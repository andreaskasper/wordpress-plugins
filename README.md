# 🔌 wordpress-plugins

Self-hosted WordPress plugins by Andreas Kasper, with **built-in automatic
updates straight from GitHub** — no wordpress.org listing required. Each plugin
embeds the [Plugin Update Checker][puc] and polls a small metadata JSON in this
repo; a GitHub Action builds the distributable ZIP, publishes it as a Release
asset and keeps that JSON in sync.

### Status & Stats

![Last Commit](https://img.shields.io/github/last-commit/andreaskasper/wordpress-plugins.svg)
![Commit Activity](https://img.shields.io/github/commit-activity/m/andreaskasper/wordpress-plugins.svg)
[![Issues](https://img.shields.io/github/issues/andreaskasper/wordpress-plugins.svg)](https://github.com/andreaskasper/wordpress-plugins/issues)
![Repo Size](https://img.shields.io/github/repo-size/andreaskasper/wordpress-plugins.svg)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-blue.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-%3E%3D6.0-21759b.svg)](https://wordpress.org)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
![Stars](https://img.shields.io/github/stars/andreaskasper/wordpress-plugins.svg?style=social)

---

These plugins are personalized builds for the goo1 projects and are **not**
published in the official WordPress plugin directory. They are distributed and
updated directly from this repository, so every site running one of them gets
update notifications in its own **Dashboard → Updates** screen, exactly like a
wp.org plugin would.

- **Language:** PHP (WordPress plugin APIs)
- **Distribution:** GitHub Releases (ZIP asset per version)
- **Update channel:** [Plugin Update Checker][puc] (v4 or v5, per plugin) polling a metadata JSON
- **Automation:** one GitHub Action builds, releases and syncs metadata for every plugin listed in `.github/plugins.json`

## Plugins

### goo1 MCP WP Claude Bridge — `src/goo1-mcp/`

Exposes WordPress functionality via a secure REST API so that **Claude AI** can
manage the site, and ships a native **remote MCP connector** (OAuth 2.1 + PKCE)
for Claude Desktop / claude.ai.

**Highlights:**
- Full CRUD for posts, pages, custom post types, taxonomies, media, comments,
  users, and navigation menus
- Plugin/theme management, options read/write (with sensitive-option filtering)
- Direct SQL execution with safety controls + database schema introspection
- Site-health dashboard, PHP error-log reader, cron and hook introspection,
  transient management
- WooCommerce support (products, orders, customers, inventory, sales reports)
- Bearer-token auth with scoped API keys, per-key rate limiting, full audit log
- **OAuth 2.1** authorization server (discovery metadata, authorization code +
  PKCE, refresh tokens, Dynamic Client Registration)
- Native **MCP JSON-RPC** transport endpoint (`/wp-json/goo1-mcp/v1/mcp`)

See [`src/goo1-mcp/readme.txt`](src/goo1-mcp/readme.txt) for the full feature
list and changelog.

> License note: this repository is MIT (see [`LICENSE`](LICENSE)), but the
> `goo1-mcp` plugin itself carries a `GPL-2.0-or-later` header, as is customary
> for WordPress plugins. MIT is GPL-compatible, so the bundled distribution
> stays consistent.

### PrivacyPilot — `src/goo1-privacypilot/`

Privacy policy, cookie policy, terms of use, "Your Privacy Choices" and imprint
for US and EU/UK sites, in English and German, generated from a questionnaire
and a centrally maintained clause library — a self-hosted replacement for
services like Termageddon. Admin under **Settings → PrivacyPilot**.

**Highlights:**
- Clause library in [`src/goo1-privacypilot-library/`](src/goo1-privacypilot-library)
  (Markdown clauses with conditions), shipped inside every ZIP and distributed
  as an **Ed25519-signed feed** (`distmeta/privacypilot/library.json`)
- Approval workflow: editorial changes publish automatically, material changes
  wait for approval (preview + clause diff), optional publication on the
  effective date of a legal change
- **Hub mode:** one site keeps the answers per legal entity and approves,
  connected sites follow
- Consent banner: opt-in for EU/EEA/UK/CH, opt-out with "Do Not Sell or Share"
  for the US (country via Cloudflare), Global Privacy Control, Google Consent
  Mode v2, blocking of known scripts and embeds (Elementor video widgets too)
- Scanner, browser cookie audit, request form with deadlines, consent log
  without IP addresses, Site Health tests, Termageddon migration
- Admin in English and German

PrivacyPilot is not legal advice. See
[`src/goo1-privacypilot/readme.txt`](src/goo1-privacypilot/readme.txt).

## How updates work

Each plugin's main file registers the update checker against a metadata JSON
served raw from this repo's `main` branch:

```php
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
    "https://raw.githubusercontent.com/andreaskasper/wordpress-plugins/main/distmeta/updater/goo1-mcp.json",
    __FILE__,
    'goo1-mcp'
);
```

PrivacyPilot uses PUC v5 the same way (`YahnisElsts\PluginUpdateChecker\v5\PucFactory`)
with `distmeta/updater/goo1-privacypilot.json`.

The flow end to end:

```
plugin (PUC) ──poll every ~12h──▶ distmeta/updater/goo1-mcp.json   (raw on main)
      │                                   │
      │   JSON.version > installed?        │ version, download_url, changelog …
      ▼                                   ▼
  download_url ───────────────▶ GitHub Release asset  v<version>/goo1-mcp.zip
      │
      ▼
  WordPress installs the new ZIP into wp-content/plugins/goo1-mcp/
```

PUC compares the `version` field in the JSON against the installed plugin's
header version. If the JSON is newer, WordPress offers the update and pulls the
ZIP from `download_url` (a Release asset). The ZIP always contains a single
top-level `goo1-mcp/` folder and bundles the Plugin Update Checker library, so
it is fully self-contained.

## Release automation

[`.github/workflows/release-plugin.yml`](.github/workflows/release-plugin.yml)
runs on every push to `main` that touches `src/**` or `.github/plugins.json` (and
via manual **Run workflow**, optionally for one plugin). A `plan` job finds the
changed plugins; a matrix job then releases them one after another. The
per-plugin settings live in [`.github/plugins.json`](.github/plugins.json):
source dir, PUC tag, version constant, files to strip and, for PrivacyPilot,
the clause library. For each plugin it:

1. Reads the version from the plugin header (the **single source of truth**).
2. For PrivacyPilot: builds the clause library with
   [`build_library.py`](.github/scripts/build_library.py) (validates every
   clause, question and service pattern), signs it with
   [`sign_library.php`](.github/scripts/sign_library.php) using the secret
   `PRIVACYPILOT_SIGNING_KEY`, bundles it into the ZIP and publishes it as the
   feed under `distmeta/privacypilot/`. Without the secret the feed is not
   updated (the bundled copy still ships).
3. Builds `<slug>.zip` — copies the source, strips dev-only files (`CLAUDE.md`,
   `tests/`), and vendors the pinned Plugin Update Checker.
4. Rewrites `distmeta/updater/<slug>.json` via
   [`update_meta.py`](.github/scripts/update_meta.py): `version`,
   `download_url`, `last_updated`, `requires` / `requires_php`, `tested` and
   `changelog` (from the readme's `== Changelog ==`).
5. Commits the metadata back to `main` (`[skip ci]`, with rebase-and-retry so
   parallel releases do not collide).
6. Creates/updates the GitHub Release and uploads the ZIP. Tags: `v<version>`
   for goo1-mcp, `<slug>-v<version>` for every other plugin.

### Cutting a release

Bump the version in the plugin header, the version constant and the readme
`Stable tag`, add a changelog entry, push to `main`. The script warns (without
failing) if the three drift apart.

> Requirements on the repo side: **Settings → Actions → General → Workflow
> permissions** set to *Read and write*, `main` not blocking the bot's
> metadata commit, and for PrivacyPilot the Actions secret
> `PRIVACYPILOT_SIGNING_KEY` (Ed25519 secret key; the public half is in
> `src/goo1-privacypilot/includes/class-signature.php`).

## Installing a plugin

1. Grab the latest `goo1-mcp.zip` from the [Releases][releases] page.
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the ZIP, install.
3. Activate it. From then on, updates appear automatically under
   **Dashboard → Updates** whenever a new release is published.

## Repository layout

```
.
├── .github/
│   ├── plugins.json                # per-plugin release settings
│   ├── workflows/
│   │   └── release-plugin.yml      # plan → build → release → sync metadata
│   └── scripts/
│       ├── update_meta.py          # rewrites the updater JSON from header+readme
│       ├── build_library.py        # PrivacyPilot clause library → library.json
│       ├── sign_library.php        # Ed25519 keygen / sign / verify
│       ├── build_local.sh          # build a plugin ZIP locally like the Action
│       └── i18n.py                 # PrivacyPilot .pot/.po/.mo
├── distmeta/
│   ├── updater/                    # update metadata PUC polls, one JSON per plugin
│   └── privacypilot/               # signed clause library feed
├── src/
│   ├── goo1-mcp/                   # goo1 MCP WP Claude Bridge
│   ├── goo1-privacypilot/          # PrivacyPilot plugin
│   └── goo1-privacypilot-library/  # PrivacyPilot clauses, questions, services
├── LICENSE
└── README.md
```

The built ZIPs and the bundled `plugin-update-checker/` library are produced by
the Action at release time and are **not** committed to the repo.

## Adding another plugin

Drop it under `src/<slug>/`, add an entry to `.github/plugins.json` and a
`distmeta/updater/<slug>.json`, and point the plugin's update checker at that
JSON.

## 🤝 Contributing

These are personal/commissioned plugins, but issues and pull requests are
welcome. Feel free to open an [issue][issues].

## 📝 License

[MIT](LICENSE) for the repository tooling. Individual plugins may carry their
own license header (`goo1-mcp`, `goo1-privacypilot`: `GPL-2.0-or-later`).

## 💰 Support the project

If this saves you time, consider supporting development:

[![donate via Patreon](https://img.shields.io/badge/Donate-Patreon-green.svg)](https://www.patreon.com/AndreasKasper)
[![donate via PayPal](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.me/AndreasKasper)
[![donate via Ko-fi](https://img.shields.io/badge/Donate-Ko--fi-green.svg)](https://ko-fi.com/andreaskasper)
[![Sponsors](https://img.shields.io/github/sponsors/andreaskasper)](https://github.com/sponsors/andreaskasper)

---

**Made with ❤️ by [Andreas Kasper](https://github.com/andreaskasper)**

[puc]: https://github.com/YahnisElsts/plugin-update-checker
[releases]: https://github.com/andreaskasper/wordpress-plugins/releases
[issues]: https://github.com/andreaskasper/wordpress-plugins/issues
