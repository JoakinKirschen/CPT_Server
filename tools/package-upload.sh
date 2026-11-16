#!/usr/bin/env bash
# Makes the folders the deploy job uploads (tools/deploy-ftp.sh), under the folder given:
#
#   <out>/migrations  the migrations alone, uploaded first: the library applies what is pending at the first
#                     request of a new version, so every migration must be on the host before the code is
#   <out>/code        what lives outside the web root: src, bin, migrations and the example config
#   <out>/web         what lives under public_html: index.php and .htaccess
#
# config/config.php is never in them: it holds the host's own settings and is not in the repository.
# Tests, docs and the development tools stay out.
set -euo pipefail
out="${1:?Usage: tools/package-upload.sh <folder>}"
root="$(cd "$(dirname "$0")/.." && pwd)"
rm -rf "${out:?}/migrations" "${out:?}/code" "${out:?}/web"
mkdir -p "$out/migrations/migrations" "$out/code/config" "$out/web"
cp "$root"/migrations/*.sql "$out/migrations/migrations/"
cp -R "$root/src" "$root/bin" "$root/migrations" "$out/code/"
cp "$root/config/config.example.php" "$out/code/config/"
cp "$root/public/index.php" "$root/public/.htaccess" "$out/web/"
if [ -e "$out/code/config/config.php" ]; then
  echo "config/config.php must never be uploaded." >&2
  exit 1
fi
echo "Made $out/migrations ($(find "$out/migrations" -type f | wc -l) files), $out/code ($(find "$out/code" -type f | wc -l)) and $out/web ($(find "$out/web" -type f | wc -l))."
