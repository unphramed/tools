# Unphramed tools — private prototype

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="logo-dark.svg">
  <img src="logo.svg" alt="Unphramed" width="280">
</picture>

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
vendor/bin/unphramed-routes add --path /books --method GET --endpoint web/GET/books.php
# Inferred target: preview, then review and confirm to create a missing 501 skeleton.
vendor/bin/unphramed-routes add --path '/books/{id:int}/{slug:slug}' --method PURGE --dry-run
vendor/bin/unphramed-routes add --path '/books/{id:int}/{slug:slug}' --method PURGE
```

`add` displays the diff and asks `Apply these changes? [y/N]`. Only `y` or `yes`
writes; Enter, EOF or another answer cancels. Use `--dry-run` to preview only.
GET is the default method. An absent endpoint becomes a 501 skeleton; an existing
file is reused without changes. `--endpoint` overrides the inferred path.

```sh
vendor/bin/unphramed-routes add --path /users
vendor/bin/unphramed-routes add --path /users --dry-run
vendor/bin/unphramed-routes add --path /users --method POST
vendor/bin/unphramed-routes add --path /users --endpoint web/custom/users.php
```

Endpoint inference is a tooling convention, not a runtime dispatcher. Append
`index.php` beneath `web/METHOD/` and one directory per route segment: `/` becomes
`web/GET/index.php`, `/users` becomes `web/GET/users/index.php`, and
`/users/{id:int}` becomes `web/GET/users/_id_int/index.php`. Untyped `{id}` means
`{id:int}`. Parameter directories use `_name_type`; literals beginning with `_`
receive one additional `_` to keep them distinct. No singularization or model
naming is guessed. Several methods in one addition require an explicit endpoint
because sharing a handler is a deliberate choice. Native Nginx remains authoritative:
inference neither rewrites existing routes nor requires every application to adopt it.

For scripts and IDE clients, preview with `--dry-run --json`, then run the same
addition with `--expected-plan-sha256 HASH` (without `--dry-run`). It applies the
reviewed plan without prompting. JSON cannot prompt, and redirected terminal
input/output cannot implicitly approve writes. Input hashes are checked at application,
including after interactive confirmation. `--apply` is no longer supported.
The `--create-endpoint` option remains a strict request for a new file and refuses
an existing destination; it is unnecessary for ordinary additions. Neither existing
PHP nor existing method associations are overwritten.

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
