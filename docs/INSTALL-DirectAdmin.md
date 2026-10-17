# Installing on DirectAdmin shared hosting

This guide installs the library at `https://nightdust.eu/cpt-api/`. Replace
`USER` with your DirectAdmin account name and `nightdust.eu` with your
domain. Menu names differ a little between DirectAdmin skins; the classic
names are given here.

## Folder layout

```
/home/USER/
├── cpt-library/                       this repository (NOT web-accessible)
│   ├── config/config.php              your settings, chmod 600
│   ├── src/  bin/  migrations/  tests/
│   └── var/log/                       request and error logs (created automatically)
├── cpt-library-backups/               nightly database backups, chmod 700
└── domains/nightdust.eu/public_html/
    └── cpt-api/
        ├── index.php                  copied from cpt-library/public/
        └── .htaccess                  copied from cpt-library/public/
```

`public/index.php` looks for the code at `dirname(__DIR__, 4) . '/cpt-library'`,
which resolves to `/home/USER/cpt-library` with this layout. If you put the
code somewhere else, edit the `$root` line in the copied `index.php`.

## 0. Probe the host (once)

1. Open `tools/probe.php` and set `PROBE_TOKEN` to a long random string. If
   you want the MySQL version too, also fill in `DB_*` (step 2 creates the
   database).
2. Upload it to `public_html/`.
3. Open `https://nightdust.eu/probe.php?token=…` in a browser, and also run
   it over SSH with `php probe.php`, because cron uses the CLI settings.
4. Send the output, then **delete `probe.php`** from the server.

## 1. PHP version and SSL

1. **Account Manager → Domain Setup → nightdust.eu**: set the PHP version to
   8.3. Some servers have this under *PHP Version Selector*.
2. **Account Manager → SSL Certificates**: choose *Free & automatic
   certificate from Let's Encrypt*. Include `nightdust.eu` and
   `www.nightdust.eu` and save. DirectAdmin renews the certificate itself.
3. Back in **Domain Setup**, tick *Force SSL with https redirect*. The API
   refuses plain HTTP anyway (`require_https`).

Over SSH, check which PHP the command line uses: run `php -v`. If it is not
8.3, use the full path everywhere below and in cron, for example
`/usr/local/php83/bin/php`. Run `ls /usr/local/php*/bin/php` to see what is
installed.

## 2. Database

1. **Account Manager → MySQL Management → Create new Database**:
   - Database: `cptlib` (DirectAdmin makes this `USER_cptlib`).
   - User: `cptlib` (becomes `USER_cptlib`).
   - A long random password.
2. Optional, to run the tests on the server: create a second database
   `cptlib_test` with its own user. Its name must contain `test`.

## 3. Code

Over SSH:

```sh
cd ~
git clone https://github.com/JoakinKirschen/CPT_Server.git cpt-library
cd cpt-library
```

If the repository is private, add a read-only *deploy key* to it on GitHub
(the key from `ssh-keygen -t ed25519 -f ~/.ssh/cpt_library_deploy`). Then
clone it with
`GIT_SSH_COMMAND='ssh -i ~/.ssh/cpt_library_deploy' git clone git@github.com:JoakinKirschen/CPT_Server.git cpt-library`.
Without SSH you can upload the files with DirectAdmin's File Manager or SFTP
into `/home/USER/cpt-library`, which is outside `public_html`.

## 4. Configuration

```sh
cp config/config.example.php config/config.php
chmod 600 config/config.php
php -r 'echo bin2hex(random_bytes(32)), "\n";'    # -> api.key_pepper
nano config/config.php
```

Set these values:

- `db.name`, `db.user`, `db.pass`: from step 2.
- `api.key_pepper`: the random string from the command above. Keep it
  secret. Changing it later invalidates every key.
- `api.base_path`: `/cpt-api`.
- `paths.backup_dir`: `/home/USER/cpt-library-backups`.
- `dov.user_agent`: something that identifies you to DOV.

DirectAdmin normally runs PHP as your own user (PHP-FPM or suPHP), so mode
600 still lets PHP read the file. If the API answers `server_misconfigured`
and the error log says the config cannot be read, PHP runs as another user.
In that case use `chmod 640` and ask the host which group to give the file.

## 5. Create the tables

```sh
php bin/migrate.php
```

