=== goo1 WP Claude Bridge ===
Contributors: goo1
Tags: api, rest, claude, ai, mcp, automation
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.261027
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes WordPress functionality via REST API for Claude AI integration. Manage posts, pages, users, database, and more through a secure API.

== Description ==

goo1 WP Claude Bridge provides a comprehensive REST API that allows Claude AI to interact with your WordPress site. It covers content management, user administration, site configuration, direct database access, and WooCommerce integration.

**Features:**

* Full CRUD for posts, pages, custom post types, taxonomies, media, comments, and users
* Server-side media upload from a URL (or array of URLs) — WordPress downloads the file itself, so no large base64 payload has to travel through the connector
* Navigation menu and widget management
* Plugin and theme management: list, activate, deactivate, install (wordpress.org slug or ZIP URL), update, delete, list available updates
* Universal REST tool: call any other REST route of the site internally as the run-as administrator (Redirection, menu items, Yoast, Polylang, WooCommerce REST …)
* WordPress options read/write with sensitive option filtering
* Direct SQL query execution with safety controls
* Database schema introspection (tables, columns, indexes)
* Site health dashboard (versions, debug state, constants)
* PHP error log reader
* Cron job listing and manual execution
* Action/filter hook introspection
* Transient management
* WooCommerce support (products, orders, customers, inventory, sales reports)
* UpdraftPlus support: status, S3-Compatible (Generic) configuration with sealed credentials, connection test, remote rescan, backup now + progress, backup list, retention guard
* ManageWP Worker helpers: connection status, clean reset, wp-admin page with the connection key (copy button) — the key is never returned to the AI
* Sealed secret hand-over: secrets reach a site encrypted (from goo1-seal.mjs on the owner's computer — local file or Vaultwarden —, from another Claude Bridge site, or typed into a wp-admin inbox), never in clear text through the conversation
* Elementor: automatic backup before every change, restore, single-element read/patch by id, saves through Elementor's document API (revisions)
* Cache purge across common caching plugins, one-call site overview for audits
* Elementor support (read/write page data, template library, active kit, compact widget tree, CSS regeneration) — active only when Elementor is installed
* Bearer token authentication with scoped API keys
* OAuth 2.1 remote MCP connector (Claude Desktop / claude.ai) with PKCE and Dynamic Client Registration
* Native MCP JSON-RPC transport endpoint (/wp-json/goo1-mcp/v1/mcp)
* Per-key rate limiting
* Full audit logging

**Security:**

* Every key / OAuth connection runs as a chosen WordPress administrator (capabilities, unfiltered_html and authorship like in wp-admin)
* Secrets (passwords, API/S3 keys, tokens, licence keys) are redacted in all tool output; optional, time-limited grants for clear text and SQL file access, behind a master switch
* The bridge's own settings can never be changed through the bridge
* Scoped permissions: read-only or full access
* Separate database write permission flag
* Rate limiting per key (configurable requests/minute)
* SQL injection protection via parameterized queries
* DDL statements (DROP, ALTER, CREATE, TRUNCATE) always blocked
* Sensitive options automatically filtered from API responses
* Complete audit trail of all API requests

== Installation ==

1. Upload the `goo1-mcp` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Claude Bridge > API Keys to create your first API key
4. Use the key with `Authorization: Bearer <your-key>` header

== Changelog ==

= 1.7.261027 =
* Secrets: sealed hand-over ("goo1seal1": X25519 + HKDF-SHA256 + ChaCha20-Poly1305). The request's private key is stored encrypted with a key derived from the wp-config salts (needs unique AUTH_KEY/SECURE_AUTH_KEY). Export targets are exact hosts (no wildcards, no redirects). New tools secrets_request, secrets_status, secrets_export_sealed. A target site creates a one-time request (30 min); the envelope comes from tools/goo1-seal.mjs on the owner's computer (reads goo1-vault.json or Vaultwarden via the bw CLI; fetches the public key from the target itself), from another Bridge site (only to hosts on its "Secret export targets" allowlist; it fetches the recipient's key itself), or from a hidden wp-admin "Secret inbox" page. The AI only ever sees the envelope.
* UpdraftPlus: updraft_status, updraft_configure_s3 (S3-Compatible Generic; credentials only sealed; tests first; for a new target it rescans the bucket and refuses with 409 — nothing saved — if the retention would delete older sets unless confirm_retention=true), updraft_test_s3, updraft_rescan_remote, updraft_list_backups, updraft_backup_now (background via WP-Cron), updraft_backup_progress.
* ManageWP: managewp_status, managewp_connection_link (refreshes the key, returns the wp-admin page where the owner copies it), managewp_reset (removes Worker keys incl. object cache). New admin page Claude Bridge → ManageWP with copy buttons for site URL and key.
* Elementor: elementor_update_data backs up the current state first and saves through Elementor's document API (creates a revision, clears the element cache). New tools elementor_get_element, elementor_patch_element (change one element's settings without uploading the page), elementor_list_backups, elementor_restore_backup. Backups live in the new table {prefix}goo1_mcp_backups (last 10 per page).
* New tools wp_cache_purge (LiteSpeed, WP Rocket, W3TC, WP Super Cache, WP Fastest Cache, SiteGround, Autoptimize, Breeze, Cache Enabler, Hummingbird, Nginx Helper, WP Engine, Elementor CSS, object cache) and wp_site_overview (versions, PHP EOL, debug flags, pending updates, risky/caching plugins, backup and ManageWP status, overdue cron, DB/disk, bridge keys).

= 1.6.261027 =
* Run-as user: every request now runs as a WordPress administrator — OAuth connections as the admin who approved them, API keys as the admin chosen under "Runs as" (default: the admin creating the key). Fixes Elementor HTML widgets losing <style>/<script>/SVG and `&` turning into `&amp;` (Elementor sanitises _elementor_data for users without unfiltered_html), new posts having author 0, and capability checks of other plugins failing. Keys created before 1.6 keep working without a user; an admin notice asks to assign one.
* Security: options, user/post meta, REST-proxy output and SQL results are redacted (user_pass and other password hashes, session tokens, S3/API keys, passwords, tokens, licence keys, nested gateway settings …). Writing a value that contains "[redacted]" keeps the stored secret (also in Elementor data, matched by element id). SQL results and REST-proxy output are additionally checked against the site's known secret values, so a secret is masked under any column name or alias. For free SQL the redaction is accident protection, not a boundary: deliberate transformations (HEX, SUBSTRING …) are not caught. Value matching only uses values that look generated (digits or mixed case, no spaces), so settings like "lost-password" never mask ordinary text. Columns named like secrets (consumer_secret, api_key …) are masked in SQL results. Elementor tools now redact form integration keys too; post/user meta written back with "[redacted]" keeps the stored secret (also inside JSON meta), and meta values are no longer unslashed (backslashes survive).
* Security: wp_db_query and wp_rest_request now require full scope (read-scope keys keep all other read tools). Reason: requests now run as an administrator, and free SQL can always be written to get around redaction.
* Security: LOAD_FILE, INTO OUTFILE/DUMPFILE and LOAD DATA are blocked in wp_db_query (audit finding K4). The keyword scan uses a real SQL lexer (strings, quoted identifiers, -- / # / block comments; executable /*! */ comments count as code), so comment tricks don't bypass it and `LIKE '%drop%'` no longer fails.
* Security: optional grants "Secrets in clear text" and "SQL file access" per API key / OAuth connection — permanent or time-limited, behind a master switch (Settings, default off), and only effective when a call sends unredacted=true. Such calls are flagged in the audit log.
* Security: Claude Bridge settings (goo1_mcp_*) can no longer be changed through the bridge's own tools. Option names are checked the way MySQL compares them (look-alikes such as a full-width "ｇ" are rejected), raw SQL writes run in a transaction and are rolled back if they touch bridge rows, and the REST proxy / plugin activation restore the core settings. This is a tripwire, not a boundary: a full-scope token that installs plugins can run code. An install can no longer replace the bridge itself.
* Security: OAuth access tokens are only accepted if the authorization server issued them (listed in the protected token index, unexpired, connection alive) — a token transient written by other means is ignored.
* Security (OAuth, audit finding K6): Dynamic Client Registration requires https (or loopback) redirect_uris; only S256 PKCE; refresh tokens rotate on every use (one grace redemption within 120 s for parallel refreshes, same client only; a further replay revokes the connection) and expire after 90 days without use (configurable); a refresh fails once the approving user is gone or no longer an admin; writes to the OAuth stores are serialised with a database lock. The authorize endpoint asks for the admin login before it redirects anywhere (no open redirect via registered clients). Existing refresh tokens get 90 days from the update.
* Security: the audit-log label can no longer be spoofed via a `_goo1_mcp_key` request parameter.
* New tools: wp_install_plugin, wp_update_plugin, wp_delete_plugin, wp_install_theme, wp_update_theme, wp_delete_theme, wp_list_updates, wp_rest_request, wp_rest_routes.
* New tools for endpoints that existed but were not exposed: wp_get/update/delete_comment, wp_get/update/delete_term, wp_update_user, wp_delete_user, wp_create_menu, wp_delete_menu, wp_set_menu_items (now with parent_index for nesting), wp_list_menu_locations, wp_delete_option, wp_flush_rewrite_rules, wp_delete_transient.
* MCP: tools carry annotations (readOnlyHint / destructiveHint / idempotentHint); WooCommerce and Elementor tools are only listed when the plugin is active; protocol version negotiation; server instructions tell the client which user it runs as.
* Slimmer responses: wp_get_post returns meta names only unless include_meta / meta_keys is given; wp_create_post / wp_update_post return a summary (return_full=true for the full post).
* Fixed: posts lost backslashes (data was not slashed before wp_insert_post/wp_update_post); content is no longer forced through wp_kses_post — WordPress filters it unless the run-as user has unfiltered_html.
* Fixed: wp_run_cron now runs events with their scheduled arguments.
* Fixed: wp_db_table_schema caused a "wpdb::prepare without placeholder" notice that broke the JSON output on sites with WP_DEBUG_DISPLAY.
* Fixed: the key's last_used timestamp was written to wp_options on every request (twice per MCP call); now at most once a minute, without losing keys created in parallel.
* Fixed: settings page warnings for missing defaults; audit-log retention now runs daily via cron; OAuth housekeeping (expired tokens, index) runs daily.
* Fixed: tool path arguments containing "/" (plugin files like worker/init.php) are no longer broken by URL encoding; a missing path argument returns a clear error.
* Admin: copy buttons next to the MCP connector URL and REST namespace; connections list with per-connection grants and disconnect; "Runs as" per API key; flags column in the audit log.
* Breaking changes: wp_get_post returns meta only with include_meta / meta_keys; wp_create_post / wp_update_post return a summary (return_full=true for the old shape); hidden options read as "[redacted]" instead of "[blocked]"; wp_db_query and wp_rest_request need full scope; OAuth clients registered without redirect_uris or using plain PKCE must reconnect (claude.ai and Claude Desktop are not affected).

= 1.5.260705 =
* Media: new server-side URL upload. Pass `url` (a single URL or an array of URLs) to the upload tool and WordPress downloads the file(s) itself via download_url() + wp_handle_sideload(), so large images no longer have to travel through the MCP conversation as base64 (a major token saver). The MIME type is sniffed when the URL has no file extension.
* Media: base64 uploads now strip a leading data:*;base64, prefix automatically and no longer fail on it.
* Media: unified upload pipeline with many new options — attach_to_post, set_as_featured, author, date, meta, alt/title/caption/description, dedupe (+ dedupe_by source_url|filename), overwrite_existing, allowed_mime_types, max_filesize, timeout, and generate_subsizes with an automatic large-image guard (intermediate sizes are skipped above ~25 MP / 40 MB unless force_subsizes is set).
* Media: added wp_get_media, wp_update_media and wp_delete_media MCP tools (previously only listing and uploading were exposed).
* Media: fixed a PHP warning in the media formatter when the underlying file is not present locally (e.g. offloaded to S3) — filesize is now null instead of erroring.
* Elementor: new integration controller, loaded only when Elementor is active. Tools: elementor_get_data, elementor_update_data (correct wp_slash handling plus CSS regeneration), elementor_find_widgets (compact widget tree instead of the full data blob), elementor_list_templates, elementor_get_kit, elementor_regenerate_css and elementor_regenerate_all_css.
* Audit log: oversized string arguments (e.g. base64 payloads) are now replaced with a short placeholder instead of bloating the audit table.
* Fixed the plugin version being out of sync across the header, the GOO1_MCP_VERSION constant and the readme Stable tag.

= 1.3.260630 =
* Fixed the MCP connector failing to load with "400 tools.N.custom.input_schema: JSON schema is invalid (draft 2020-12)". The wp_db_query tool's `params.items` was an empty `{}` which PHP re-serialized as `[]` (invalid: `items` must be an object). `items` now has an explicit type, and normalize_schema() recursively keeps every empty object as a JSON object, so nested schema keywords (items, properties, ...) can never serialize as arrays again.
* Hardened the auto-updater bootstrap: the Plugin Update Checker is now loaded and wired up only when the library is actually present, so a missing library can no longer fatally crash the plugin (and with it every REST/MCP endpoint).

= 1.3.260629 =
* Fixed MCP tools/list rejecting tools whose input schema has no parameters: an empty `properties` now serializes as a JSON object `{}` instead of `[]`, so Claude no longer fails with "Input should be a valid dictionary".

= 1.2.260629 =
* Discovery now also served from /goo1-mcp-oauth/.well-known/* with a path-based issuer, so the OAuth/MCP connector keeps working when server hardening 403-blocks the root /.well-known/ directory (also a hint that ACME/Let's Encrypt is likely blocked the same way). The canonical root /.well-known/ endpoints still work once hardening allows them.
* WWW-Authenticate resource_metadata pointer now targets the non-blocked discovery path.

= 1.1.260629 =
* Added OAuth 2.1 authorization server (discovery metadata, authorization code + PKCE, token + refresh, Dynamic Client Registration) so Claude Desktop / claude.ai can connect as a remote MCP connector
* Added native MCP JSON-RPC transport endpoint that bridges tools/list and tools/call to the existing REST controllers
* OAuth access tokens are accepted alongside API keys on all endpoints; permission level is chosen at consent time
* Added Connector (OAuth) admin page and OAuth settings
* Fixed GOO1_MCP_VERSION constant not matching the plugin header version

= 1.0.0 =
* Initial release
