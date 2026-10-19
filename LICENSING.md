# Licensing

PlotBird Analytics is **free software under the GNU Affero General Public
License, version 3 or later** ([LICENSE](LICENSE)). You can run it, study it,
modify it, redistribute it and even sell services around it — as long as you
respect the AGPL.

This file explains the one situation where the AGPL asks something extra of
you, and what to do about it.

## What the AGPL requires

The AGPL is the GPL plus one clause (§13, "Remote Network Interaction"). In
practice, for this project:

| You want to | What the AGPL asks |
|---|---|
| Install PlotBird on your own website or intranet | Nothing extra. Use it freely, keep your copy private if you like. |
| Modify it for yourself | Nothing extra, as long as you do not give the modified program to others as a network service. |
| Distribute it, modified or not | Keep the licence, publish the source of your changes. |
| Offer it to third parties as a hosted service (SaaS, agency panel, "analytics as a feature" of your product) | **Offer those users the complete corresponding source code of the version you run** — including your modifications. |

That last row is the whole point: nobody gets to take this project, close their
changes and sell it as a service without giving the source back.

## Commercial licensing

If the AGPL does not fit what you need to do — typically because you want to
offer PlotBird as a closed hosted service, embed it in a proprietary product, or
ship it with modifications you cannot publish — a **separate commercial licence**
is available from the copyright holder. It replaces the AGPL for your
organisation and removes the source-disclosure obligation.

Write to the address on the repository's public profile to discuss it. Please
describe your use case and expected scale; there is nothing to sign for
self-hosting under the AGPL.

## Why the project is not MIT

MIT would let anyone take the code, keep their changes private and compete with
the project as a hosted service, contributing nothing back. The AGPL keeps the
project genuinely open while leaving a commercial path for organisations that
need one. This is the same model Plausible uses.

## Contributions

Contributions are accepted under the terms in [CONTRIBUTING.md](CONTRIBUTING.md):
by submitting a change you agree to it being distributed under the AGPL, and you
grant the maintainer the right to also license your contribution commercially.
That is what keeps the dual-licensing model possible; without it, a single
outside contributor would be enough to make commercial licensing impossible.

## Trademark

The AGPL covers **the code**. It does not grant any right to the name
"PlotBird Analytics" or the logo (`assets/img/logo.svg`).

Forks are welcome and explicitly allowed — but a fork must be renamed and must
not present itself as the upstream project. You may state, factually, that your
project is "based on PlotBird Analytics".

## Third-party components

Bundled data and libraries keep their own licences and are listed in
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md). In particular, the IP
geolocation database is CC BY 4.0 and its attribution is shown in the
application's settings page.
