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
- **Update channel:** [Plugin Update Checker][puc] v4 polling a metadata JSON
- **Automation:** a single GitHub Action builds, releases and syncs metadata

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
runs on every push to `main` under `src/goo1-mcp/**` (and via manual
**Run workflow**). For each run it:

1. Reads the version from the plugin header (the **single source of truth**).
2. Builds `goo1-mcp.zip` — copies `src/goo1-mcp/`, strips dev-only files
   (`CLAUDE.md`), and vendors the Plugin Update Checker (`PUC_VERSION`, pinned
   to a v4 release that provides `Puc_v4_Factory`).
3. Rewrites `distmeta/updater/goo1-mcp.json` via
   [`.github/scripts/update_meta.py`](.github/scripts/update_meta.py):
   `version`, `download_url` (→ the new Release asset), `last_updated`,
   `requires` / `requires_php` (from the header), `tested` (from `readme.txt`),
   and `changelog` (rendered from the readme's `== Changelog ==` section).
4. Commits the updated JSON back to `main` (`[skip ci]`, and the metadata lives
   under `distmeta/**` which is outside the trigger path — so it never loops).
5. Creates/updates the GitHub Release `v<version>` and uploads the ZIP asset.

### Cutting a release

Bump the version in **one** place and push:

```text
src/goo1-mcp/goo1-mcp.php   → Version:  +  GOO1_MCP_VERSION constant
src/goo1-mcp/readme.txt     → Stable tag:  +  a new == Changelog == entry
```

The version scheme is `1.<minor>.<YYMMDD>` (e.g. `1.3.260629`). Push to `main`
and the Action does the rest. The script emits a warning (without failing the
build) if the header version, the `GOO1_MCP_VERSION` constant and the readme
`Stable tag` ever drift apart.

> Requirements on the repo side: **Settings → Actions → General → Workflow
> permissions** set to *Read and write*, and `main` not blocking the bot's
> metadata commit (or supply a PAT if the branch is protected).

## Installing a plugin

1. Grab the latest `goo1-mcp.zip` from the [Releases][releases] page.
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the ZIP, install.
3. Activate it. From then on, updates appear automatically under
   **Dashboard → Updates** whenever a new release is published.

## Repository layout

```
.
├── .github/
│   ├── workflows/
│   │   └── release-plugin.yml      # build → release → sync metadata
│   └── scripts/
│       └── update_meta.py          # rewrites the updater JSON from header+readme
├── distmeta/
│   └── updater/
│       └── goo1-mcp.json           # update metadata PUC polls (icons/banners
│                                   # referenced from here can live alongside it)
├── src/
│   └── goo1-mcp/                   # the plugin source
│       ├── goo1-mcp.php            # main file (header version = source of truth)
│       ├── readme.txt              # WP readme + changelog
│       ├── uninstall.php
│       ├── mcp-server.json
│       ├── includes/               # auth, permissions, rate limiter, audit, oauth
│       ├── rest/                   # REST controllers (posts, users, db, mcp, …)
│       └── admin/                  # admin UI
├── LICENSE
└── README.md
```

The built ZIP and the bundled `plugin-update-checker/` library are produced by
the Action at release time and are **not** committed to the repo.

## Adding another plugin

The current workflow is scoped to `goo1-mcp`. To onboard a second plugin, drop
it under `src/<slug>/`, add a `distmeta/updater/<slug>.json`, and either copy
the workflow or parameterize it over a small plugin matrix — the build and the
metadata script are already slug-driven via their arguments.

## 🤝 Contributing

These are personal/commissioned plugins, but issues and pull requests are
welcome. Feel free to open an [issue][issues].

## 📝 License

[MIT](LICENSE) for the repository tooling. Individual plugins may carry their
own license header (`goo1-mcp`: `GPL-2.0-or-later`).

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
