# Contributing

Thanks for considering a contribution. PlotBird is a small project; the notes
below describe how to build locally and what a patch is expected to look like.

## Local setup (no Composer)

There are no dependencies to install. The application is plain PHP and the
front-end libraries are already vendored in `assets/vendor/`.

1. Copy the repository into a folder served by your web server, or use PHP's
   built-in server (PHP 8.0 or newer):

   ```bash
   php -S 127.0.0.1:8080 tests/router.php
   ```

   `tests/router.php` mirrors the routing rules of the shipped `.htaccess`, so
   pretty URLs and `api/*.php` behave the same locally as on Apache.

2. Open `http://127.0.0.1:8080/` — you are redirected to the 7-step installer.

3. You need a MySQL 5.7+ or MariaDB 10.2+ database. Credentials are only stored
   in the generated `config/config.php`, which is git-ignored.

The end-to-end suite in `tests/` does all of the above for you (install, track
hits, render every page) against a throw-away database. See "Before opening a
pull request" below.

## Coding standards

* **PHP 8.0 compatible syntax only.** Newer syntax is not allowed even if your
  local PHP accepts it: no `enum`, no `readonly` properties, no `never` return
  type, no first-class callable syntax (`strlen(...)`), no `array_is_list()`,
  no constructor property promotion edge cases beyond what 8.0 supports.
* `declare(strict_types=1);` at the top of every PHP file.
* Four spaces for indentation, no tabs. PSR-12 is the reference; consistency
  with the surrounding file wins over a strict reading of it.
* Comments and docblocks in English. Explain *why* a non-obvious decision was
  made, not what the next line does.
* Every database query goes through a PDO prepared statement. No string
  concatenation of values into SQL, including integers and identifiers.
* Every piece of output that reaches HTML is escaped with `e()`. Do not echo
  raw values, not even "safe" ones from the database.
* Every state-changing request (POST, and any GET that mutates state) is
  protected by a CSRF token and validated with `Security::requireCsrf()`.
* No new third-party dependencies. If something genuinely needs a library
  discuss it in an issue first.

## Adding a translation

Locale files live in `locales/`. Each is a JSON object whose **keys** are the
translation keys used by `I18n::translate()`; nested groups map to dotted keys
(`{"nav": {"dashboard": "..."}}` becomes `nav.dashboard`).

1. Copy `locales/en.json` to `locales/<code>.json` (for example `locales/it.json`).
2. Translate the **values only**. Do not rename, add or remove keys, and do not
   change the nesting. Keys missing from a translation fall back to English, but
   a mismatched key is a bug.
3. Keep the locale metadata in `core/i18n.php` in mind: a new locale must also
   be listed in `I18n::available()` to show up in the switcher.
4. Verify that both files have the same number of keys with:

   ```bash
   node -e "const K=o=>Object.entries(o).reduce((n,[k,v])=>n+(v&&typeof v==='object'&&!Array.isArray(v)?K(v):1),0);const r=f=>K(JSON.parse(require('fs').readFileSync(f,'utf8')));console.log('en.json',r('locales/en.json'),'new.json',r('locales/it.json'))"
   ```

   Both numbers must be identical. (The command counts keys at every nesting
   level; values that are arrays of plural forms count as one key, which is what
   you want.)

## Adding a dashboard widget

Three places are involved, and they must stay in agreement:

1. `core/Widgets.php` — the widget catalogue (`CATALOG`): id, title key, grid
   size (`full`, `half`, `third`) and whether it is visible by default.
2. `core/StatsPayload.php` — the payload builder: add whatever data the widget
   needs, so the server-rendered dashboard and `api/stats.php` return the same
   numbers.
3. `templates/partials/widget_<id>.php` — the markup. Add the matching title
   and label keys to `locales/en.json` (and, ideally, to the other locales).

The dashboard grid, the "customise widgets" modal and layout validation all read
the catalogue, so a widget that is only added to a template will not render.

Then rebuild the CSS (`npm run build:css`) if the markup uses classes that are
not used anywhere else yet — with a compiled stylesheet an unknown class renders
as nothing at all. The end-to-end suite catches that: it compares every class in
the rendered pages against the compiled file.

## Adding a goal type

Goals live in `core/Goal.php` (validation and matching) and are rolled up by
`Aggregator::aggregateGoals()`. A new type needs a branch there, a badge in
`templates/partials/widget_goals.php`, and a test in `tests/e2e.php` — the
existing page and event goals show the shape.

