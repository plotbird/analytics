# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-10-19

### Fixed

* **Installations in a sub-directory produced doubled paths.** With the
  application in `/plotbird/`, every generated link pointed at
  `/plotbird/plotbird/...`: the installer redirect looped, stylesheets and
  scripts returned 404, and the wizard was unusable. The base URL carried the
  folder and the base path was appended to it again; the base URL now holds the
  scheme and host only, and the folder comes from one place.
* The default address of the project repository pointed at a location that no
  longer exists.

### Added

* `tests/urls.php` checks generated links for both layouts - document root and
  sub-directory - and runs in CI after the end-to-end suite, so a regression in
  URL building fails the build instead of the installation.

## [1.0.1] - 2026-10-19

Hardening found by a security review of the shipped code and of a live
installation.

### Fixed

* Translation files were opened through a path built from the locale. Only the
  locale setter could introduce a value, and it rejects anything unknown, so the
  directory could not be escaped — but the guarantee lived one call away from the
  filesystem code. The file name is now reduced with `basename()` and has to look
  like a language tag before it is opened.
* `X-Powered-By: PHP/8.2.34` was sent with every response, handing the exact
  interpreter version to anyone who asked. Removed in PHP for everything the
  application renders, in `.htaccess` for static files, and in the documented
  nginx configuration.

### Added

* Four authorisation and input-limit checks in the end-to-end suite: a client
  cannot create a share link for a website it cannot see, a client cannot create
  a goal, a revoked token no longer authorises the API, and the tracker refuses a
  body larger than it accepts.
* Weekly Dependabot updates for the workflow actions and the single build
  dependency.

## [1.0.0] - 2026-10-17

First public release.

### Added

* Seven-step browser installer: language, requirements check, database
  credentials, schema and `config.php` generation, bundled IP database import,
  administrator account, first website with tracking code, finish.
* Cookie-free tracking: visitors are counted through a salted daily
  `md5(IP + User-Agent + salt + date)` pseudonym, with no cookies, no
  `localStorage` and no device fingerprinting on the visitor's side.
* Tracker script: under 2 KB, one request per page view, `sendBeacon` with a
  `fetch` fallback, SPA route-change handling, bot filtering, file-based rate
  limiting, per-site include/exclude rules and a custom domains allow-list.
* Dashboard with a drag & drop widget grid: real-time counter (5 minutes),
  overview cards with previous-period comparison, traffic chart by day or hour,
  top pages, traffic sources and entry pages, countries with an interactive
  world map, and devices / OS / browsers. Widget visibility is remembered per
  website, and websites and periods switch over AJAX.
* Accounts and roles: super administrator with full access, and client accounts
  limited to the websites assigned to them, switchable in the header.
* Goals (conversions): a goal counts visitors who reached a specific page
  (exact path or a `/prefix/*` wildcard, no website changes needed) or who fired
  a named event through the new `plotbird.track('signup')` tracker API. The
  dashboard shows converting visitors, the conversion rate against all visitors
  and the change against the previous period. Events are stored separately from
  page views, so they never inflate page or session counts.
* Visitor log: one row per session with its start time in the website's time
  zone, duration, page count, entry and exit page, source, country, device and
  the pages of the session on expand. IP addresses are shown only when the
  operator explicitly enables storing them.
* Optional IP storage: off by default; when enabled, the visitor log shows the
  address of every session, and every toggle is written to the activity log.
* Installable as a PWA (web app manifest, service worker caching static assets
  only, raster icons) with an "Install app" entry in the account menu.
* Schema migrations: plain SQL files applied from the settings page or by the
  cron endpoint, so the project can be upgraded over FTP without a shell. A
  fresh installation baselines the shipped migrations.
* Public read-only share links (`/share/<token>`) with optional expiry and
  one-click revocation; only a SHA-256 hash of the token is stored.
* Privacy controls: IP anonymisation (the network part is truncated before
  hashing — IPv4 `/24`, IPv6 `/48`), Do-Not-Track support globally or per
  website, a configurable raw-data retention window (365 days by default) and
  per-site country-only geolocation.
* GeoIP out of the box: the DB-IP Lite country database is bundled in
  `data/geoip/` and imported by the installer in chunks, with an optional
  download of a newer database and attribution displayed on the settings page.
* Six languages — English, Русский, Deutsch, Español, Français, 中文 — with
  automatic browser-language detection and a switcher throughout the interface.
* Cron endpoint (`api/cron.php?secret=...`) that refreshes the daily rollups,
  deletes raw data past the retention window and keeps the IP database current.
  Without cron the rollups are computed lazily on the first dashboard load of
  the day.
* Security baseline: PDO prepared statements only, CSRF tokens on every form,
  `password_hash()` / `password_verify()`, session fingerprinting, rate-limited
  sign-in with lockout after five failures, protected `config/`, `core/`,
  `data/`, `locales/` and `templates/` directories, a self-locking installer,
  security headers, and an audit log.
* Daily rollups so long history stays fast on cheap shared hosting.
