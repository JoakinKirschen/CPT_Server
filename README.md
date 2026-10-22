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

DOV data is public, so reading it needs no key (1.2.0). Users with a key can
also upload their own CPTs, after an automatic quality check. Each upload
stays private, is shared with the uploader's group, or goes into the shared
library for every key, and it records how exactly its position is known.

It runs on ordinary shared hosting: PHP 8.3, MySQL/MariaDB, Apache or
LiteSpeed with `.htaccess`, and cron. It needs no Composer packages, no
long-running processes and no root access. See
[docs/INSTALL-DirectAdmin.md](docs/INSTALL-DirectAdmin.md).

## API

Base URL, for example `https://nightdust.eu/cpt-api`. Responses are JSON,
`Cache-Control: no-store`.

- **Without a key** you can read DOV objects and search near a point:
  `GET /`, `GET /cpt/{dovId}`, `GET /borehole/{dovId}`,
  `GET /interpretation/{dovId}`, the `near` searches and the favourite
  boreholes (`GET /borehole/favourites`). These are limited
  per IP address (see *Rate limits and cache* under Security). Since
  1.6.0, the uploads shared with the whole library (`visibility: library`)
  are public too: a `near` search without a key lists them, and
  `GET /cpt/{uploadId}` returns them. Private and group uploads still need
  a key.
- **With a key** (`X-Api-Key: cptlib_…`) you can also refresh DOV objects,
  read earlier revisions, upload and delete uploads, read private and group
  uploads, and mark
  boreholes as favourites (1.5.0). A key has
  its own, larger limits.
- A key that is sent must be valid. An invalid key is refused with 401; it is
  never treated as "no key".
- `public.enabled => false` in the config turns keyless reads off, so every
  request needs a key again, as before 1.2.0.
- The CORS preflight (`OPTIONS`) never needs a key.

