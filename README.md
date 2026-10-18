# Structural Canvas CPT library server

A small PHP server that keeps a shared library of CPTs (cone penetration
tests, *sonderingen*) and boreholes (*boringen*, with their lithological
descriptions) for [Structural Canvas](https://github.com/JoakinKirschen/StructuralCanvas).

Structural Canvas is an offline single-file HTML app. It is opened as
`file://`, so its requests carry `Origin: null`. DOV
(Databank Ondergrond Vlaanderen) sends no CORS headers, so the app cannot read
`https://www.dov.vlaanderen.be/data/sondering/<id>.xml` (or
`/data/boring/<id>.xml`, `/data/interpretatie/<id>.xml`) itself. This server
fetches that XML server-side, stores it (with every revision), and returns it
to the app together with its provenance: retrieval time, SHA-256 and
attribution.

It runs on ordinary shared hosting: PHP 8.3, MySQL/MariaDB, Apache or
LiteSpeed with `.htaccess`, and cron. It needs no Composer packages, no
long-running processes and no root access. See
[docs/INSTALL-DirectAdmin.md](docs/INSTALL-DirectAdmin.md), or
[docs/INSTALL-without-SSH.md](docs/INSTALL-without-SSH.md) to install or
update with only DirectAdmin's File Manager and cron.

## API

Base URL, for example `https://nightdust.eu/cpt-api`. Every request needs the
header `X-Api-Key: cptlib_…`. The only exception is the CORS preflight
(`OPTIONS`), which browsers send without custom headers. Responses are JSON,
`Cache-Control: no-store`.

| Method and path | What it does |
|---|---|
| `GET /` | Connection test: returns your user name and the licence. |
| `GET /cpt/{dovId}` | Returns the stored DOV CPT. If it is missing, the server fetches it from DOV, stores it and returns it. |
| `GET /cpt/{dovId}?refresh=1` | Asks DOV again, unless the stored copy was checked less than 24 h ago. If the XML changed, a new revision is stored and the old one is kept. |
| `GET /cpt/{dovId}?revision={sha256}` | Returns an exact earlier revision. |
| `GET /cpt/{dovId}?format=xml` | Returns the raw XML; the metadata comes in `X-Cpt-*` headers. |
| `GET /cpt/{dovId}/revisions` | Lists every stored revision. |
| `GET /cpt?near={lat},{lon}&radius={m}` | Lists the CPTs in the library within the radius (default 1000 m, max 10 000 m), sorted by distance. It never asks DOV. |
| `POST /cpt` | Stores one of your own CPTs as GEF or CSV text. It is flagged as private. |
| `GET /cpt/{privateId}` | Returns one of your private CPTs. |
| `DELETE /cpt/{privateId}` | Deletes one of your private CPTs. |
| `GET /borehole/{dovId}` | Returns the stored DOV borehole (*boring*), fetched from DOV when missing. Takes `refresh`, `revision` and `format` like a CPT, and has `/revisions`. |
| `GET /interpretation/{dovId}` | The same for a DOV interpretation (*interpretatie*), such as a borehole's lithological description. |
| `GET /borehole?near={lat},{lon}&radius={m}` | Lists the boreholes in the library within the radius, like the CPT search. It never asks DOV. |

`{dovId}` must match `^\d{4}-\d{6}$`. This is `DOV_IDENTIFIER_ONLY` from the
app's `js/project-information.js`, for example `2002-009953`.
`{privateId}` looks like `p-0123456789abcdef`. Anything else gets a 400 error.
Boreholes and interpretations use the same id pattern; the same id in two
datasets names two different DOV objects, each stored on its own.

### `GET /cpt/{dovId}`

```json
{
  "kind": "dov",
  "private": false,
  "dovId": "2002-009953",
  "name": "GEO-02/041-S3",
  "sourceUrl": "https://www.dov.vlaanderen.be/data/sondering/2002-009953.xml",
  "recordUrl": "https://www.dov.vlaanderen.be/data/sondering/2002-009953",
  "retrievedAt": "2026-10-17T12:00:00Z",
  "lastCheckedAt": "2026-10-18T13:00:00Z",
  "sha256": "…64 hex…",
  "bytes": 183245,
  "revision": 1,
  "revisionCount": 1,
  "current": true,
  "cache": "hit",
  "change": null,
  "warning": null,
  "position": { "latitude": 51.05, "longitude": 3.72, "lambert72": { "x": 104500.0, "y": 194300.0 } },
  "licence": {
    "source": "Databank Ondergrond Vlaanderen (DOV)",
    "name": "Modellicentie Gratis Hergebruik v1.0",
    "url": "https://data.vlaanderen.be/doc/licentie/modellicentie-gratis-hergebruik/v1.0",
    "attribution": "Databank Ondergrond Vlaanderen, Vlaamse overheid - Departement Mobiliteit en Openbare Werken, Afdeling Geotechniek, sondering 2002-009953, geraadpleegd op 2026-10-18, via https://www.dov.vlaanderen.be/data/sondering/2002-009953"
  },
  "xmlEncoding": "utf-8",
  "xml": "<?xml version=\"1.0\" …"
}
```

- DOV answers an unknown id with HTTP 200 and an empty `<dov-schema>`.
  The server turns that into a 404 `not_found` and stores nothing. A CPT
  whose XML names another id (`dataidentifier/permkey` or `uri`) is refused
  with `upstream_invalid`.
- `xml` holds the bytes DOV returned, unchanged. `sha256` is computed over
  exactly those bytes. If the XML is not valid UTF-8, it is sent as base64
  and `xmlEncoding` is `"base64"`.
- `retrievedAt` is when this exact XML was first fetched from DOV.
  `lastCheckedAt` is the last time DOV confirmed it.
- `cache` takes one of these values:
  - `hit`: served from the library.
  - `miss`: fetched from DOV now.
  - `refreshed`: DOV was asked again. `change` is then `unchanged`,
    `changed` or `reverted`.
  - `refresh-skipped`: the copy was checked recently, so DOV was not asked.
  - `stale`: DOV failed or the fetch budget was used up, so the library copy
    was served. `warning` explains which.
- `position` comes from the GML points in the XML:
  - Lambert 72 is read from `ligging/Point/pos`.
  - Latitude and longitude come from DOV's own WGS 84 point in
    `alternatieve_crs`.
  - If one of the two is missing, it is converted from the other.
  - `position` is `null` if the XML has no usable position.

### `GET /borehole/{dovId}` and `GET /interpretation/{dovId}`

The same answer as a CPT's, with `"type": "borehole"` or
`"type": "interpretation"` (a CPT's answer says `"type": "cpt"`), the
dataset's own `sourceUrl` and `recordUrl`
(`https://www.dov.vlaanderen.be/data/boring/<id>.xml`,
`…/data/interpretatie/<id>.xml`) and the dataset in the attribution
(`boring <id>`, `interpretatie <id>`). `name` is the borehole's
`boornummer`; an interpretation has none.

- They are kept in their own tables (`borehole_*`, `interpretation_*`) with
  every revision, and share the API key, the request limits and the DOV
  fetch budgets with the CPTs. The "not found" cache is per dataset.
- A borehole is the `<boring>` with content in DOV's `<dov-schema>`; an
  interpretation is the element in `<interpretaties>` (or, without that
  wrapper, the first element that is not a CPT or a borehole). An empty
  `<dov-schema>` is a 404 `not_found`; another dataset, or a
  `dataidentifier` (`permkey`, or a `uri` of the same dataset) naming
  another id, is refused with `upstream_invalid`. An interpretation's link
  to its borehole is not its own id.
- These shapes and DOV's redirect to
  `/xdov/proxy/export/boring/<id>` (`…/interpretatie/<id>`) follow DOV's
  schema and the CPT probe; they have not been seen from the host yet. If
  DOV answers otherwise, the server refuses (`upstream_redirect`,
  `upstream_invalid`) and stores nothing.
- `GET /` lists the datasets the server keeps: `"datasets": ["cpt",
  "borehole", "interpretation"]`.

### `POST /cpt`

```http
POST /cpt
X-Api-Key: cptlib_…
Content-Type: application/json

{ "name": "Site A – S1", "format": "gef", "content": "#GEFID= 1, 1, 0\r\n…#EOH=\r\n…",
  "latitude": 51.05, "longitude": 3.72 }
```

- `format` is `gef` or `csv`. If you leave it out, the server detects it.
  GEF needs `#GEFID` and `#EOH`; CSV needs at least two rows of numbers.
- `latitude` and `longitude` are optional, but give both or neither.
- The limit is 3 MB of text (kept under PHP's `post_max_size` of 8 MB,
  because JSON escaping can nearly double the size). A body that carries `dovId`, `url` or `sourceUrl` is
  refused.
- The answer is `201` with `{"kind": "private", "private": true, "id": "p-…", …}`.
  Uploading the same text again returns the existing record with `200` and
  `"duplicate": true`.

Private CPTs live in their own table and have their own id space. Only their
owner sees them: for any other key they read as "not found". They never appear
among the DOV results. `GET /cpt?near=` returns them in a separate `private`
array.

### Errors

Every error is JSON, `{"error": "<code>", "message": "<text>"}`. It never
contains stack traces, SQL or file paths. A 500 response also carries a
`requestId`, which you can find in the server's error log. The codes are:

| Status | Codes |
|---|---|
| 400 | `invalid_id`, `invalid_path`, `invalid_near`, `invalid_radius`, `invalid_revision`, `invalid_body` |
| 401 | `unauthorized` |
| 403 | `https_required`, `forbidden` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 413 | `payload_too_large` |
| 415 | `unsupported_media_type` |
| 429 | `rate_limited`, `dov_rate_limited` (both with `Retry-After`) |
| 502 | `upstream_unavailable`, `upstream_invalid`, `upstream_redirect`, `upstream_too_large` |
| 503 | `busy`, `database_unavailable` |

## Security

- **DOV only.** The server builds the fetch URL itself from a validated id
  and the dataset of the endpoint:
  `https://www.dov.vlaanderen.be/data/sondering/<id>.xml` (`boring`,
  `interpretatie` for the other two). No endpoint takes a URL from the
  client. DOV answers that URL with a 302 to its export URL,
  `https://www.dov.vlaanderen.be/xdov/proxy/export/sondering/<id>`, for
  the same dataset and id. The server follows that one hop, and only when the
  `Location` header is exactly that URL, built from the same validated id.
  cURL itself never follows redirects and is limited to HTTPS. Any other
  redirect, a second redirect, or a response from another URL is refused.
  Every URL is checked against these two shapes of its own dataset before
  it is requested.
  Responses are capped at 8 MB and must be well-formed XML. The parser
  loads no external entities and makes no network access. This is not a
  proxy.
- **API keys.** Every request needs `X-Api-Key`. Keys are random, with
  238 bits of entropy. The database stores only an HMAC-SHA256 hash of each
  key, made with a secret pepper from the config file. Each user has one key.
  You create, rotate, revoke and list keys with `bin/keys.php`. Keys are
  never accepted in the URL.
- **CORS is not authentication.** The server allows `Origin: null` (the
  file:// app) and the `X-Api-Key` header, with no cookies or credentials.
  A request from any origin still needs a valid key; CORS only decides
  whether a browser lets the page read the answer.
- **Rate limits and cache.** Each key is limited per minute and per day, and
  POSTs per hour. Failed logins are limited per IP. DOV has two fetch budgets
  of its own, one per user per hour and one for the whole server per minute.
  On top of that:
  - Stored CPTs and boreholes are served without asking DOV.
  - A refresh within 24 h of the last check is skipped.
  - A DOV "not found", or an answer that is not CPT XML, is remembered
    for 1 h.
  - Only one fetch per object runs at a time (MySQL `GET_LOCK`).
- **Database.** Every query is a prepared statement, with server-side
  prepares and strict SQL mode.
- **Secrets.** `config/config.php` holds the database password and the key
  pepper. It lives outside `public_html` with mode 600 and is never
  committed (see `.gitignore`). The web folder contains only `index.php` and
  `.htaccess`.
- **Logs.** The request log records time, IP, user name, method, path, the
  known query parameters, status, cache result and duration. It never
  records the API key, request headers or request bodies. Logs are kept in
  `var/log/` outside the web root and deleted after 90 days.
- **Backups.** A nightly cron job runs `bin/backup.php`. It writes a gzip
  file with mode 600 to a folder outside `public_html`, and refuses to write
  into a web folder. It uses `mysqldump`, with the password in a temporary
  options file rather than on the command line. If `mysqldump` is not
  available, it falls back to a PHP dumper.

## DOV data licence and attribution

DOV publishes its data, including the CPT dataset *Sonderingen*, under the
Flemish **Modellicentie Gratis Hergebruik v1.0** ("model licence for free
reuse",
<https://data.vlaanderen.be/doc/licentie/modellicentie-gratis-hergebruik/v1.0>).
Commercial and non-commercial reuse are both allowed, free of charge. The
only condition is attribution.

DOV's dataset metadata asks you to cite it as *"Databank Ondergrond
Vlaanderen, [beheerder], [geraadpleegde gegevens], geraadpleegd op [datum],
via www.dov.vlaanderen.be"*. The data manager for CPTs is the Afdeling
Geotechniek (Departement Mobiliteit en Openbare Werken). DOV also warns
that the database contains data from many sources and periods, so errors
or gaps are possible.

Every DOV response from this server carries `licence.attribution` in that
form, for example:

> Databank Ondergrond Vlaanderen, Vlaamse overheid - Departement Mobiliteit en
> Openbare Werken, Afdeling Geotechniek, sondering 2002-009953, geraadpleegd op
> 2026-10-18, via https://www.dov.vlaanderen.be/data/sondering/2002-009953

The DOV websites could not be opened from the environment where this server
was built. The licence and citation above therefore come from DOV's
published metadata for the *Sonderingen* dataset (DOV catalogue record
`b397faec-1b64-4854-8000-2375edb3b1a8`) and DOV's disclaimer. Before going
live, check them against that record:
<https://www.dov.vlaanderen.be/geonetwork/srv/api/records/b397faec-1b64-4854-8000-2375edb3b1a8>.
The texts are settings (`licence` in the config), so you can correct them
without changing code.

Boreholes and interpretations come from DOV under the same licence. DOV
holds them from many data managers, so their attribution
(`licence.borehole_attribution`, `licence.interpretation_attribution`)
names DOV and the Flemish government without one data manager, for
example *"Databank Ondergrond Vlaanderen, Vlaamse overheid, boring
2016-122561, geraadpleegd op 2026-10-18, via
https://www.dov.vlaanderen.be/data/boring/2016-122561"*. Check them against
DOV's dataset records for *Boringen* and *Interpretaties* before relying on
them.

## Repository layout

```
public/            index.php + .htaccess: the only files under public_html
src/               the application (namespace CptLibrary)
bin/               setup (no-SSH first run), migrate, keys, backup (cron), cleanup (cron), reindex
migrations/        SQL schema, applied in order by bin/migrate.php
config/            config.example.php (the real config.php is not committed)
tests/             php tests/run.php
tools/probe.php    one-off host check (step 0), delete after use
docs/              install guides (with SSH, and without SSH)
```

## Tests

```sh
php tests/run.php                 # all tests
php tests/run.php revision        # tests whose name contains "revision"
```

The tests cover id validation, the DOV-only URL rule, redirects,
authentication, key hashing and revocation, rate limits, revisions, the
"not found" cache, CORS headers, private CPTs, the `near` search, JSON
errors, logs without keys, a backup and restore round trip, and boreholes
and interpretations (their URLs, classification, storage apart from the
CPTs, revisions, shared budgets and their own `near` search). Most of them
need an **empty test database whose name contains `test`**, because the
tests drop all its tables. Configure it in `tests/config.test.php` (copy
`tests/config.test.example.php`) or with the `CPT_TEST_DB_*` environment
variables. If no test database is configured, the database tests report
`SKIP` and the run fails. Pass `--allow-skip` to run only the unit tests.
The tests use a fake DOV and a fixed clock, and never touch the network.
