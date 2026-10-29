# Security Fixes & PHP 8.3 Boot Fixes

This document covers the 6 application-level security fixes and the PHP 8.3
compatibility fixes applied to OpenSupports v4.11.0 in this fork. For the
dependency/library upgrade (Slim, PHPMailer, respect/validation, php-imap,
etc.), see [`LIBRARY_UPGRADE.md`](LIBRARY_UPGRADE.md) and
[`SLIM_UPGRADE.md`](SLIM_UPGRADE.md).

All paths below are relative to `server/`.

## Security fixes

1. **Unauthenticated SSRF via `/system/test-imap`** (`controllers/system/test-imap.php`)
   `permission` was `any` — any unauthenticated request could make the server
   call `imap_open()` with attacker-controlled host/user/pass, enabling SSRF
   against internal network hosts and, on vulnerable PHP/imap builds,
   potential RCE (CVE-2018-19518-class issue). Changed to `staff_3`, matching
   `edit-settings.php` (the controller that actually stores IMAP credentials).

2. **Unauthenticated SSRF via `/system/test-smtp`** (`controllers/system/test-smtp.php`)
   Same issue, same fix: `permission` changed from `any` to `staff_3`.

3. **SQL injection in `/staff/get-new-tickets`** (`controllers/staff/get-new-tickets.php`)
   `departmentId` was concatenated directly into the SQL string
   (`'AND department_id=' . $departmentId`), while the sibling
   `get-tickets.php` controller already used parameterized queries for the
   same parameter — a 1-of-N inconsistency. Rewritten to use `?`
   placeholders with a bindings array for both the `Ticket::count()` and
   `Ticket::find()` calls.

4. **SQL string interpolation in `/system/get-stats`** (`controllers/system/stats.php`)
   Date range, department, tag, and owner IDs were interpolated into SQL
   without casting. All are now cast with `(int)` before being placed in
   the query string.

5. **Cross-department ticket search bypass in `/ticket/search`** (`controllers/ticket/search.php`)
   The `supervisor` request parameter let *any* `staff_1` user disable the
   department filter entirely (`$this->ignoreDeparmentFilter = (bool)Controller::request('supervisor')`),
   exposing tickets from departments they don't belong to. Now also gated on
   `$loggedUser->level >= 2`, so only staff with manager/supervisor level can
   use it. As defense-in-depth, every department/tag/owner/author/date ID
   concatenated into the raw SQL filters in this controller is now cast with
   `(int)` as well — the existing `validTagsId`/`validDepartmentsId`/
   `validOwnersId` custom validators only confirm that *a* record with that
   ID exists, via a parameterized lookup; they don't guarantee the JSON-decoded
   value itself is a clean integer before it's reused in string concatenation.

6. **Unauthorized attachment download (IDOR) in `/system/download`** (`controllers/system/download.php`)
   For a logged-in staff member, the controller previously did **no
   permission check at all** — `isNotDepartmentOwner()` existed in the class
   but was never called. Any authenticated staff user could download any
   ticket's attachments regardless of department. Now checks
   `Staff::canManageTicket()` (the same ownership check used elsewhere in the
   codebase) for staff users; the dead `isNotDepartmentOwner()` method was
   removed.

## PHP 8.3 boot fixes

PHP 8.3 on the original v4.11.0 code failed on essentially every request,
for two independent reasons:

1. **`get_magic_quotes_gpc()` removed in PHP 8.0.** `vendor/slim/slim`
   2.x's `Util::stripSlashesIfMagicQuotes()` called it unconditionally; it's
   used by Slim's `Request` class on nearly every request that reads
   `$_GET`/`$_POST`. Fatal "call to undefined function" on every API call.
   Resolved as part of the Slim 4 upgrade — see `SLIM_UPGRADE.md`.

2. **Slim 2.x converts *any* matching PHP error into a thrown exception.**
   `Slim::handleErrors()` throws for every error that matches
   `error_reporting()`, including `E_DEPRECATED`/`E_WARNING` noise the
   pre-upgrade dependencies (PHPMailer 5.x, respect/validation 1.x)
   routinely produce under PHP 8.3. `server/index.php` sets
   `error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)`
   and `display_errors = 0` at bootstrap so only genuine errors are
   escalated. (Also still relevant after the Slim 4 upgrade, since the
   underlying dependencies still emit this noise.)

## Verification

No automated test suite covers these controllers, so every fix was verified
by actually running the app (PHP 8.3.36, MySQL 8.4) and driving it with real
HTTP requests, side-by-side against the unpatched code where practical:

- SSRF: unpatched build let an anonymous request reach the real
  `imap_open()`/SMTP connection attempt; patched build returns
  `NO_PERMISSION` for the same anonymous request, and still works for an
  authenticated `staff_3` admin.
- Cross-department search bypass: a level-1 staff member assigned only to
  one department, sending `supervisor=1`, saw tickets from an unrelated
  department on the unpatched build; only their own department's tickets on
  the patched build. A level-2 account with the same request still
  correctly sees tickets across departments.
- Attachment IDOR: the same cross-department download request returned
  `200 OK` with the file's contents on the unpatched build, `403 Forbidden`
  on the patched build.
- SQL injection: a `departmentId` payload designed to break out of the
  string concatenation produced a raw MySQL syntax error on the unpatched
  build (proof the value was interpolated unescaped) and was handled safely
  as ordinary bound data on the patched build.
