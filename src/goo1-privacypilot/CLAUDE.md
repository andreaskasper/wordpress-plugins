# CLAUDE.md

Guidance for Claude Code when working on **PrivacyPilot** (`goo1-privacypilot`). Not shipped in the release ZIP.

## What it is

Replaces hosted policy services (Termageddon) on goo1-managed WordPress sites: privacy policy, cookie policy, terms, "Your Privacy Choices" (with request form) and imprint, EN + DE, built from a questionnaire and a central, signed clause library. Plus a consent banner with geo rules, script/embed blocking, Google Consent Mode v2, GPC, consent log, scanner, cookie audit, Site Health tests, hub/client mode and a Termageddon migration. Pure PHP + vanilla JS, no build step. PHP ≥ 7.4, WP ≥ 6.0. Admin UI lives under **Settings → PrivacyPilot** (never a top-level menu).

PrivacyPilot is not legal advice; the disclaimer is shown in the admin only.

## Layout

- `goo1-privacypilot.php` – header (version = source of truth, plus `GOO1_PP_VERSION`), requires, PUC v5 bootstrap, `goo1_pp_asset_ver()` (version + filemtime so Cloudflare never serves stale JS/CSS).
- `includes/`
  - `class-settings.php` – option `goo1_pp_settings`, defaults, `FEED_URL`.
  - `class-library.php` – bundled `library/library.json` (built at release) vs feed (option `goo1_pp_library`); `refresh()` verifies the Ed25519 signature, schema 1, and only accepts newer versions.
  - `class-signature.php` – `PUBLIC_KEYS` (key 1 created 2026-11-10). WP core ships sodium_compat, so verification works without ext-sodium.
  - `class-conditions.php` – safe expression parser for `when:` (and/or/not/==/!=/in, dotted paths). Never `eval`.
  - `class-markdown.php` – small Markdown → HTML; block helpers `{{#name:arg}}` on their own line stay raw.
  - `class-questionnaire.php` – entity answers (standalone option / hub profile / client cache) and site answers; `context()` adds `pp.*` (banner, gpc, us_optout, languages, documents, consent_days); `warnings()` lists contradictions.
  - `class-generator.php` – renders a document: clauses whose `when` matches, placeholders `{{entity.name}}`, `{{url.doc}}`, `{{link.doc}}`, blocks `services_table`, `services_list`, `cookie_table`, `request_form`, `settings_link`. Returns html, hash, manifest (clause id → hash@version), changes, effective date, missing placeholders.
  - `class-versions.php` (table `goo1_pp_versions`), `class-publisher.php` (sync/classify/approve), `class-documents.php` (shortcodes, block, pages, core privacy page).
  - `class-consent.php` (banner config, Consent Mode defaults in `<head>`, REST `consent`/`geo`/`audit`), `class-blocker.php` (output buffer), `class-scanner.php`, `class-requests.php`, `class-migration.php`, `class-site-health.php`, `class-hub.php`, `class-activator.php`, `class-plugin.php`.
- `admin/` – `class-admin.php` (all tabs, actions via `admin-post.php?action=goo1_pp&do=…`, nonce `goo1_pp_<do>`), css/js, `block.js`.
- `public/` – `banner.js/css`, `request.js`, `documents.css`.
- `languages/` – `.pot`, `de_DE.po` and `.mo` (also `de_DE_formal`, `de_AT`, `de_CH`). Update with `python3 .github/scripts/i18n.py update` then `compile` (needs `pip install polib`).
- `tests/run.php` – standalone tests (conditions, markdown, every clause condition and placeholder, every service regex). Run `php tests/run.php path/to/library.json`.

The clause library is **not** in this folder: `src/goo1-privacypilot-library/` (see its structure below).

## Publishing rules (Publisher)

1. Render every enabled document × language.
2. Same content hash as published → nothing (draft deleted).
3. `classify()`: no published version, changed entity/site hash, added/removed clause, or a changed clause marked `change: material` → **material**; otherwise **editorial**.
4. Editorial → published automatically. Material → draft; published when approved (standalone: `goo1_pp_approval` = library version + entity hash + site hash; hub/client: entity approval on the hub + site approval locally) or on the clause's `effective` date if `auto_publish_deadline` is on and the document was published before. First publication always needs a human.
5. `goo1_pp_synced_library` remembers the library version of the last sync; `admin_init` re-syncs once after a plugin update.

