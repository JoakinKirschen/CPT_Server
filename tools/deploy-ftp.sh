#!/usr/bin/env bash
# Uploads a folder to the web host over FTP with TLS (lftp). Used by the deploy job of
# .github/workflows/ci.yml with the folders tools/package-upload.sh makes; it can be run by hand the same way.
#
#   FTP_SERVER=ftp.example.com FTP_USERNAME=… FTP_PASSWORD=… FTP_DIR=/cpt-library \
#     tools/deploy-ftp.sh dist/code
#
# - Every file of the folder is sent, whatever the host has. Files are added and replaced; nothing on the
#   host is ever deleted. So config/config.php (the host's own settings, which the folder never holds),
#   the logs under var/ and files the library no longer has all stay where they are.
# - The connection must be encrypted and the server's certificate valid for FTP_SERVER; otherwise nothing
#   is sent, the password included. Name the host by the name its certificate is for.
# - Many hosts send their certificate without the intermediate certificates between it and an authority
#   this machine trusts. Those are fetched from where each certificate says its issuer's is, and count
#   only when the whole chain then verifies against the trusted authorities: a fetched certificate can
#   complete a chain, never make an untrusted one trusted. With FTP_SERVER the name the certificate is
#   for, the upload then goes on when the host renews its certificate.
# - A certificate that still cannot be verified (the host's own, or one for another name than FTP_SERVER)
#   is refused, with its fingerprint. FTP_CERT_FINGERPRINT trusts the one certificate with that
#   fingerprint, and no other: the connection is then as safe as the fingerprint is the host's. When the
#   host renews such a certificate the upload is refused again, with the new fingerprint.
# - The password is read from the environment, so it is not on a command line or in a log.
# Optional: FTP_PORT (21), FTP_CERT_FINGERPRINT (as lftp prints it: twenty pairs of hex digits with colons),
# FTP_CA_FILE (the certificate authorities to trust instead of the machine's, as a PEM file).
set -euo pipefail

folder="${1:?Usage: deploy-ftp.sh <folder to upload>}"
: "${FTP_SERVER:?Set FTP_SERVER to the host name of the FTP server}"
: "${FTP_USERNAME:?Set FTP_USERNAME to the FTP account}"
: "${FTP_PASSWORD:?Set FTP_PASSWORD to its password}"
: "${FTP_DIR:?Set FTP_DIR to the folder on the host the site is served from}"
fingerprint="${FTP_CERT_FINGERPRINT:-}"
if [ -n "$fingerprint" ] && ! [[ "$fingerprint" =~ ^([0-9A-Fa-f]{2}:){19}[0-9A-Fa-f]{2}$ ]]; then
  echo "FTP_CERT_FINGERPRINT is not a fingerprint: twenty pairs of hex digits with colons between them." >&2
  exit 1
fi
[ -d "$folder" ] && [ -n "$(find "$folder" -type f -print -quit)" ] || {
  echo "$folder is not a folder with files: make it first (tools/package-upload.sh)." >&2
  exit 1
}

roots="${FTP_CA_FILE:-/etc/ssl/certs/ca-certificates.crt}"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
# In a GitHub run a warning is shown on the run's page; elsewhere it is a line like any other.
warn() { echo "${GITHUB_ACTIONS:+::warning::}$*" >&2; }

# The certificates the server sends, its own first.
server_certificates() {
  timeout 20 openssl s_client -connect "$FTP_SERVER:${FTP_PORT:-21}" -starttls ftp -servername "$FTP_SERVER" -showcerts </dev/null 2>/dev/null |
    awk '/-----BEGIN CERTIFICATE-----/{on=1} on{print} /-----END CERTIFICATE-----/{on=0}'
}

# The certificate of the issuer of the certificate in file $1, fetched from the address that certificate
# gives for it (its Authority Information Access), as PEM.
issuer_of() {
  local url
  url="$(openssl x509 -in "$1" -noout -ext authorityInfoAccess 2>/dev/null |
    sed -n 's/^ *CA Issuers - URI:\(https\{0,1\}:\/\/[^ ]*\).*/\1/p' | head -n 1)"
  [ -n "$url" ] || return 1
  curl -fsSL --max-time 20 --max-filesize 200000 -o "$work/issuer" "$url" 2>/dev/null || return 1
  openssl x509 -inform DER -in "$work/issuer" 2>/dev/null || openssl x509 -inform PEM -in "$work/issuer" 2>/dev/null
}

