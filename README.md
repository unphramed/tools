# Unphramed tools — private prototype

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="logo-dark.svg">
  <img src="logo.svg" alt="Unphramed" width="280">
</picture>

Composer **development-only** tools for native Nginx routing and explicit PHP dependency factories.
Apache support is not implemented. No release or remote package source is available.
The website repository is private and is not an installation source for application users.

After obtaining the package through a separately supplied repository or private
artifact, install with `composer require --dev unphramed/tools:0.1.0`.
The consuming application chooses `require-dev`; Composer cannot enforce this
from package metadata. Production HTTP behavior must work with `--no-dev`.

Both CLIs provide grouped help, aligned inventory/factory tables and common diagnostic
and change-preview formatting. Terminal output uses
ANSI colors without additional dependencies. Colors are disabled for redirected
output, when `NO_COLOR` is set or `TERM=dumb`; JSON output remains unstyled.

## Refresh a private alpha artifact

Private builds stay at `0.1.0` during the alpha phase. After the supplier
replaces the ZIP in your configured Composer artifact repository, run:

```sh
composer remove --dev unphramed/tools
composer --no-cache require --dev unphramed/tools:0.1.0
```

This temporarily removes the tools and their unused dependencies, then installs
the new build and refreshes its checksum in `composer.lock`. Review the lock diff.
A simple update can retain old files; reinstall alone can retain an old checksum.
JSON protocol versions (routes v2 and DI v1) are independent of the package version.

## Commands and prerequisites

The installed `vendor/bin/unphramed-routes` targets the current directory or explicit
`--project DIR`. PHP 8.5 with ctype/posix is required. The application supplies
`config/nginx.conf` and its PHP targets. The routing fragment contains server rewrites and optional locations,
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
and a status/target. `parameters` contains name, type, capture and `source`. Rewrite captures use `source: "query"`
with `queryKey`; optional location captures use `source: "requestPath"`. This replaces the former
`serverKey` metadata: path values reach `$_GET` in the rewrite profile or are extracted by the endpoint
from `REQUEST_URI` in the optional location profile. No custom FastCGI parameters are supplied. Refresh CLI and IDE together for this alpha profile.
Changes use `update` or `create` with the complete proposed text and the previous
SHA-256 (`null` for a new file). Preconditions list all checked inputs with their
SHA-256 or required absence. This interface requires updating the IDE plugin together.

## Native profiles

The default inferred-endpoint profile emits an anchored server-level rewrite to
`/$request_method/...php`. Present matching method files determine available methods;
an absent method file receives 404. Adding a method file extends the same route without
rewriting its native text. No HEAD file is created with GET. The inventory is derived
from the route and files, not a runtime registry. Changes in discovered method files
invalidate reviewed plans, including after interactive confirmation.

Numeric and URL-safe slug captures are positional query bindings, placed after `$args`
with a terminal `?`. The documented application bootstrap rejects requests whose
rewritten query may exceed PHP's input-variable limit; the CLI supplies no HTTP runtime
helper. Arbitrary strings are not safely interpolated into query strings and use the
optional location/request-path profile instead. Unbounded `\d+` captures can be inventoried;
PHP must validate integer representability. Generated integer patterns remain bounded.

Explicit custom endpoints and arbitrary string captures use optional guarded locations,
with chosen method associations, literal rewrite targets and 405/Allow. Several methods
may share one file; different targets use native local `set` assignments. The CLI does
not silently convert a method-directory rewrite into this profile; extend it with the
same method-relative file or adapt the native source deliberately. A fixed-target manual
rewrite admits all matching methods and is inventoried with method `*`.

All profiles retain manual text and existing PHP. One direct-PHP guard precedes server
rewrites, with no method-directory enumeration. PHP-FPM transport and virtual hosts belong
to the environment. Unknown syntax blocks edits rather than guessing.

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

## Explicit dependency factories (private 0.1.0 prototype)

The package also provides `vendor/bin/unphramed-di`. It uses `nikic/php-parser` as a
Composer dependency of this development-only tool, not an application runtime
container. The generated PHP needs neither package in production. The DI binary
loads only tool/parser classes; it does not load the consuming application's
Composer autoload files or execute its classes to discover constructors.

## Interactive dependency assembly

From the application's root, run:

```sh
vendor/bin/unphramed-di
```

The terminal assistant detects an existing class named `DI` and its file. If several
exist, choose one; if none exists, enter the fully qualified class and destination
PHP file. The parent directory must already exist. `--project DIR` selects another
application without changing your working directory.