| Method and path | Key | What it does |
|---|---|---|
| `GET /` | no | Connection test: returns your user name (or `"anonymous": true`), the version and the licence. |
| `GET /cpt/{dovId}` | no | Returns the stored DOV CPT. If it is missing, the server fetches it from DOV, stores it and returns it. |
| `GET /cpt/{dovId}?refresh=1` | yes | Asks DOV again, unless the stored copy was checked less than 24 h ago. If the XML changed, a new revision is stored and the old one is kept. |
| `GET /cpt/{dovId}?revision={sha256}` | yes | Returns an exact earlier revision. |
| `GET /cpt/{dovId}?format=xml` | no | Returns the raw XML; the metadata comes in `X-Cpt-*` headers. |
| `GET /cpt/{dovId}/revisions` | yes | Lists every stored revision. |
| `GET /cpt?near={lat},{lon}&radius={m}` | no | Lists the CPTs in the library within the radius (default 1000 m, max 10 000 m), sorted by distance: DOV CPTs in `dov`, and in `uploads` the uploads your key may see (without a key: the library's, 1.6.0). It never asks DOV. |
| `POST /cpt` | yes | Uploads one of your own CPTs as GEF or CSV text, after the quality check. |
| `GET /cpt/{uploadId}` | no for a library upload (1.6.0) | Returns an upload your key may see, with its text. Without a key: a library upload only. |
| `DELETE /cpt/{uploadId}` | yes | Deletes one of your own uploads. |
| `GET /borehole/{dovId}` | no | Returns the stored DOV borehole (*boring*), fetched from DOV when missing. Takes `refresh`, `revision` and `format` like a CPT, and has `/revisions`. |
| `GET /interpretation/{dovId}` | no | The same for a DOV interpretation (*interpretatie*), such as a borehole's lithological description. |
| `GET /borehole?near={lat},{lon}&radius={m}` | no | Lists the boreholes in the library within the radius, like the CPT search. It never asks DOV. |
| `GET /borehole/favourites?ids={dovId},{dovId}` | no | Which of these boreholes (at most 200) are favourites, who marked them and when (1.5.0). |
| `PUT /borehole/{dovId}/favourite` | yes | Marks a borehole as a favourite; the key must be allowed to write (1.5.0). |
| `DELETE /borehole/{dovId}/favourite` | yes | Clears the mark; any key that may write (1.5.0). |

`{dovId}` must match `^\d{4}-\d{6}$`. This is `DOV_IDENTIFIER_ONLY` from the
app's `js/project-information.js`, for example `2002-009953`.
`{uploadId}` looks like `p-0123456789abcdef`. Anything else gets a 400 error.
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

### Favourite boreholes (1.5.0)

A favourite marks a borehole whose data is of higher quality. The mark is
shared: everyone sees it, with or without a key, and every key that may
write can set or clear it. There is one mark per borehole; marking it again
records the new key and time.

- `PUT /borehole/{dovId}/favourite` answers `{"dovId": "2016-122561",
  "favourite": {"setBy": "alice", "setAt": "2026-10-17T12:00:00Z"}}`.
  `DELETE` answers `"favourite": null`, also when there was no mark. Without
  a key: 401; with a key that may not write: 403.
- The mark is kept by the DOV id (table `borehole_favourites`). The
  borehole need not be in the library, and marking it never asks DOV.
- `GET /borehole/favourites?ids=2016-122561,2016-000002` answers
  `{"type": "borehole", "favourites": {"2016-122561": {"setBy": "alice",
  "setAt": "…"}}}`; ids without a mark are left out. More than 200 ids, or
  one that is not a DOV id, is a 400 `invalid_ids`.
- `GET /borehole/{dovId}` and each borehole in `GET /borehole?near=` carry
  `"favourite"`: the mark, or `null`.
- `setBy` is the key's user name, as `GET /` names it.

### `POST /cpt`

```http
POST /cpt
X-Api-Key: cptlib_…
Content-Type: application/json

{ "name": "Site A – S1", "format": "gef", "content": "#GEFID= 1, 1, 0\r\n…#EOH=\r\n…",
  "visibility": "library",
  "latitude": 51.05, "longitude": 3.72,
  "location": { "method": "street", "text": "Kerkstraat, 9000 Gent" },
  "testDate": "2025-03-04", "origin": "file" }
```

- `format` is `gef` or `csv`. If you leave it out, the server detects it.
  GEF needs `#GEFID` and `#EOH`; CSV needs at least two rows of numbers.
- `visibility` decides who sees the upload:
  - `private` (the default): only the uploader;
  - `group`: the uploader and every key in the uploader's group
    (`bin/keys.php group`);
  - `library`: everyone. Before 1.6.0 this meant every key; since 1.6.0 a
    library upload is also read without a key, like DOV data.

  Private and group uploads are never visible without a key.
- `latitude` and `longitude` are optional for a private upload. A group or
  library upload needs both, and `location.method`. Give both or neither.
- `location` says how the position is known, because many CPTs carry only a
  street or a municipality:

  | `method` | Meaning | Default accuracy |
  |---|---|---|
  | `survey` | Measured coordinates, for example from the GEF header | 5 m |
  | `address` | A street and house number | 25 m |
  | `map` | Placed by hand on a map | 50 m |
  | `street` | A street without a number | 250 m |
  | `municipality` | Only the municipality | 2000 m |

  `accuracyM` (1–20 000 m) overrides the default. `text` (at most 200
  characters) keeps the address or place name as given.
- `origin` (1.3.0; `image-plot` and `ai-table` since 1.4.0) says where the
  readings come from:
  - `file` (the default): a data file such as a GEF, a CSV or a
    spreadsheet;
  - `pdf-table`: a table of readings in a PDF report;
  - `pdf-plot`: digitised from a plot in a PDF report. These readings are
    less exact than the test's own data;
  - `image-plot`: traced from a plot in a picture (a scanned page or an
    image file). Less exact than the test's own data, like `pdf-plot`;
  - `ai-table`: read by an AI model from a table in a picture, read twice
    and compared with the page by the uploader. Its values were written by
    the AI, not taken from the test's data.

  Every answer that names the upload says where its readings come from: the
  `origin` field in the upload answer, in `GET /cpt/{id}`, in
  `possibleDuplicates` and in the `near` search.
- `testDate` is the date of the test (`YYYY-MM-DD`). It is optional, but
  the answer warns when it is missing because it helps to find duplicates.
- The limit is 3 MB of text (kept under PHP's `post_max_size` of 8 MB,
  because JSON escaping can nearly double the size). A body that carries
  `dovId`, `url` or `sourceUrl` is refused.

**Quality check.** Before anything is stored, the server reads the depth,
qc and fs columns:

- GEF: by their `#COLUMNINFO` quantity numbers (11 corrected depth or 1
  penetration length, 2 qc, 3 fs), with kPa converted to MPa.
- CSV: in that order, or by a header row naming them.

The upload is refused with `422 quality_failed` and a `problems` list when:

- it has fewer than 10 readings;
- the depth goes back up;
- a depth is outside 0–100 m;
- a qc is outside 0–100 MPa, or an fs outside 0–2 MPa (usually kPa sent as
  MPa);
- the readings cover less than 0.5 m;
- every qc is zero.

It is stored, but with `warnings`, when it has no fs, when many friction
ratios are above 15 %, or when no test date was given. Nobody reviews
uploads by hand.

**Answer.** `201` with a summary:

```json
{ "kind": "upload", "private": false, "visibility": "library", "origin": "file", "id": "p-…",
  "name": "Site A – S1", "owner": "joakin", "format": "gef", "sha256": "…", "bytes": 5321,
  "createdAt": "…", "testDate": "2025-03-04", "rowCount": 412, "finalDepth": 20.6,
  "position": { "latitude": 51.05, "longitude": 3.72 },
  "location": { "method": "street", "accuracyM": 250, "text": "Kerkstraat, 9000 Gent" },
  "duplicate": false, "warnings": [],
  "possibleDuplicates": [ { "kind": "dov", "dovId": "2002-009953", "name": "…", "distance": 41.2 } ] }
```

- **Exact duplicates.** If you can already see an upload with exactly the
  same text, it is not stored again. Its summary comes back with `200` and
  `"duplicate": true`. An upload you cannot see (another user's private
  one) is never revealed, so you get your own copy.
- **Possible duplicates.** `possibleDuplicates` lists the CPTs that may be
  the same test, nearest first:
  - uploads you can see, within the larger of the two location accuracies
    (at least 25 m, at most 2 km), with the same test date and a final
    depth within 0.5 m where both are known;
  - DOV CPTs in the library within the upload's accuracy (10–100 m).

  They are only reported. Nothing is merged.

Uploads live in their own table (`private_cpts`) and their own id space, and
never appear among the DOV results. For a key that may not see an upload it
reads as "not found". `GET /cpt?near=` returns uploads in the `uploads`
array (named `private` before 1.2.0), each with its `location`.

### Errors

Every error is JSON, `{"error": "<code>", "message": "<text>"}`. It never
contains stack traces, SQL or file paths. A 500 response also carries a
`requestId`, which you can find in the server's error log. The codes are:

| Status | Codes |
|---|---|
| 400 | `invalid_id`, `invalid_ids`, `invalid_path`, `invalid_near`, `invalid_radius`, `invalid_revision`, `invalid_body` |
| 401 | `unauthorized` (no key where one is needed, or an invalid key) |
| 403 | `https_required`, `forbidden` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 413 | `payload_too_large` |
| 415 | `unsupported_media_type` |
| 422 | `quality_failed` (with `problems` and `warnings`) |
| 429 | `rate_limited`, `dov_rate_limited` (both with `Retry-After`) |
| 502 | `upstream_unavailable`, `upstream_invalid`, `upstream_redirect`, `upstream_too_large` |
| 503 | `busy`, `database_unavailable` |
| 507 | `storage_full`: the database reached `storage.max_database_mb`. Stored objects are still served. |

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
- **API keys.** Reading DOV objects and library uploads needs no key. Everything else needs
  `X-Api-Key`, and a key that is sent must be valid. Keys are random, with
  238 bits of entropy. The database stores only an HMAC-SHA256 hash of each
  key, made with a secret pepper from the config file. Each user has one key.
  You create, rotate, revoke and list keys, and put users in groups, with
  `bin/keys.php`. Keys are never accepted in the URL.
- **CORS is not authentication.** The server allows `Origin: null` (the
  file:// app) and the `X-Api-Key` header, with no cookies or credentials.
  Uploads still need a valid key from any origin. CORS only decides
  whether a browser lets the page read the answer.
- **Rate limits and cache.**
  - Each key is limited per minute and per day, and POSTs per hour.
  - Requests without a key are limited per IP address, per minute and per
    day.
  - Failed logins are limited per IP.
  - DOV fetches have budgets of their own: per key per hour, or without a
    key per IP address per hour and for all keyless requests together per
    day, and one for the whole server per minute.

  On top of that:
  - Stored CPTs and boreholes are served without asking DOV.
  - A refresh within 24 h of the last check is skipped.
  - A DOV "not found", or an answer that is not CPT XML, is remembered
    for 1 h.
  - Only one fetch per object runs at a time (MySQL `GET_LOCK`).
- **Storage cap.** `storage.max_database_mb` (default 2048) caps what can
  be added. When the tables reach it, nothing new is fetched or uploaded
  (`507 storage_full`), and stored objects are still served, stale if a
  refresh was asked for. Uploads are also refused when they fail the
  quality check, so a bad file never reaches the database.
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
docs/              install guide
```

## Tests

```sh
php tests/run.php                 # all tests
php tests/run.php revision        # tests whose name contains "revision"
```

The tests cover id validation, the DOV-only URL rule, redirects,
authentication, key hashing and revocation, rate limits, revisions, the
"not found" cache, CORS headers, uploads, the `near` search, JSON
errors, logs without keys, a backup and restore round trip, and boreholes
and interpretations (their URLs, classification, storage apart from the
CPTs, revisions, shared budgets and their own `near` search). Since 1.2.0
they also cover keyless reads and their budgets, the storage cap, the
quality check, visibility and groups, and the duplicate checks. Since 1.3.0
they cover where an upload's readings come from, and since 1.4.0 the
`image-plot` and `ai-table` origins (accepted, read back from the upload
answer, `GET /cpt/{id}`, possible duplicates and the `near` search, and
short enough for the database column). Since 1.5.0 they cover favourite
boreholes (who may set and clear them, everyone reads them, the lookup's
limits, the mark in the borehole's answer and in `near`, one mark per
borehole), and since 1.6.0 library uploads without a key (read and listed
in `near`, never a private or group one, never deleted). Most of them
need an **empty test database whose name contains `test`**, because the
tests drop all its tables. Configure it in `tests/config.test.php` (copy
`tests/config.test.example.php`) or with the `CPT_TEST_DB_*` environment
variables. If no test database is configured, the database tests report
`SKIP` and the run fails. Pass `--allow-skip` to run only the unit tests.
The tests use a fake DOV and a fixed clock, and never touch the network.
