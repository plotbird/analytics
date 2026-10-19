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

## Brand marks in the interface

The device, browser and operating-system lists show the marks of the products
they name. The artwork comes from **Simple Icons** (https://simpleicons.org),
released under **CC0 1.0 Universal** — a public-domain dedication, so no
attribution is required and none is implied here beyond this note.

What CC0 does **not** cover is the trademark itself. Each mark remains the
property of its owner and is used only to identify the product a row refers to:
no mark is altered, recoloured beyond a single inherited text colour, or used to
suggest an endorsement or a partnership. Products without a CC0 mark — Windows
and Microsoft Edge among them — are shown with a plain letter monogram instead.

Included marks: Chrome, Firefox, Safari, Opera, Brave, Vivaldi, macOS, iOS,
Linux, Ubuntu, Android, Samsung Internet.

## Brand marks in the interface: sources

The device, browser and operating-system lists show the marks of the products
they name. Three permissively licensed sets are used, and one mark is drawn here.

### browser-logos — MIT

https://github.com/alrra/browser-logos

Used for: Chrome, Edge, Firefox, Opera, Safari, Samsung Internet, Brave,
Vivaldi, UC Browser.

```
Copyright (c) Cătălin Mariș

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
```

### devicon — MIT

https://github.com/devicons/devicon

Used for: Android, Apple (shown for macOS and iOS), Linux, Ubuntu, Debian,
Fedora.

```
The MIT License (MIT)

Copyright (c) 2015 konpa

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
```

### Bootstrap Icons — MIT

https://github.com/twbs/icons

Used for the three device glyphs: a monitor, a phone and a tablet. These are
interface icons rather than brand marks, and they follow the text colour.

```
The MIT License (MIT)

Copyright (c) 2019-2024 The Bootstrap Authors

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
```

### The Windows mark — drawn for this project

https://github.com/plotbird/analytics

Simple Icons no longer carries Microsoft products, and the shape — four panes
with a gutter — is plain geometry rather than a copied drawing. It is drawn in
`assets/icons/brand/windows.svg`, in the colour Microsoft uses for it.

### Trademarks

None of the licences above grant trademark rights. Every mark remains the
property of its owner and is used only to identify the product a row refers to:
nothing is altered beyond being shown at a smaller size, and nothing suggests an
endorsement or a partnership. Products with no available mark keep a plain
letter monogram.