Discovery scans only the project's main Composer PSR-4/PSR-0 autoload directories.
Development-only classes and vendor classes are not offered; installed dependencies
remain available when resolving constructors. No application PHP is executed.
Classes with an existing compatible factory, including private factories, are already
configured and are left untouched. Factories are recognized by their return type,
not their name.

Suggested DI additions are unconfigured classes whose required object-constructor
dependencies reach an existing compatible factory, directly or transitively. The
list shows one existing dependency that motivates each suggestion. Analysis stops
at existing factories; their bodies and lifetimes remain opaque. Optional constructor
parameters retain their defaults and do not establish a DI link.

These suggestions are selected by default. Enter numbers separated by commas or
spaces to select a subset, `all` for all suggestions, `none` to cancel, or `q` to
quit. Selected classes become public factories; newly constructed dependencies
outside the selection become private factories, including required helpers without
constructors. Related classes needing manual configuration or ambiguous/colliding
names are reported and skipped.

Construction alone does not imply that a class needs a DI factory. The assistant
does not classify objects by namespace or prescribe a model organization. Autonomous
views, data objects and static utilities have no constructor link to DI and are not
suggested merely because `new` would succeed. A DI link is a conservative heuristic,
not proof that a constructor argument is a technical dependency rather than data.

Enter `other` at the class selection to browse all remaining constructible classes.
When there are no suggestions, the assistant offers the same list through
`Show other constructible classes? [y/N]`. This opt-in list starts with no selection;
Enter cancels. It supports autonomous components and projects with no existing
factories. The advanced `generate --expose` command remains available for explicit
choices and manual configuration. Unsupported unrelated classes do not produce
constructor-diagnostic noise in the default suggestions.

A second numbered selection chooses which new types to share. Pressing Enter
keeps all new objects fresh. Existing factories retain their own lifetimes.
Review the diff before answering `yes` to apply; Enter, `no`, or end of input
cancels without writing. Source and destination changes after analysis invalidate
the preview. Existing methods and surrounding PHP remain intact. The tool adds
factories; it does not repair wiring inside existing methods.

Without terminal input and output, the bare command displays help and never
writes. Use `generate` for scripts and IDE clients; its JSON v1 and hash-based
application contract are unchanged.

## Advanced dependency assembly

From a PSR-4 application containing `App\RatingSummary`, its formatting collaborators
and a manually configured `App\DisplaySettings` getter (as in the website's
standalone rating-summary exercise):

```sh
vendor/bin/unphramed-di generate --expose 'App\RatingSummary' --expose 'App\DisplaySettings'
vendor/bin/unphramed-di generate --expose 'App\RatingSummary' --expose 'App\DisplaySettings' --json
```

The defaults are `--class 'App\DI' --target src/DI.php`; the parent directory must
exist. Repeat `--expose` for public entry points and `--shared` for objects retained
in a local static. Other newly generated reachable factories are private and return a fresh object
on every call. Public/private and shared/new are independent decisions. Automatic
method names use `get` plus the class's short name; use `--name 'App\RatingSummary=createRatingSummary'` to
resolve a collision or choose a different name. Options are also accepted with
`--project DIR`; the current application directory is otherwise used.

The graph is inferred from concrete required constructor parameter types. Aliases,
Composer PSR-4/PSR-0 sources from the project's autoload/autoload-dev and installed packages,
constructor property promotion and resolved inherited constructors are supported.
Optional parameters are omitted, retaining their PHP defaults. Required nullable
class parameters still require an object; no implicit null is supplied.
Classmap-only/custom autoloading, traits, variadic/by-reference parameters, required
scalar/untyped/union/intersection parameters and non-public constructors require
manual factories. Duplicate source candidates and dependency cycles are diagnosed.
Symlinks and sources outside the selected project are refused in this prototype.

Existing static factories with no required arguments and a compatible non-null
class/interface return type are discovered by their types, not their names. A
unique match is reused automatically. A compatible concrete implementation also
matches an interface parameter. Each graph node's optional `candidates` list
exposes the matching methods to IDE clients; this is an additive DI JSON v1 field.
Several matches stop generation and require `--factory TYPE=METHOD`. Unresolvable
return types cannot be inferred as matches. A private or protected factory cannot
satisfy a public entry point without a developer decision.