## Before opening a pull request

* Run `php -l` over every PHP file you changed:

  ```bash
  php -l core/StatsPayload.php
  ```

* Run the end-to-end suite. By default it drives the real entry points over HTTP
  (`php -S` plus curl, the same path CI uses):

  ```bash
  php tests/e2e.php
  ```

  If binding a local port is not possible in your environment (some sandboxes
  forbid it), run the same suite through php-cgi instead:

  ```bash
  PB_TEST_TRANSPORT=cgi php tests/e2e.php
  ```

  Both transports execute the same assertions; keep them both green. The IP
  database import is the slow part and is opt-in:

  ```bash
  php tests/e2e.php --with-geoip
  ```

  The suite must pass completely. If your change alters behaviour it asserts,
  update the assertion in the same commit and explain why in the pull request.

* If you touched the tracker, remember it is minified by hand: keep
  `assets/tracker.src.js` readable and `assets/tracker.js` in sync.

## Styling

Tailwind is compiled ahead of time: the pages load `assets/css/tailwind.css`
(about 43 KB of CSS) and never a runtime compiler. After changing classes in
`templates/`, `install/views/` or `assets/js/`:

```bash
npm install
npm run build:css
```

Commit the regenerated `assets/css/tailwind.css` with your change. The reusable
component classes (`.pb-card`, `.pb-btn`, `.pb-input`, ...) live in
`assets/css/tailwind.src.css`; everything else is utility classes in the
templates. `npm` is only needed for this build - the server never runs Node.

## Logo and icons

The mark is a leaping bird built from three bands, drawn in a 64×64 grid. Its
geometry lives in **`scripts/bird-geometry.php`** as closed Catmull-Rom
contours; the last contour is the bird's eye, which is punched out with the
even-odd fill rule so it stays transparent instead of being painted white.

That one file feeds everything:

* `assets/img/logo.svg` - the bare mark: brand blue on a transparent
  background. Used as the favicon and as the header mark, so it has to work on
  both a light and a dark surface.
* `assets/img/logo-lockup.svg` - mark plus the two-tone "**Plot**Bird" wordmark,
  for the README. The wordmark is real text with a font stack: inside an `<img>`
  a browser cannot load a webfont, so the viewer's sans-serif decides the shape.
  Do not use it as a print asset.
* the favicon and PWA PNGs, generated by a plain GD script - no image editor and
  no Node:

```bash
php scripts/make-icons.php
```

`icon-192.png` and `icon-512.png` keep the transparent background. Two files are
opaque on purpose, and that is not a style choice: `maskable-512.png` is cropped
by the launcher, so a transparent one would show the launcher background through
the safe zone, and iOS composites a transparent touch icon onto black.

Change the geometry once in `bird-geometry.php` and re-run the script; keep the
mark legible at 16 px, because that is the size a favicon is actually seen at.

## Pull requests

* Keep them small and focused: one topic per pull request. A refactor plus a
  feature is two pull requests.
* Describe the user-visible change: what an operator or visitor sees differently
  after the patch. Internal cleanups should say so explicitly.
* Attach a screenshot (or a short recording) for any change to the dashboard,
  installer or settings UI — before and after is ideal.
* Do not commit generated or local files: `config/config.php`,
  `config/install.lock`, anything under `data/logs/`, `data/cache/`,
  `data/ratelimit/` or `data/geoip/*.csv`, and nothing from `.dev/` or `node_modules/`.
* Do not commit secrets, credentials, database dumps or real visitor data.
* Fill in the pull request template; a reviewer should not have to guess what
  was tested.

## Licence of your contribution

PlotBird is distributed under the **AGPL-3.0-or-later** and is also available
under a commercial licence — see [LICENSING.md](LICENSING.md). For both to exist
at the same time:

* by submitting a pull request you agree that your contribution is licensed under
  the AGPL-3.0-or-later;
* you grant the maintainer a perpetual, worldwide, non-exclusive, royalty-free
  right to also license your contribution on other terms, including a commercial
  licence.

You keep the copyright on your work. If that is not acceptable to you, open an
issue instead of a pull request: a bug report or a discussion needs no licence
grant.

Please do not paste code you did not write. If a change includes a snippet from
elsewhere, its licence has to be compatible with the AGPL and its attribution
belongs in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
