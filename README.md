# Unphramed tools — private prototype

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="logo-dark.svg">
  <img src="logo.svg" alt="Unphramed" width="280">
</picture>

Composer **development-only** tools for native Nginx method-to-file associations.
Apache support is not implemented. No release or remote package source is available.
The website repository is private and is not an installation source for application users.

After obtaining the package through a separately supplied repository or private
artifact, install with `composer require --dev unphramed/tools:0.1.0`.
The consuming application chooses `require-dev`; Composer cannot enforce this
from package metadata. Production HTTP behavior must work with `--no-dev`.

The CLI provides grouped help and an aligned route listing. Terminal output uses
ANSI colors without additional dependencies. Colors are disabled for redirected
output, when `NO_COLOR` is set or `TERM=dumb`; JSON output remains unstyled.

## Commands and prerequisites

The installed `vendor/bin/unphramed-routes` targets the current directory or explicit
`--project DIR`. PHP 8.5 with ctype/posix is required. The application supplies
`config/nginx.conf` and its PHP targets. The routing fragment contains locations,
server-level direct-script guards and a fallback, included inside an environment-owned
virtual host. The CLI parses this supported syntax and lints endpoints; it needs no
Nginx, PHP-FPM, Python, distribution configuration files or server settings.
It never generates a server configuration, starts a service or executes the application.

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
and a status/target. `parameters` contains name, type, capture and `source: "requestPath"`. This replaces the former
`serverKey` metadata: path values are extracted by the endpoint from `REQUEST_URI`,
not supplied as custom FastCGI parameters. Refresh CLI and IDE together for this alpha profile.
Changes use `update` or `create` with the complete proposed text and the previous
SHA-256 (`null` for a new file). Preconditions list all checked inputs with their
SHA-256 or required absence. This interface requires updating the IDE plugin together.

## Native profiles

The supported profile uses location-local method guards, explicit literal endpoint
selection and `rewrite ^ TARGET last` to the environment’s common PHP location.
Each admitted method is explicit. Multiple methods can reuse one PHP file; different
files on one public path use native `set` assignments with an empty default and 405 guard.
Custom methods follow Nginx's parser limits. Typed routes support int/slug/string captures
validated by the endpoint against the original request path. Unknown syntax blocks edits rather than guessing.

Generated locations list their methods in a literal `Allow` header with `always`.
The header is returned on 405; accepted requests continue to the common PHP location.
Extending one location preserves captures, comments and all
unrelated source text. No shared method map is changed and no PHP file is reorganized.

`list` is useful even when Allow is wrong; `check` reports the mismatch and edits are
blocked. Unsupported syntax yields located diagnostics, a nonzero exit and an explicitly
incomplete inventory of recognized entries. Edits require a complete inventory.
The collision check is conservative: potentially overlapping typed routes are refused,
even when a carefully ordered hand-written configuration could resolve them.

## Verification limits

`check` parses supported application route directives, checks method/Allow consistency
and lints PHP targets. It does not invoke Nginx or validate the full server configuration,
PHP-FPM, authentication, CSRF or HTTP behavior. Validate the complete Nginx configuration
and HTTP behavior through the application's environment before deployment.

Writes use advisory locks, source hashes and ordinary-failure rollback. Other writers
must respect the locks. A process crash or forced termination is not a multi-file
transaction; use application version control for recovery. No service is started or reloaded.

The private artifact builder and IDE instructions live in the maintainer monorepo.
The planned `unphramed/tools` GitHub repository is a package-only distribution mirror;
development remains in the private monorepo. Do not edit the mirror independently.
No licensing or publication decision is made by this prototype.

## Single-file alpha migration

Former `config/http.conf` / `config/routes.conf`, maps-plus-server and per-route
FastCGI include formats require
manual migration to the server-included fragment documented on the website. The CLI
rejects those formats and does not convert or modify deployment configuration.
