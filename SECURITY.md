# Security Policy

PlotBird Analytics is a small, self-hosted application that stores visitor
statistics. Please treat the notes below as a description of what has been done
and what has not.

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.0.x   | Yes       |
| < 1.0   | No        |

Only the latest 1.0.x release receives fixes. Security fixes are not backported
to older commits or to modified forks.

## Reporting a vulnerability

Do **not** open a public issue, and do not describe the problem in a pull
request or a discussion. Use GitHub's private reporting channel:

1. Open the **Security** tab of this repository.
2. Click **Report a vulnerability**.
3. Describe the issue, the affected version, the configuration you tested and
   the smallest set of steps that reproduces it.

That channel (GitHub Security Advisories) is the only accepted way to submit a
report. If you are unsure whether something is a vulnerability, report it
privately anyway rather than in public.

Targets, not guarantees:

* first response within **72 hours**;
* fix released within **30 days** for high-severity issues.

Please give us reasonable time to release a fix before publishing anything
publicly. Credit in the advisory is offered unless you prefer to stay anonymous.

## In scope

* The collection endpoint: `api/collect.php` (tracking input handling, bot and
  rate-limit bypasses, injection into stored data).
* The statistics API: `api/stats.php` (authorisation, site scoping, parameter
  handling, data leakage between clients).
* The cron endpoint: `api/cron.php` (secret handling, unauthenticated actions,
  resource exhaustion).
* The installer: `install/index.php` (re-installation over a live instance,
  privilege escalation, information disclosure, session handling).
* The dashboard: authentication, authorisation between roles, and any dashboard
  route reachable by a client account.
* Session and CSRF handling (`core/Security.php` and its callers): session
  fixation, CSRF token bypass, cookie flags.
* The tracker script `assets/tracker.js` (DOM-based injection, unsafe handling
  of page data, cross-site script inclusion).

## Out of scope

* Missing security headers on static assets (images, fonts, CSS, JS delivered
  from `/assets/`).
* Findings that require an already-compromised administrator account, a
  malicious administrator, or direct filesystem/database access.
* Reports produced only by an automated scanner, with no demonstrated impact
  and no explanation of what an attacker gains.
* Denial of service by volume, load testing, or brute force without a
  demonstrated logic flaw — the project is explicitly designed for shared
  hosting and makes no availability promise.
* Vulnerabilities in the bundled third-party data or libraries. Report those to
  their upstream projects; see `LICENSE` for the list.
* Anything that depends on a configuration the documentation warns against
  (for example leaving `/install/` in place after setup, or exposing `config/`
  over HTTP).

## Operator hardening checklist

PlotBird cannot protect an installation that is deployed carelessly. After
installing, do at least the following:

* **Delete the `/install/` folder.** The wizard locks itself once an
  administrator exists, but removing the folder removes the risk entirely.
* **Keep `config/` unreadable over HTTP.** On Apache the bundled `.htaccess`
  files do this; on nginx add the `deny all` block from the README. Verify with
  a browser that `/config/config.php` does not download.
* **Serve the site over HTTPS only.** Session cookies and the tracking endpoint
  should never travel in clear text.
* **Use a long, random `cron_secret`.** Keep the default generated value, or
  replace it with at least 32 random characters. Anyone who knows the secret can
  run the maintenance job.
* **Restrict `trusted_proxies`.** Leave it empty unless PlotBird runs behind a
  reverse proxy you control. If it is set too broadly, clients can forge their
  address and country and evade the rate limiter.
* **Review the retention window.** The default is 365 days; lower it to the
  shortest period your reporting actually needs, so raw page views are deleted
  sooner.

## What has been reviewed

A review of the shipped code and of a running installation covered the
following. Every item is a property of the code, so it can be checked again:

* **SQL.** Prepared statements everywhere, no user data concatenated into a
  query.
* **CSRF.** A token on every one of the state-changing POST routes.
* **Sessions.** `HttpOnly`, `SameSite=Lax`, `Secure` when the request is HTTPS,
  and a regenerated id after sign-in.
* **Authorisation.** Every site-scoped request resolves the site through the
  session owner, so another account's site id answers 403.
* **Redirects.** `safeNext()` rejects targets outside the installation.
* **Cron.** The scheduler compares the shared secret with `hash_equals()`.
* **Static files.** The bundled `.htaccess` was checked against a live shared
  host: configuration, logs, templates, locales and migrations answer 403 and
  directory listing is off.

This is a review by the maintainer, not an independent audit, and it is not a
guarantee. See Maturity below.

## Maturity

This project is young and has **not** received an independent security audit.
The code follows the practices described in the README (prepared statements
everywhere, output escaped through `e()`, CSRF tokens on state-changing
requests, `password_hash()`/`password_verify()`, no cookies for visitors), but
that is a statement about intent and review, not a guarantee. Run it with
appropriate caution, especially on a public internet-facing host, and keep your
PHP, database and web server patched.