## Consent

- Cookie `pp_consent` = JSON `{id, v (banner version), t, c (categories), s (services), r (rule), a (action)}`; banner version is a hash of the used services, so adding a service asks again.
- Rule: EU/EEA/UK/CH opt-in, US opt-out (notice + "Do Not Sell or Share"), other/unknown opt-in. Country from `/cdn-cgi/trace` (Cloudflare) with REST `/geo` as fallback; without Cloudflare everybody gets opt-in.
- Site answer `services.necessary` (checkboxes below the services question, not a library question) turns selected services into category `necessary` inside `Goo1_PP_Library::services()` (`category_default` keeps the original), so blocker, banner and documents all follow. Changing it changes the site hash, so documents wait for approval.
- GPC removes `marketing`. Consent Mode defaults are denied and updated once per distinct state.
- Revoking a category reloads the page (scripts that ran cannot be unloaded).
- Consent log stores no IP and no user agent.

## Blocker

Output buffer on the front end only (not admin, REST, feeds, builders' preview). Scripts matching a service pattern become `type="text/plain" data-pp-category data-pp-service`; iframes become `.pp-embed` placeholders holding the original markup base64-encoded; Elementor video widgets get their `data-settings` parked in `data-pp-settings` (no second overlay when the widget already contains a blocked iframe, e.g. Vimeo). Section/container background videos (`background_video_link` in `data-settings`) keep a static copy of their settings without the video and get class `pp-elementor-bg`; no overlay. Category `necessary` is never blocked; `cmp` tools can be removed. Forms that need a blocked service (`FORM_GATES` in `banner.js`, currently reCAPTCHA: `.elementor-g-recaptcha`, `.g-recaptcha`, `[data-pp-needs]`) get a `.pp-form-gate` notice with an allow button (service-level consent) and their submit is stopped in the capture phase until the script is active.

## Clause library (`src/goo1-privacypilot-library/`)

- `meta.json`, `documents.json` (ids, titles, slugs per language), `questions.json` (scope `entity` or `site`, `when`, options, defaults), `services.json` (id, name, provider, category, group, purpose, privacy_url, cookies, patterns), `ui.json` (banner/placeholder/form texts per language).
- `clauses/<doc>/<lang>/<order>-<id>.md` with front matter `id, order, when, version, change (editorial|material), effective, jurisdiction, reviewed_by, reviewed_at`.
- Bump `version` and set `change` honestly when editing a clause: `material` makes every site wait for approval. Use `effective: YYYY-MM-DD` for legal deadlines.
- Built by `.github/scripts/build_library.py` (validates everything; version = last commit time of the library directory) and signed by `.github/scripts/sign_library.php` in the release workflow (secret `PRIVACYPILOT_SIGNING_KEY`). Feed: `distmeta/privacypilot/library.json(.sig)`.
- Use `group.marketing` (advertising services) rather than `cat.marketing` for ad-related clauses: newsletter tools are in the marketing consent category but are not advertising.

## Testing

- Local: `.github/scripts/build_local.sh goo1-privacypilot <outdir>` builds the ZIP like the workflow; then `php src/goo1-privacypilot/tests/run.php <outdir>/library.json`.
- Dev site: dev.wp1web.com (container `wordpress_wp1web_dev_web_1` on v22768, wp-cli available, `wp-content` volume `/var/docker/wordpress/data/wp1web_dev/wp-content`). The site sits behind Cloudflare and a bot guard that blocks non-browser user agents (use a browser UA for curl/Playwright).

## Conventions

- Prefix `Goo1_PP_`, text domain `goo1-privacypilot`, options `goo1_pp_*` (non-autoloaded where large), tables `{prefix}goo1_pp_*`.
- Escape late, nonce every admin action, `manage_options` for everything in the admin.
- Version scheme `0.<minor>.<YYMMDD>` (header, `GOO1_PP_VERSION`, readme `Stable tag`, changelog). Release tag `goo1-privacypilot-v<version>`.