For example, a `RatingRepository(Connection $db, UserRepository $userRepository,
Visitor $visitor)` constructor can call existing `getDbConnection()`,
`getUserRepository()` and `getVisitor()` methods without three explicit mappings.
These are application-provided contracts, not a prescribed repository or storage
implementation. The tool never reads configuration from those method bodies.

Requesting an existing compatible public getter again yields no changes, including
when its body has been manually edited. The tool does not repair wiring inside an
existing getter. To construct a separate graph, select a fresh `--name`; to change
an existing getter's sharing, edit and test its PHP. `--shared` is rejected for a
reused factory rather than silently changing its lifetime.

Keep application configuration and implementation choices in manually written PHP,
and choose a factory explicitly when necessary:

```sh
vendor/bin/unphramed-di generate --expose 'App\RatingSummary' --factory 'App\DisplaySettings=getDisplaySettings'
```

`getDisplaySettings` must already exist in the selected DI class, be static, require no
arguments and declare a compatible non-null return type. It may be private unless
its type is selected as a public entry point. Analysis stops at that method. Its
body, name and lifetime remain application-owned; do not combine a manual factory
with `--shared` or `--name` for the same type. Unknown internal types may be supplied
by a factory returning exactly that type. The tool does not prove a manual factory's
behavior or absence of hidden cycles.

Review the full proposed file. Applying requires the hash from the successful
preview, with identical choices:

```sh
vendor/bin/unphramed-di generate --expose 'App\RatingSummary' --expose 'App\DisplaySettings' \
    --apply --expected-plan-sha256 HASH_FROM_PREVIEW
```

The apply command reanalyzes sources, checks the plan and all input hashes, and
locks existing inputs while writing. New files use exclusive creation. Existing
method bodies, comments and visibility are never rewritten; additions are inserted
before the selected class's closing brace. Unique compatible getters are reused;
ambiguity requires `--factory`, or a fresh `--name` for a separate graph. Existing DI classes
with inheritance or traits are refused. No permanent generation manifest or service
registry is created. Both final and abstract DI classes are supported: static
factories must themselves be concrete; DI is never instantiated by the tool.
The file becomes application-owned PHP.

A failed analysis contains a partial graph and located diagnostics, but no changes
or usable plan hash. Ordinary write failures attempt rollback; this is not a
crash-safe filesystem transaction, and advisory locks do not constrain editors
that ignore them. Review generated code and test the application wiring.

DI uses independent JSON **version 1**, operation `generate`: `ok`, `graph`,
`diagnostics`, `changes`, `preconditions`, `planSha256` and `message`. A graph node
contains `type`, `method`, `visibility`, `lifetime` (`new`, `shared`, `manual`),
`manual`, `dependencies`, `file` and `line`. Changes are full destination texts;
preconditions include analyzed sources, Composer metadata, the destination, and
absent candidate files. Success agrees with process exit status. Route JSON remains
version 2. The PhpStorm generator requires this executable; route-only plugin
versions can continue to use the existing route interface.

This package and its dependency are supplied privately for the prototype. No
Packagist release of `unphramed/tools` is implied by these commands.

## Migrate the former two-file alpha format

The current CLI expects the server-included fragment. It diagnoses former `http.conf`/`routes.conf` and maps-plus-server layouts; it does not convert them automatically.

1. Back up the existing configuration and retain assets and fallback locations. Place the single direct-PHP guard before server-level rewrites. No method-directory enumeration is needed.
2. Use the server-level rewrites described under Native profiles: `$request_method` selects a method directory. Retain existing explicit guarded locations only when their 405/Allow or shared-target policy is intentional. Files present in the selected method directories determine which methods are enabled.
3. Remove the enclosing `server` block and `*-server.conf` include from the application file. Have the environment include `config/nginx.conf` inside its virtual host instead.
4. Remove per-route FastCGI directives and transport includes. Use `rewrite ... last` to reach the environment’s common PHP handler. Numeric/slug path captures may become final query arguments after `$args`, ending the replacement with `?`. Use the common query-limit check in the documented bootstrap. Arbitrary string captures remain in the optional location/request-path profile; do not interpolate decoded query delimiters.
5. Remove application-owned FastCGI/FPM files only after the environment provides their required behavior. Validate the complete deployment and exercise methods, route/query collisions and private URLs.

The CLI's `check` parses the supported route syntax, checks optional method/Allow consistency and lints existing PHP targets. It neither invokes Nginx nor generates a server configuration. Validate the complete Nginx configuration and test the full request path through your environment. Former per-route transport includes are also unsupported and require this manual migration.
