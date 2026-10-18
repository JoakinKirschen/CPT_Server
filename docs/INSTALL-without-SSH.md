# Installing and updating without SSH

This guide uses only DirectAdmin in a browser (**Files**, **Cronjobs**,
**MySQL Management**, **phpMyAdmin**) and PowerShell on your own computer.
You never need SSH.

- **Part A** updates a server that already runs the CPT library (1.0.x) to
  **1.1.0**, which adds boreholes (*boringen*) and their lithological
  descriptions (*interpretaties*). Use it if `https://nightdust.eu/cpt-api/`
  already answers.
- **Part B** is a first installation on an empty account.

Replace `USER` with your DirectAdmin account name and `nightdust.eu` with your
domain everywhere below. The folder layout is the one in
[INSTALL-DirectAdmin.md](INSTALL-DirectAdmin.md#folder-layout):

```
/home/USER/
├── cpt-library/                     the code, NOT under public_html
│   └── config/config.php            your settings, permission 600
├── cpt-library-backups/             nightly backups
└── domains/nightdust.eu/public_html/cpt-api/
    ├── index.php                    from cpt-library/public/
    └── .htaccess                    from cpt-library/public/
```

## How a command runs without SSH

The server's scripts in `bin/` are run by a **one-off cron job**:

1. **Advanced Features → Cronjobs → Create cron job.** Put `*` in all five
   time fields (minute, hour, day, month, weekday) and the command in the
   *Command* field, for example
   `/usr/local/bin/php /home/USER/cpt-library/bin/migrate.php --to-file`.
2. Wait two minutes, then **delete the cron job** (tick it, *Delete*).
   Otherwise it runs every minute.
3. In **Files**, open `cpt-output.txt` in your home folder (the folder that
   shows `domains`). It holds what the script printed, with a time stamp.
   Each run appends to it.
4. Delete `cpt-output.txt` once you have read it. It can contain an API key.

Use only a PHP path, the script and its arguments in the command: no `;`,
`>`, `&&` or brackets, which some hosts refuse. `--to-file` is what sends the
output to `cpt-output.txt`. The PHP path is the one that already works in
your nightly backup job. If you have none yet, see step B5.

## Part A: update 1.0 to 1.1.0 (boreholes)

What changes: new code, two new database tables per dataset (created by
`migrations/002_boreholes.sql`), and a corrected `.htaccess`. Your
`config/config.php`, your API keys and every stored CPT stay as they are.
`index.php` and the cron jobs do not change.

### A1. Make a backup

1. In **Files**, open `cpt-library-backups` and download the newest
   `cptlib-….sql.gz` to your computer. If the folder is empty, use
   **phpMyAdmin**: select `USER_cptlib`, tab *Export*, *Quick*, format *SQL*,
   *Go*.
2. Download `cpt-library/config/config.php` too. It holds the database
   password and the key pepper; without the pepper every API key stops
   working. Keep this copy somewhere safe and private.

### A2. Download the new code

1. Log in to GitHub and open
   <https://github.com/JoakinKirschen/CPT_Server>.
2. **Code → Download ZIP.** This is the default branch, which contains
   1.1.0. The ZIP holds one folder named `CPT_Server-<branch>`.

### A3. Put the new code in place

In DirectAdmin **Files**, in your home folder:

1. Rename `cpt-library` to `cpt-library-old` (tick it, *Rename*). The API is
   briefly unavailable from here until step A4.
2. *Upload files* → choose the ZIP. Then tick the ZIP and click
   **Extract**. Delete the ZIP.
3. Rename the extracted folder `CPT_Server-…` to `cpt-library`. Check that
   `cpt-library/src/bootstrap.php` exists; if the files sit one level deeper,
   move that inner folder up and name it `cpt-library`.

### A4. Bring back your configuration

1. Open `cpt-library-old/config`, tick `config.php`, **Copy** it to
   `/home/USER/cpt-library/config/` (or upload the copy from step A1 into
   `cpt-library/config`).
2. Tick `cpt-library/config/config.php`, set permission `600`, *Set*.

Do not start from `config.example.php` again: a new `api.key_pepper` would
make every existing key invalid. No new settings are needed for boreholes.

### A5. Replace `.htaccess` in the web folder

1. Open `domains/nightdust.eu/public_html/cpt-api`.
2. Upload `cpt-library/public/.htaccess` into it, replacing the old one (or
   open the old one with *Edit* and paste the new contents). Windows hides
   files starting with a dot, so take the file from DirectAdmin rather than
   from Explorer.
3. Leave `index.php` as it is; it has not changed. Nothing else belongs in
   this folder: if `probe.php` is still in `public_html`, delete it.

### A6. Create the borehole tables

Run this once as a one-off cron job (see
[How a command runs](#how-a-command-runs-without-ssh)):

```
/usr/local/bin/php /home/USER/cpt-library/bin/migrate.php --to-file
```

`cpt-output.txt` should now say `Applied: 002_boreholes`. If it says
`Database is up to date.`, the tables already existed. If it says
`Migration failed: …`, stop there and send the message. MySQL cannot undo
table changes, so a failed migration may have created some of the new
tables already; running it again would then fail on the first of them. The
CPTs keep working meanwhile.

**Instead of the cron job**, you can use **phpMyAdmin**: select
`USER_cptlib`, tab *SQL*, paste the whole contents of
`cpt-library/migrations/002_boreholes.sql` and click *Go*. Do this only once,
and only if the table list does not show `borehole_records` yet. The file
records itself in `schema_migrations`, so the cron migration skips it later.

### A7. Check it

In **PowerShell** on your own computer (Windows 10 and later include
`curl.exe`; type `curl.exe`, not `curl`):

```powershell
$key = "cptlib_..."      # your own key
curl.exe -s https://nightdust.eu/cpt-api/ -H "X-Api-Key: $key"
```

The answer must contain `"version":"1.1.0"` and
`"datasets":["cpt","borehole","interpretation"]`. Then:

```powershell
curl.exe -s https://nightdust.eu/cpt-api/cpt/2002-009953 -H "X-Api-Key: $key"
curl.exe -s https://nightdust.eu/cpt-api/borehole/BOREHOLE_ID -H "X-Api-Key: $key"
curl.exe -s https://nightdust.eu/cpt-api/interpretation/INTERPRETATION_ID -H "X-Api-Key: $key"
curl.exe -s "https://nightdust.eu/cpt-api/borehole?near=51.05,3.72&radius=2000" -H "X-Api-Key: $key"
```

- The CPT must answer `"cache":"hit"`: it was already in the library.
- Take `BOREHOLE_ID` and `INTERPRETATION_ID` from Structural Canvas's
  Geology tab. *Open in DOV* shows `/data/boring/<id>`; the lithological
  description's id is in its *Download* link,
  `/data/interpretatie/<id>.xml`. Both look like `2016-122561`.
- The first request for a borehole answers `"cache":"miss"` and
  `"type":"borehole"`; asking again answers `"cache":"hit"`.
- The `near` search lists the boreholes stored so far (only those already
  fetched once; it never asks DOV).

Optionally run `bin/reindex.php --to-file` once as a cron job. It reports,
per dataset, how many stored records have no position; that should be `0`.

If a borehole answers `upstream_redirect` or `upstream_invalid`, DOV
answered in a shape the server has not seen yet, and nothing was stored.
The CPTs keep working. Send the answer you got together with the output of

```powershell
curl.exe -s -i https://www.dov.vlaanderen.be/data/boring/BOREHOLE_ID.xml
```

(run from your own computer; DOV answers everyone the same), so
`src/Dov/DovId.php` or `src/Dov/BoreholeXml.php` can be adjusted.

### A8. Clean up

1. Delete `cpt-output.txt` from your home folder.
2. Once everything in A7 works, delete `cpt-library-old`. It holds a copy of
   the database password.
3. The nightly cron jobs (`backup.php`, `cleanup.php`) need no change: the
   folder name is the same.

**Going back:** rename `cpt-library` to `cpt-library-new` and
`cpt-library-old` back to `cpt-library`. The 1.0 code ignores the new tables,
so the database can stay as it is.

## Part B: first installation

### B1. PHP version and HTTPS

1. **Account Manager → Domain Setup → nightdust.eu**: PHP version 8.3 (on
   some servers under *PHP Version Selector*).
2. **Account Manager → SSL Certificates**: *Free & automatic certificate
   from Let's Encrypt* for `nightdust.eu` and `www.nightdust.eu`, *Save*.
3. In **Domain Setup**, tick *Force SSL with https redirect*.

### B2. Database

**Account Manager → MySQL Management → Create new Database**: database
`cptlib`, user `cptlib`, a long random password. DirectAdmin names both
`USER_cptlib`. Note the password.

### B3. Code

Do steps A2 and A3.2–A3.3: download the ZIP, upload and extract it in your
home folder, and rename the folder to `cpt-library`.

### B4. Configuration

1. Make the key pepper, a secret random string, in PowerShell:

   ```powershell
   $b = New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); ($b | ForEach-Object { $_.ToString("x2") }) -join ""
   ```

   It prints 64 characters. Keep them secret; changing them later makes every
   API key invalid.
2. In **Files**, open `cpt-library/config`, *Create New File* named
   `config.php`, and paste the contents of `config.example.php`. Change:
   - `db.name`, `db.user`: `USER_cptlib`; `db.pass`: the password from B2.
   - `api.key_pepper`: the 64 characters from step 1.
   - `paths.backup_dir`: `/home/USER/cpt-library-backups`.
   - `dov.user_agent`: something that identifies you to DOV, such as a
     contact address.
3. Save, tick `config.php`, permission `600`, *Set*.
4. Create the folder `cpt-library-backups` in your home folder, permission
   `700`.

### B5. Web folder

Create `domains/nightdust.eu/public_html/cpt-api` and upload
`cpt-library/public/index.php` and `cpt-library/public/.htaccess` into it.
Nothing else from the code goes under `public_html`.

### B6. Tables and your API key

Run as a one-off cron job (see
[How a command runs](#how-a-command-runs-without-ssh)):

```
/usr/local/bin/php /home/USER/cpt-library/bin/setup.php joakin
```

`cpt-output.txt` should show the PHP version, `Applied: 001_initial,
002_boreholes` and your key, `cptlib_…`. Store the key (for example in your
password manager), then delete the file.

- If the file says the PHP is older than 8.1, or no file appears at all,
  the PHP path is wrong. Try `/opt/alt/php83/usr/bin/php` (CloudLinux
  servers), then `/usr/local/php83/bin/php`, and use the path that works
  from now on.
- Running it again is harmless: it then says `Database is up to date` and
  that the user already exists.

### B7. Check it and enter the key in Structural Canvas

1. Do the checks of step A7. The first CPT request answers `"cache":"miss"`,
   the second `"cache":"hit"`. Without a key, `curl.exe -s
   https://nightdust.eu/cpt-api/` answers `unauthorized`.
2. In Structural Canvas, open *Settings*. The *Address* already reads
   `https://nightdust.eu/cpt-api`. Paste the key under *API key* and click
   *Test connection*; it says *Connected as joakin.*

### B8. Nightly jobs

Add two permanent jobs under **Advanced Features → Cronjobs**, with the PHP
path that worked in B6:

| Minute | Hour | Day | Month | Weekday | Command |
|---|---|---|---|---|---|
| 17 | 3 | * | * | * | `/usr/local/bin/php /home/USER/cpt-library/bin/backup.php` |
| 47 | 3 | * | * | * | `/usr/local/bin/php /home/USER/cpt-library/bin/cleanup.php` |

They print nothing when they succeed, so DirectAdmin only emails you when
something fails. Download a backup now and then: a backup on the same
server does not protect against losing the account.

## Later tasks, all as one-off cron jobs

| Task | Command (then read `cpt-output.txt`) |
|---|---|
| Key for a colleague | `/usr/local/bin/php /home/USER/cpt-library/bin/keys.php create colleague --to-file` |
| Read-only key | `/usr/local/bin/php /home/USER/cpt-library/bin/keys.php create colleague --read-only --to-file` |
| List users | `/usr/local/bin/php /home/USER/cpt-library/bin/keys.php list --to-file` |
| New key, old one stops working | `/usr/local/bin/php /home/USER/cpt-library/bin/keys.php rotate joakin --to-file` |
| Withdraw a key | `/usr/local/bin/php /home/USER/cpt-library/bin/keys.php revoke colleague --to-file` |
| After an update | `/usr/local/bin/php /home/USER/cpt-library/bin/migrate.php --to-file` |

**Restoring a backup:** phpMyAdmin → select `USER_cptlib` → *Import* →
choose the `cptlib-….sql.gz` file → *Go*.

**Every later update** is Part A again: backup, new ZIP, keep
`config/config.php`, replace `.htaccess` and `index.php` only if they changed,
run `migrate.php --to-file` once.

For error messages, see
[Troubleshooting](INSTALL-DirectAdmin.md#troubleshooting).
