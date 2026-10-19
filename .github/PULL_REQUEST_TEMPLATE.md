Do not open a public pull request for a security fix. Report it privately
through the repository's Security tab first, as described in
[SECURITY.md](../../SECURITY.md).

## What this changes

<!-- One paragraph. Describe the user-visible effect, or say clearly that this is an internal change. -->

## Why

<!-- The problem this solves, or a link to the issue it closes. -->

## Checklist

- [ ] PHP 8.0 compatible syntax only (no enums, no readonly properties, no `never` return type, no first-class callable syntax); `declare(strict_types=1);` present in new files.
- [ ] `php -l` is clean on every changed PHP file.
- [ ] The end-to-end suite `php tests/e2e.php` passes.
- [ ] If templates changed: the CSS is rebuilt (`npm run build:css`) and committed.
- [ ] New or changed strings are added to `locales/en.json`.
- [ ] No secrets, credentials, generated files or local environment files are committed.
- [ ] Screenshots attached for UI changes (before and after).
- [ ] I agree to the contribution terms in [CONTRIBUTING.md](../../CONTRIBUTING.md#licence-of-your-contribution).

## Notes for the reviewer

<!-- Anything you are unsure about, anything deliberately left out, and what you tested manually. -->
