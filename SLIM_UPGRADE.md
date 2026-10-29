# Slim Framework Upgrade (2.6.3 → 4.15.3)

This is on top of everything in [`CHANGES.md`](CHANGES.md) and
[`LIBRARY_UPGRADE.md`](LIBRARY_UPGRADE.md). It replaces the last piece of
legacy infrastructure: the routing framework itself.

## Why this was tractable in one pass

Slim 2 → 4 is normally a full rewrite — Slim 3/4 moved to PSR-7
(request/response are immutable objects passed into route callbacks,
instead of a global singleton you mutate), which usually means touching
every route handler in an app.

OpenSupports has an unusually clean boundary: **only 4 files in the whole
codebase talk to Slim directly** — `server/index.php`,
`server/libs/Controller.php`, `server/libs/ControllerGroup.php`, and
`server/models/Response.php`. Every individual controller under
`server/controllers/**` only ever calls `Controller::request($key)` and
`Response::respondSuccess()/respondError()/respond403()` — never Slim
itself. The whole migration happens by rewriting those 4 files, with
**zero changes to any individual controller**.

## What changed

**`server/composer.json`**: `slim/slim` bumped from `~2.0` to `^4.14`, and
`slim/psr7` (Slim's own PSR-7 implementation) added as a new direct
dependency — Slim 4 needs one and doesn't bundle one.

**`server/index.php`**: replaced `\Slim\Slim::registerAutoLoader(); $app = new \Slim\Slim();`
with `$app = \Slim\Factory\AppFactory::create();`, plus the two middleware
Slim 4 needs explicitly (Slim 2 did this implicitly):
`$app->addRoutingMiddleware()` (matches the URL to a route) and
`$app->addErrorMiddleware(false, false, false)` (turns Slim's "no route
matched" exception into a clean 404 instead of an uncaught exception).

It also now computes and sets a base path:

```php
$basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if ($basePath !== '/' && $basePath !== '.' && $basePath !== '') {
    $app->setBasePath($basePath);
}
```

Slim 2 used to strip the front controller's own directory from the request
path automatically, so a route like `/system/login` matched whether
`server/index.php` was the webroot or — the normal OpenSupports deployment
layout — reached as `/api/system/login` through the top-level `.htaccess`
rewrite into the `api/` (built from `server/`) subdirectory. Slim 4 requires
this base path to be set explicitly, or every route 404s as soon as the app
isn't served from the domain root. **This only shows up in a real nested
deployment** — it doesn't appear when `server/`'s built output is served as
its own webroot, which is how this was first tested, so it was found only
after deploying to a real server.

**`server/libs/Controller.php`**:
- `Controller::request($key)` used to read through Slim 2's
  `$app->request()->get()/post()`. It now reads straight from PHP's own
  `$_GET`/`$_POST` superglobals — populated by PHP itself regardless of
  what framework is in front of it, so this is *more* robust than going
  through Slim's request object, and it means `Controller::request()` no
  longer depends on Slim's internals at all.
- `Controller::getAppInstance()` used to call the removed
  `\Slim\Slim::getInstance()` static singleton (that pattern doesn't exist
  in Slim 4). Replaced with an explicit `Controller::setAppInstance($app)`
  call from `index.php`, stored in a static property.
- `Controller::getHandler()` (the closure every route actually runs) now
  matches Slim 4's calling convention — `function($request, $response, $args)`
  instead of `function()` — and **returns** the response object, which
  Slim 4 requires (PSR-7 responses are immutable; you hand the final object
  back instead of mutating a global).

**`server/models/Response.php`**: rewritten to build a PSR-7 response
instead of mutating Slim 2's `$app->response`. `respondSuccess()`/
`respondError()` write a JSON body, `respond403()` sets the status code.

**`server/libs/ControllerGroup.php`**: `$app->group($path, function() use ($app) {...})`
became `$app->group($path, function(RouteCollectorProxy $group) {...})` —
Slim 4 passes the group's own route-registrar object into the group
callback instead of relying on capturing `$app` by reference.

## A real bug this surfaced (and fixed)

Slim 2's `$response->setBody($json)` **replaced** the response body every
time it was called. Slim 4's PSR-7 `$response->getBody()->write($json)`
**appends** to it instead. `controllers/ticket/create.php` calls
`SignUpController`'s handler directly as a side effect (to auto-register the
ticket's guest author), and that inner call *also* calls
`Response::respondSuccess()` — once for the implicit signup, once for the
actual ticket creation. Under Slim 2 the second call silently overwrote the
first, so it never mattered. Under a naive Slim 4 port, both responses got
concatenated into one invalid, double-JSON blob. Fixed in `Response.php` by
rebuilding the response body from scratch on every `respond*()` call (via
`Slim\Psr7\Factory\StreamFactory`), restoring the exact "last call wins"
behavior Slim 2 had — without needing to touch `create.php` or
`SignUpController`.

## Verification

- `php -l` across the whole `server/` tree: 0 errors
- Boot + routing: confirmed Slim 4 dispatches to the right controller for
  both POST and GET routes, and that unmatched routes return a clean `404`
- Login, logout, and session-cookie handling across multiple requests
- Ticket creation — including the double-`respondSuccess()` case above,
  confirmed to return a single valid JSON object
- Ticket search, `get-stats`, `get-new-tickets` — all return correct data
- The raw-`header()`/`exit()` file download path (bypasses Slim's response
  object entirely on the success path) — still works for both a successful
  download and a body-less `403`
- Deployed to a real nested-subdirectory production host (where `server/`'s
  build output lives under `/api/` beneath the main domain) specifically to
  catch the base-path issue above, which did not reproduce in a
  webroot-at-`/`-style local test
- Re-ran every security regression test from `CHANGES.md` against the
  Slim-4 build specifically; all 6 fixes still hold
