# Unphramed tools — private prototype

Composer **development-only** tools for native Nginx method-to-file associations.
Apache support is not implemented. No release or remote package source is available.
The website repository is private and is not an installation source for application users.

After obtaining the package through a separately supplied repository or private
artifact, install with `composer require --dev unphramed/tools:0.2.0-dev`.
The consuming application chooses `require-dev`; Composer cannot enforce this
from package metadata. Production HTTP behavior must work with `--no-dev`.

## Commands and prerequisites

The installed `vendor/bin/unphramed-routes` targets the current directory or explicit
`--project DIR`. PHP 8.5 with ctype/posix is required; `check` and additions also
require Nginx and PHP-FPM on PATH. The application supplies `config/http.conf`,
`config/routes.conf`, its project-owned `config/fastcgi-params.conf`, bootstrap and
Composer autoloader. No Python, web-server reload or application execution is used by the CLI.

```sh
vendor/bin/unphramed-routes list
vendor/bin/unphramed-routes check
# Existing target: no PHP file is modified.
vendor/bin/unphramed-routes add --path /books --method GET --method HEAD --endpoint web/GET/books.php
# New target: request a 501 skeleton explicitly.
vendor/bin/unphramed-routes add --path '/books/{id:int}/{slug:slug}' --method PURGE --endpoint web/purge.php --create-endpoint
```

Preview is the default. Only `--apply` writes. Use `--json` for version 2, and pass
its `planSha256` to `--apply --expected-plan-sha256 HASH` when applying a reviewed
preview. The guard covers HTTP maps, locations, shared parameters, bootstrap and
endpoint inputs. `add-read` remains a compatibility command that explicitly selects
GET and HEAD and requests a new endpoint. Its old route-only hash option is retained
for compatibility but cannot guard the full preview; new clients must use the plan hash.

JSON v2 returns `routes`, `diagnostics`, `changes`, `preconditions`, `planSha256`,
`ok`, `operation` and `message`. Endpoint records group methods selecting one file;
redirect records have `kind: redirect`, `endpoint: null`, empty `methods` (unrestricted),
and a status/target. `parameters` contains name, type, capture and serverKey.
Changes use `update` or `create` with the complete proposed text and the previous
SHA-256 (`null` for a new file). Preconditions list all checked inputs with their
SHA-256 or required absence. This interface requires updating the IDE plugin together.

## Native profiles

Both documented profiles are supported: shared method-directory maps and
resource-specific file maps. Variable names and literal upstream names are
application-owned. Exact paths (including `/`), local redirects and several typed
captures are interpreted. Parameters are `{name:int}` (1–999999999), `{name:slug}`
(lowercase alphanumeric words separated by hyphens), and `{name:string}` (one
normalized segment of 1–255 bytes). Historical `{name}` means integer.
Captured values use explicit `ROUTE_*` FastCGI parameters, separate from query fields.

Methods are explicit; adding GET does not add HEAD or OPTIONS. Standard and custom
methods use the same mechanism. The current Nginx HTTP/1 parser accepts uppercase
letters, hyphens and underscores in method names. This FastCGI profile excludes
TRACE and CONNECT; it does not promise every HTTP token can be served by Nginx.

Use existing `.php` targets under `web/` or request a new skeleton. Method directories
are a convention, not a generator requirement. Existing PHP is never overwritten.
When extending a location, fresh maps preserve other locations sharing its original
maps. Its comments and all unrelated source text are retained; the affected location's
formatting can change in the preview. Old maps remain untouched rather than being
removed without proving they are unused.

FastCGI settings are inferred only when unambiguous; otherwise supply
`--fastcgi-pass VALUE` and `--params-include VALUE`. Supported parameter includes
are `fastcgi-params.conf`, `../config/fastcgi-params.conf`, or the absolute path to the
project's `config/fastcgi-params.conf`. Includes resolve from the main Nginx configuration
directory: retain the application's actual convention. Unknown includes are not followed.

`list` is useful even when Allow is wrong; `check` reports the mismatch and edits are
blocked. Unsupported syntax yields located diagnostics, a nonzero exit and an explicitly
incomplete inventory of recognized entries. Edits require a complete inventory.
The collision check is conservative: potentially overlapping typed routes are refused,
even when a carefully ordered hand-written configuration could resolve them.

## Verification limits

`check` lints targets and tests a disposable Nginx/FPM envelope. Named upstreams are
substituted with loopback syntax-test destinations; socket targets are never connected.
This does not validate the application's actual envelope, live FPM connection,
authentication, CSRF or HTTP behavior. Run the application's own configuration and
HTTP checks before deploying or restarting its dedicated processes.

Writes use advisory locks, source hashes and ordinary-failure rollback. Other writers
must respect the locks. A process crash or forced termination is not a multi-file
transaction; use application version control for recovery. No service is started or reloaded.

The private artifact builder and IDE instructions live in the maintainer monorepo.
The planned `unphramed/tools` GitHub repository is a package-only distribution mirror;
development remains in the private monorepo. Do not edit the mirror independently.
No licensing or publication decision is made by this prototype.
