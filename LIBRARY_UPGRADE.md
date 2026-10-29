# Dependency Upgrade

This fork upgrades every pinned dependency in `server/composer.json` that was
on an unmaintained major version, on top of the security and PHP 8.3 boot
fixes in [`CHANGES.md`](CHANGES.md). The Slim framework upgrade specifically
(2.x → 4.x) is documented separately in [`SLIM_UPGRADE.md`](SLIM_UPGRADE.md)
since it required rewriting the app's Slim integration.

## Why

`composer audit` against the **original, unmodified** `composer.lock`
reported 8 security advisories, including three high-severity PHPMailer
RCE/vulnerability CVEs (CVE-2021-34551, CVE-2021-3603, CVE-2020-13625).
These are independent of the 6 application-level bugs in `CHANGES.md` — they
live in the libraries themselves. `slim/slim` 2.x, `respect/validation` 1.x
and `phpmailer` 5.x are all unmaintained, so none of them will ever receive
a patch upstream. After this upgrade, `composer audit` reports no advisories.

## What changed

| Package | Before | After | Risk |
|---|---|---|---|
| `phpmailer/phpmailer` | 5.2.x | 6.12.0 | low — mostly a namespace change |
| `respect/validation` | 1.1.x | 2.5.0 | medium — real breaking changes, see below |
| `gabordemooij/redbean` | 5.4.x | 5.7.6 | low — patch release |
| `ezyang/htmlpurifier` | 4.8.x | 4.19.1 | low — minor release |
| `google/recaptcha` | ~1.1 | 1.3.1 | low — minor release |
| `ifsnop/mysqldump-php` | 2.x | 2.13 | low — minor release |
| `willdurand/email-reply-parser` | 2.8.x | 2.10.0 | low — patch release |
| `php-imap/php-imap` | 3.1.x | 6.0.0 | low in practice — see below |
| `codeguy/upload` | 1.3.x | 1.3.2 (unchanged) | already latest; flagged **abandoned** on Packagist, no CVE — see caveat below |

## Real bugs this upgrade surfaced (and fixed)

These weren't hypothetical — they were found by actually running the app and
driving it with real requests after each change, not by reading changelogs.

1. **respect/validation 2.x's `oneOf()` changed meaning.** In v1, `oneOf()`
   meant "at least one rule passes" (logical OR). In v2, `OneOf` means
   "*exactly* one rule passes" (true XOR). This broke login outright: the
   login validator is `oneOf(captcha(), nullType())`, and with no CAPTCHA
   configured, `captcha()` always returns `true` *and* `nullType()` also
   returns `true` for a `null` input — two passes, which the new strict
   `OneOf` rejects. Fixed by switching the 16 affected call sites to
   `anyOf()`, respect/validation 2.x's actual equivalent of v1's `oneOf()`.

2. **A custom validator (`libs/validations/validOrderBy.php`) had a latent
   missing `return`** on one code path — harmless under PHP's old loose
   typing, but a hard `TypeError` once respect/validation 2.x required an
   explicit `: bool` return type on every custom rule's `validate()` method
   (the `Validatable` interface declares one, and PHP won't let an
   implementing class silently drop it). Fixed alongside adding the return
   type to all 18 custom rules in `libs/validations/`.

3. The built-in rule `numeric()` was renamed to `numericVal()` in
   respect/validation 2.x. Renamed at the 7 call sites that used it.

4. `DataValidator::with('CustomValidations', true)` — the v1 API for
   registering the app's custom rule namespace — was removed entirely in
   v2. Replaced at all 51 call sites with
   `Respect\Validation\Factory::setDefaultInstance(Respect\Validation\Factory::getDefaultInstance()->withRuleNamespace('CustomValidations'));`

## php-imap 3 → 6

Despite the large major-version jump, the actual API surface used by
`controllers/system/email-polling.php` (the `Mailbox` constructor,
`searchMailbox()`, `getMail()`, `getMailHeader()`, `deleteMail()`,
`expungeDeletedMails()`, and the `IncomingMail`/`IncomingMailHeader`/
`IncomingMailAttachment` properties it reads) is unchanged across versions.
No code changes were needed. Verified by exercising the full
`email-polling` request path — including the `Mailbox` constructor and its
exception handling — up to the point of actually opening an IMAP connection
(no real IMAP server was available to test against, but the failure
observed there was a legitimate "can't connect" error, not a crash).

## Not changed, and why

`codeguy/upload` is marked **abandoned** on Packagist (no further releases;
the suggested replacement is `league/flysystem`). It was not swapped out:
`league/flysystem` is a storage abstraction layer (local/S3/FTP), not an
upload-handling library — it has no concept of validating an uploaded
file's mimetype or size, which `codeguy/upload` currently does for this app.
Replacing it would mean writing that validation logic from scratch, not
removing code. It's still on its latest released version and `composer
audit` reports no known CVE against it, so it was left in place.

## Verification

Same approach as `CHANGES.md`: real PHP 8.3.36 + MySQL 8.4, real HTTP
requests, not just `php -l`.

- `composer audit`: 0 advisories (was 8, including 3 high-severity CVEs, on
  the original `composer.lock`)
- `php -l` across the whole `server/` tree (app code + vendor, excluding
  `require-dev`-only PHPUnit internals never loaded by the running app):
  **0 errors**
- Login, ticket creation (including content that exercises HTMLPurifier
  sanitization), ticket search, `get-stats`, `get-new-tickets`, `get-logs`
  — all verified working correctly against a real database
- All of `CHANGES.md`'s security regression tests were re-run after this
  library upgrade to confirm none of the 6 fixes regressed
