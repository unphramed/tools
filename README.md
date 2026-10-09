# Unphramed tools — private prototype

Composer development package, currently providing tools for the native Nginx
GET/HEAD route profile. Apache support is not implemented. No release or
remote package source is available. The website repository is private and is not
an installation source for application users.

The installed `vendor/bin/unphramed-routes` targets the application's current
working directory or explicit `--project DIR`. PHP 8.5 with ctype/posix is required;
`check` and `add-read` also require Nginx and PHP-FPM on PATH.

Operations: `list`, `check`, and `add-read --path /hello --endpoint web/GET/hello.php`.
Add `--json` for result version 1. Preview is the default; only `--apply` writes.
An IDE can pass the preview's `beforeSha256` as `--expected-routes-sha256 HASH`
with `add-read --apply`; a changed route source is rejected before writing.

Supported sources are the internal demonstration's single method map and locations,
or the website exercise's method map plus checked Allow map and its relative
FastCGI include. Each profile remains strict. Native configuration is the only
editable routing source; no manifest, discovery or HTTP router is introduced.

The tool refuses unknown syntax, overlaps, symlinks and endpoint overwrites.
Generated endpoints return 501 until implemented. Syntax checks do not prove HTTP
behavior. No live service is started or reloaded. Ordinary write failures are
rolled back; a machine crash or forced termination is not a two-file transaction.

The private artifact builder and PhpStorm prototype instructions live in the
maintainer repository. Future users must obtain this package independently via
Composer, without access to website sources. No licensing or publication decision
is made by this prototype.

The GitHub distribution repository is `unphramed/tools`. It is intended to contain
only this package, with `composer.json` at its root. Development remains in the
private Unphramed monorepo; the mirror is read-only for contributions and must not
be edited independently.