# Completes the server's chain of certificates where the server leaves intermediate ones out. Prints the
# file of authorities lftp is to trust (the machine's, and the fetched intermediates) when that is needed
# and the chain then verifies; prints nothing otherwise, and lftp decides with what it has.
complete_chain() {
  command -v openssl >/dev/null && command -v curl >/dev/null && [ -f "$roots" ] || return 0
  server_certificates >"$work/chain.pem"
  openssl x509 -in "$work/chain.pem" -out "$work/own.pem" 2>/dev/null || return 0
  awk '/-----BEGIN CERTIFICATE-----/{cert=""} {cert=cert $0 "\n"} /-----END CERTIFICATE-----/{last=cert} END{printf "%s", last}' \
    "$work/chain.pem" >"$work/top.pem"
  : >"$work/fetched.pem"
  local fetched
  for fetched in 0 1 2 3 4; do
    if openssl verify -CAfile "$roots" -untrusted "$work/chain.pem" "$work/own.pem" >/dev/null 2>&1; then
      if ! openssl x509 -in "$work/own.pem" -noout -checkhost "$FTP_SERVER" 2>/dev/null | grep -q "does match"; then
        warn "The server's certificate is genuine but not for the name in FTP_SERVER. It is for:$(openssl x509 -in "$work/own.pem" -noout -ext subjectAltName 2>/dev/null | tail -n +2 | tr -s ' ')." \
          "Enter the server's own name as FTP_SERVER, so that uploads go on when the certificate is renewed."
      fi
      [ "$fetched" -gt 0 ] || return 0
      echo "The server sends its certificate without $fetched intermediate certificate(s); fetched from the issuer, and the chain verifies." >&2
      cat "$roots" "$work/fetched.pem" >"$work/authorities.pem"
      echo "$work/authorities.pem"
      return 0
    fi
    [ "$fetched" -lt 4 ] && issuer_of "$work/top.pem" >"$work/next.pem" && [ -s "$work/next.pem" ] || return 0
    cat "$work/next.pem" >>"$work/chain.pem"
    cat "$work/next.pem" >>"$work/fetched.pem"
    cp "$work/next.pem" "$work/top.pem"
  done
}

# What certificate the server shows, to tell why an upload was refused. A certificate is public; nothing
# secret is printed.
show_certificate() {
  command -v openssl >/dev/null || return 0
  echo "The certificate the server presents (to trust this one certificate, set FTP_CERT_FINGERPRINT to its SHA1 fingerprint):" >&2
  timeout 20 openssl s_client -connect "$FTP_SERVER:${FTP_PORT:-21}" -starttls ftp -servername "$FTP_SERVER" </dev/null 2>/dev/null |
    openssl x509 -noout -subject -issuer -dates -fingerprint -sha1 >&2 || echo "  (it could not be read)" >&2
}

authorities="$(complete_chain)"
[ -n "$authorities" ] || authorities="${FTP_CA_FILE:-}"
export LFTP_PASSWORD="$FTP_PASSWORD"
if ! lftp <<LFTP; then
set cmd:fail-exit true
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ssl:verify-certificate true
set ssl:check-hostname true
${authorities:+set ssl:ca-file "$authorities"}
${fingerprint:+set ssl:verify-certificate/${fingerprint^^} no}
set net:max-retries 3
set net:timeout 30
set net:reconnect-interval-base 5
open --user "$FTP_USERNAME" --env-password -p "${FTP_PORT:-21}" "ftp://$FTP_SERVER"
mirror --reverse --transfer-all --no-perms --no-umask --parallel=4 --verbose=1 "$folder" "$FTP_DIR"
LFTP
  show_certificate
  exit 1
fi
echo "Uploaded $(find "$folder" -type f | wc -l) files to $FTP_SERVER:$FTP_DIR."
