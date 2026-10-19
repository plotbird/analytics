# Third-party notices

PlotBird Analytics itself is licensed under the **GNU Affero General Public
License, version 3 or later** — see [LICENSE](LICENSE). The components below
keep their own licences; they are redistributed here under the terms of those
licences, and this file collects the attributions they require.

---

## Bundled data

### IP geolocation database

- **File:** `data/geoip/dbip-country-lite.csv.gz`
- **Source:** [DB-IP Lite](https://db-ip.com), free country database
- **Licence:** Creative Commons Attribution 4.0 International (CC BY 4.0) —
  <https://creativecommons.org/licenses/by/4.0/>

The attribution required by that licence is displayed on the settings page of
the application. If you remove the database from your installation you may
remove the attribution with it.

### Country names

- **Files:** `locales/countries/*.json`
- **Source:** [i18n-iso-countries](https://github.com/michaelwittig/node-i18n-iso-countries)
- **Licence:** MIT

### Country flags

- **Files:** `assets/vendor/flags/*.svg`
- **Source:** [flag-icons](https://github.com/lipis/flag-icons)
- **Licence:** MIT

---

## Front-end libraries (`assets/vendor/`)

| Library | Licence | Project |
|---|---|---|
| Alpine.js | MIT | <https://alpinejs.dev> |
| Chart.js | MIT | <https://www.chartjs.org> |
| jsVectorMap | MIT | <https://jvm-docs.vercel.app> |
| Sortable | MIT | <https://sortablejs.github.io/Sortable/> |
| Inter (font) | SIL Open Font License 1.1 | <https://rsms.me/inter/> |

Tailwind CSS is not redistributed: the project ships CSS that was compiled from
Tailwind's own source at release time (`assets/css/tailwind.css`), and Tailwind
itself is MIT — <https://tailwindcss.com>.

---

## Build tooling (not redistributed)

`tailwindcss` is a development dependency in `package.json`. It is never needed
on a server and no part of it is shipped in a release archive.