Without SSH, open **phpMyAdmin**, select `USER_cptlib`, go to the *SQL* tab,
and paste the contents of `migrations/001_initial.sql`. The file records its
own version, so `bin/migrate.php` will not apply it twice later.

## 6. Web folder

```sh
mkdir -p ~/domains/nightdust.eu/public_html/cpt-api
cp public/index.php public/.htaccess ~/domains/nightdust.eu/public_html/cpt-api/
```

The `.htaccess` file does three things:

- It sends every request to `index.php` (this needs `mod_rewrite`, which
  DirectAdmin has on Apache and LiteSpeed).
- It turns off directory listings.
- It denies every other file in the folder.

Nothing else from the repository belongs under `public_html`.

## 7. API keys

```sh
php bin/keys.php create joakin            # prints the key once: store it safely
php bin/keys.php create colleague --read-only
php bin/keys.php list
php bin/keys.php rotate joakin            # new key, old one stops working
php bin/keys.php revoke colleague
```

## 8. Check it

```sh
KEY=cptlib_...
curl -s https://nightdust.eu/cpt-api/ -H "X-Api-Key: $KEY"
curl -s https://nightdust.eu/cpt-api/cpt/2002-009953 -H "X-Api-Key: $KEY" | head -c 600
curl -s "https://nightdust.eu/cpt-api/cpt?near=51.05,3.72&radius=2000" -H "X-Api-Key: $KEY"
curl -s -i -X OPTIONS https://nightdust.eu/cpt-api/cpt/2002-009953 -H "Origin: null" -H "Access-Control-Request-Headers: x-api-key"
curl -s https://nightdust.eu/cpt-api/                                   # 401 without a key
```

The first CPT request should answer `"cache":"miss"` and the second
`"cache":"hit"`. Then run `php bin/reindex.php`. It reports how many stored
CPTs have no position. If that count is not 0, the coordinates in the DOV
XML sit somewhere other than expected; send one XML so `src/Dov/CptXml.php`
can be adjusted.

## 9. Cron jobs

Add these under **Advanced Features → Cronjobs**, with your own PHP path. The
minutes are spread on purpose. The scripts print nothing on success, so
DirectAdmin only emails you (the address set on the Cronjobs page) when
something fails.

| Minute | Hour | Day | Month | Weekday | Command |
|---|---|---|---|---|---|
| 17 | 3 | * | * | * | `/usr/local/php83/bin/php /home/USER/cpt-library/bin/backup.php` |
| 47 | 3 | * | * | * | `/usr/local/php83/bin/php /home/USER/cpt-library/bin/cleanup.php` |

- **Backups.** By default they are kept for 14 days (`backup.keep_days`),
  and the newest one is always kept. They contain the user table, but only
  key hashes, never keys.
- **Restoring.** Run
  `gunzip < ~/cpt-library-backups/cptlib-YYYYMMDD-HHMMSS.sql.gz | mysql -u USER_cptlib -p USER_cptlib`.
  Without SSH, use phpMyAdmin → *Import*.
- **Copies.** Download a backup now and then. A backup on the same server
  does not protect against losing the account.

## 10. Tests on the server (optional)

```sh
cp tests/config.test.example.php tests/config.test.php   # fill in the *_test database
php tests/run.php
```

## Updating

```sh
cd ~/cpt-library
git pull
php bin/migrate.php
cp public/index.php public/.htaccess ~/domains/nightdust.eu/public_html/cpt-api/   # only if they changed
php tests/run.php        # optional, against the test database
```

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `server_misconfigured` | Either `config/config.php` is missing or unreadable, the pepper is shorter than 32 characters, or `$root` in `index.php` points to the wrong folder. |
| `database_unavailable` | Wrong `db.*` settings. The details are in `var/log/errors-*.log`. |
| `https_required` behind a proxy | Set `api.trust_forwarded_proto` to `true`, but only if the host's proxy sets `X-Forwarded-Proto`. |
| Every path gives 404 from the web server | `mod_rewrite` or `.htaccess` is not active for the folder. |
| `upstream_unavailable` for every CPT | Outbound HTTPS to DOV is blocked or DOV is down. Check the probe output. |
| `upstream_redirect` | DOV changed its URLs. The server deliberately does not follow redirects. |
