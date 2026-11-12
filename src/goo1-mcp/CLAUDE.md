# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**goo1 WP Claude Bridge** (`goo1-mcp`) is a WordPress plugin that exposes WordPress functionality via a REST API for Claude AI integration. It provides ~80 REST endpoints / MCP tools covering content, users, database, site health, and WooCommerce. It can be used two ways: with static scoped API keys (`Authorization: Bearer goo1_mcp_…`) **or** as a remote MCP connector (Claude Desktop / claude.ai) via a built-in OAuth 2.1 server and a native MCP JSON-RPC transport endpoint. Pure PHP, no build tools.

## Architecture

### Two access surfaces
1. **REST API** under `goo1-mcp/v1` — the endpoints below, each guarded by token + scope. Driven directly with a static API key, or internally by the MCP transport.
2. **MCP transport** at `goo1-mcp/v1/mcp` (`rest/class-mcp-controller.php`) — a single JSON-RPC route speaking the MCP Streamable-HTTP protocol (`initialize` / `tools/list` / `tools/call` / `ping`). Tool definitions come from `mcp-server.json`; `tools/call` is dispatched *internally* via `rest_do_request()` to the REST controllers above, so scope checks, rate limiting and audit logging all still apply per tool.
3. **OAuth 2.1 server** (`includes/class-oauth.php`) — lets an MCP client obtain a Bearer token without a pre-shared API key (discovery + DCR + auth-code/PKCE + refresh). See the OAuth section below.

### REST API Namespace
All REST endpoints live under `goo1-mcp/v1`. The namespace constant is defined in `rest/class-base-controller.php`.

### Request Lifecycle
1. Bearer token extracted from `Authorization` header
2. `Goo1_MCP_Auth` validates token hash: first against the static API key store (`wp_options` → `goo1_mcp_api_keys`), then falls back to an OAuth access token (transient `goo1_mcp_oauth_at_{hash}`). Both resolve to the same "key data" shape (`scope`, `db_write`, `label`, `rate_limit`, `user_id`, `grants`).
   **Run-as user (since 1.6):** if the key data has a `user_id`, that user must exist and have `manage_options`; it becomes the current user via `wp_set_current_user()`, so kses/unfiltered_html, authorship and other plugins' capability checks behave like in wp-admin. Elementor sanitises `_elementor_data` in a `register_meta` sanitize callback for users without `unfiltered_html` — this was the cause of stripped `<style>`/`<script>` before 1.6. Keys from before 1.6 have no `user_id` and still run as user 0.
   The authenticated key data is kept in `Goo1_MCP_Auth::instance()->current()` (never trust a request param for it).
3. `Goo1_MCP_Rate_Limiter` checks transient-based sliding window (60 req/min default)
4. `Goo1_MCP_Permissions` verifies scope (`read`, `full`, `db_write`)
5. Controller handles request
6. Output passes `Goo1_MCP_Redactor` (secret-looking keys → `[redacted]`, SQL rows, serialized/JSON strings) unless the key has the `unredacted` grant **and** the call sends `unredacted=true`. SQL results and REST-proxy output are also value-matched against `known_secrets()` (values of secret-bearing options and of every sensitive key in any option; only values ≥ 6 chars that look generated — digit or mixed case, no whitespace — so settings like "lost-password" never mask text; one regex per request), so aliases / `CONCAT` / missing `option_name` columns don't leak. Columns named like secrets (`is_sensitive_key`, plus `consumer_key`/`consumer_secret`) are masked. Meta written back with `[redacted]` is restored via `restore_masked_value()` (handles JSON-string meta). Deliberate transformations (HEX, SUBSTRING) are not caught — redaction is accident protection, not a boundary.
7. `rest_post_dispatch` filter writes audit log entry to `{prefix}goo1_mcp_audit_log` table (client IP: forwarding headers only from trusted proxies — setting `trusted_proxies`, empty = official Cloudflare ranges auto-detected, see `Goo1_MCP_Audit_Log::client_ip_from()`; retention purge compares in site time like `current_time('mysql')`), and on a 401 adds a `WWW-Authenticate: Bearer resource_metadata="…"` header pointing at the OAuth protected-resource metadata (RFC 9728 / MCP auth spec)

### OAuth 2.1 connector flow (`includes/class-oauth.php`)
`Goo1_MCP_OAuth` is a self-contained authorization server, served **outside** the REST stack from a front-controller hooked on `init` priority 0 (`maybe_handle_request()`), so it runs with full WordPress + cookie context and bypasses REST permissions. It intercepts these paths (matched against the request URI, relative to the WP home path):
- `/.well-known/oauth-protected-resource` **and** `/goo1-mcp-oauth/.well-known/oauth-protected-resource` — Protected Resource Metadata (RFC 9728). `resource` = the `/mcp` URL, `authorization_servers` = the (path-based) issuer. Matched with a prefix so the path-suffixed variant (`…/wp-json/goo1-mcp/v1/mcp`) also resolves.
- `/.well-known/oauth-authorization-server` (and `…/openid-configuration`) **and** `/goo1-mcp-oauth/.well-known/oauth-authorization-server` (and `…/openid-configuration`) — Authorization Server Metadata (RFC 8414). Advertises the authorize/token/register endpoints; `registration_endpoint` only appears when DCR is enabled.

**Dual discovery location (hardening workaround).** Many servers 403-block the entire root `/.well-known/` directory (a "hide dotfiles" hardening rule — which also breaks ACME/Let's Encrypt). To survive that, the **issuer is path-based** (`home_url('/goo1-mcp-oauth')`), so the OIDC-style "well-known appended to the issuer path" discovery URL — `/goo1-mcp-oauth/.well-known/oauth-authorization-server` — is reachable even with root `.well-known` blocked, and the `WWW-Authenticate: resource_metadata="…"` pointer (used verbatim by the client, RFC 9728) targets `/goo1-mcp-oauth/.well-known/oauth-protected-resource`. The canonical root `/.well-known/` handlers are kept too, so once hardening allows them the standard path also resolves. This is why `issuer()` / `protected_resource_url()` in `class-oauth.php` return `/goo1-mcp-oauth`-prefixed URLs rather than the bare host.
- `/goo1-mcp-oauth/register` — Dynamic Client Registration (RFC 7591), POST only.
- `/goo1-mcp-oauth/authorize` — auth-code flow + PKCE (S256 only since 1.6). Requires a logged-in `manage_options` admin who picks a scope on an HTML consent page; issues a single-use code (transient, 10 min TTL).
- `/goo1-mcp-oauth/token` — token + refresh. Issues a Bearer access token stored as a transient (`oauth_token_ttl`, default 3600s) plus a refresh token in `goo1_mcp_oauth_refresh`. Since 1.6 refresh tokens **rotate** on every use; each connection is a `family`. A rotated token is still honoured for `REFRESH_GRACE` (120 s, parallel refreshes); after that a replay revokes the whole family. Refresh tokens expire after `oauth_refresh_ttl_days` (90) and require the approving user to still be an admin. DCR requires https/loopback `redirect_uris`; PKCE is S256 only.

Issued access tokens are plain Bearer tokens validated by `Goo1_MCP_Auth` alongside the static keys, so every existing endpoint accepts them transparently. The consent scope choice maps to `read` / `full` / `full`+`db_write`. CORS headers (incl. `Mcp-Protocol-Version` / `Mcp-Session-Id`) are emitted for the claude.ai web client. **Routing note:** because discovery and OAuth endpoints are intercepted on `init` (not registered as rewrite rules), the request must actually reach WordPress's `index.php`. A server/WAF/security layer that serves `/.well-known/*` from disk or 403s non-standard paths will break the connector handshake before any code runs — that is the first thing to check when registration fails.

### Secrets, grants, self-protection (since 1.6)
- `includes/class-redactor.php` — value-based redaction. `redact_text()` masks secrets *inside* free text (substring replacement of `known_secrets()` plus wp-config DB password/salts, password hashes, PEM private keys) — used by `wp_error_log`. `is_blocked_option()` (whole option hidden), `is_sensitive_key()` (nested keys like secretkey/accesskey/password/token, minus suffixes such as `_ttl`/`_enabled`), `redact_rows()` for SQL results, `restore_masked()` so writing a read-back value with `[redacted]` keeps the stored secret.
- Grants (`unredacted`, `sql_files`) live on the API key (`grants`) or on the OAuth refresh record of a connection (resolved per request by `Goo1_MCP_OAuth::hydrate_token_data()`, so admin changes apply immediately). Each grant is `null` (no), `0` (permanent) or an expiry timestamp. Master switch `grants_enabled` in settings, default off. Only settable in wp-admin.
- `includes/class-guard.php` — the bridge's own `goo1_mcp_*` options are off limits for its tools. **Tripwire, not a security boundary** (a full-scope token that installs plugins can run code). Name checks must respect MySQL collation: `ｇoo1_mcp_settings` (full-width g) matches `goo1_mcp_settings` in `utf8mb4_general_ci` — so tool-supplied option names must be plain ASCII (`is_valid_option_name()`) and are resolved to the stored name (`stored_name()`). Raw SQL writes run via `run_sql_write()` inside a transaction (rolled back if any row named like `%goo1_mcp_%` except rate-limit transients changed); the REST proxy and plugin activation snapshot/restore only the core settings (`CORE`) to avoid reverting concurrent legitimate OAuth writes. Flagged as `guard_restored` in the audit log.
- OAuth access tokens are only valid if listed in the protected `goo1_mcp_oauth_token_index` (unexpired, family alive) — `Goo1_MCP_OAuth::is_issued_token()`. A transient alone (e.g. forged via SQL) is not enough. Writers of the OAuth stores take a MySQL named lock (`lock()`/`unlock()`) and read with `fresh()`.
- `wp_db_query` and `wp_rest_request` require full scope; SQL keyword scanning uses `code_only()` (a small lexer: strings → empty, backtick identifiers → their content, comments → space, honours NO_BACKSLASH_ESCAPES), never regexes on the raw statement. Executable comments (`/*! … */`, MariaDB `/*M! … */`) and `GET_LOCK` are rejected outright.
- Known limits of the tripwire: the REST proxy and plugin activation only snapshot `Guard::CORE`, so a third-party route that writes arbitrary options could still touch the OAuth stores; `run_sql_write()` issues `START TRANSACTION` on the shared `$wpdb` connection (would commit a transaction another plugin left open). Collations that ignore zero-width characters are covered by also snapshotting every non-ASCII option name; all bridge stores are pre-created by the activator so none can be planted as a new row.
- The OAuth lock name is per site (`md5(DB_NAME|prefix)`), because MySQL named locks are server-wide; if `GET_LOCK` times out the token endpoint answers 503 instead of running unlocked.

### Lean profile (since 1.8)
`/goo1-mcp/v1/mcp/lean` is the same transport (`Goo1_MCP_MCP_Controller::handle()`, detected via `is_lean_route()`), but `tools/list` returns only `wp_find_tools`, `wp_describe_tool`, `wp_call_read`, `wp_call_write` (`lean_tool_definitions()`). `wp_call_*` delegate to `call_tool()`, so scope checks, rate limiting, redaction and per-tool audit entries are identical. `wp_call_read` only accepts tools whose `annotations.readOnlyHint === true` — keep the annotations in `mcp-server.json` accurate, they are now an access boundary for client-side approvals. The protected-resource metadata has a lean variant (`…/oauth-protected-resource/mcp/lean`) so `resource` equals the URL the client connected to. The WP Fleet hub (repo `andreaskasper/wpfleet`) talks to sites through the normal `/mcp` and does its own search, so it works with 1.6+.

### Integrations since 1.7
- **Sealed secrets** (`includes/class-seal.php`, `rest/class-secrets-controller.php`): one-time X25519 request per target (`goo1_mcp_seal_requests`, 30 min, consumed on open). Envelope `goo1seal1.` + base64url JSON `{v,rid,epk,n,ct}`; key = HKDF-SHA256(X25519(esk, pk), info `goo1-mcp-seal-v1`, salt epk‖pk); ChaCha20-Poly1305-IETF with AD `goo1seal1|<rid>`. Sources: `tools/goo1-seal.mjs` (Node ≥18, local `goo1-vault.json` or Vaultwarden via `bw`), `secrets_export_sealed` on another site (allowlist `seal_export_targets`; the source fetches the recipient key from `/goo1-mcp/v1/secrets/public-key/<rid>` itself — never accept a public key from the caller), or the hidden admin page `goo1-mcp-secret-inbox`. Tools take `sealed` or `secret_request_id`, never clear-text secrets. At rest the request's private key and inbox entries are wrapped (secretbox) with a key derived from `AUTH_KEY`/`SECURE_AUTH_KEY` — requests are refused without unique salts; SQL on the option only shows ciphertext. Store writes take a per-site MySQL named lock; opening is single-use (`delete_request()` returns false for the loser of a race). Export: exact hosts only, no redirects (`redirection => 0`), the recipient's reported `site` host must match. Known limits: a full-scope token can seal its *own* credentials (the public key is public) and point UpdraftPlus at its own bucket — that is an admin action like any other; the AI-run `goo1-seal.mjs` refuses to run without `allowed-sites.txt`.
- **UpdraftPlus** (`rest/class-updraft-controller.php`, loaded when `UpdraftPlus` exists): options `updraft_s3generic` (multi-instance `{version:1, settings:{<id>:{accesskey,secretkey,path,endpoint,bucket_access_style,signature_version}}}`), `updraft_service`, `updraft_interval(_database)` (set via `$updraftplus->schedule_backup*()`, the register_setting sanitizers don't run outside wp-admin), `updraft_retain(_db)`. Retention guard: `simulate_prune()` mirrors `UpdraftPlus_Backup::prune_retained_backups()` (imported sets skipped, always_keep counted against `updraft_retain` while below it, auto-backups only pruned at the limit, remotesend pruned). configure_s3 refuses when the change adds deletions compared to before (rescan first for a new target; on refusal restore options **and** history); backup_now refuses when anything would be pruned unless `confirm_retention`. The sealed request is consumed only after success. Intervals are checked against `wp_get_schedules()`.
- **ManageWP** (`rest/class-managewp-controller.php`): key = `mwp_get_potential_key()` (24 h), shown only on `admin/views/managewp.php`; connections from `mwp_communication_keys`.
- **Elementor backups** (`includes/class-backups.php`, table `{prefix}goo1_mcp_backups`): taken in `save_document()` before every write; saving prefers `Document::save()`.
- **Site tools** (`rest/class-site-tools-controller.php`): `wp_cache_purge`, `wp_site_overview`.

### File tools (since 1.9)
Nine tools (`wp_file_list`, `_read`, `_search`, `_write`, `_edit`, `_fetch`, `_delete`, `_backups`, `_restore`), routes `/files/*` in `rest/class-files-controller.php` (read side) + `rest/trait-files-write.php` (changing side). `mcp-server.json` marks them `"requires": "files"`, so they only appear in `tools/list` when at least one folder is shared.
- **Shared folders** (`includes/class-file-access.php`): constant `GOO1_MCP_FILE_ROOTS` (string or array) > environment variable of the same name > setting `file_roots` (wp-admin → Settings → File access; read-only in the form when one of the first two is set). One entry per line or `;`, path relative to ABSPATH + `r`/`rw` (default `r`); ABSPATH itself, `wp-admin`, `wp-includes` are forced to `r`. Empty = tools off.
- **Resolution:** `resolve( $path, 'read'|'write'|'delete' )` normalises (no `..`, no control chars/backslashes, absolute paths only inside ABSPATH), resolves with `realpath` (for new files: deepest existing ancestor), picks the most specific root containing the **real** path (a symlink into a read-only root is read-only), refuses symlinks as write targets, the root itself for delete, and runs `blocked_reason()` on both the logical and the real path. Listing and search never follow symlinks.
- **Fixed block list** (not configurable): read+write – everything starting with `wp-config` except `wp-config-sample.php`, every dot file/folder (only exception `.htaccess`, see below), `php.ini`, `web.config`, `auth.json`, `id_rsa*`/`id_ed25519*`/`id_ecdsa*`, `*.pem|key|p12|pfx|jks|ppk|kdbx`, `*.sql` (also gzip/zip/bz2/xz/7z/tar/zst), `wp-content/updraft/`, `wp-content/ai1wm-backups/`, `wp-content/backups-dup-*`, any `wp-snapshots/`. Key/dump extensions count anywhere in the name (`server.key.bak`, `dump.sql.1`). Write only – this plugin's folder; below **every** upload folder (`uploads_rels()`: this site's basedir, the main site's on multisite, `wp-content/uploads`, `UPLOADS`, `blogs.dir`, also before they exist) only types from `get_allowed_mime_types()` (+css) and never scripts (`SCRIPT_EXT`) or `UPLOAD_EXCLUDED_EXT` (html, xht(m)l, js, svg, xml, xsl(t), mml, rdf, atom, rss …) – both also as an inner extension (`x.php.jpg`, `x.html.pages`: Apache's mod_mime uses every extension it knows); every inner part that looks like an extension (`^[a-z]{2,5}\d?$`, WordPress' `sanitize_file_name()` rule) must be an allowed type too (`theme.min.css` is refused). `Goo1_MCP_File_Writer::save()` also refuses content below uploads whose first character (after BOM/whitespace) is `<` (`looks_like_markup()`): WordPress allows types Apache sends without Content-Type (.3gp, .midi, .rtx …), and the browser would sniff HTML. The type rules apply to `write` only – deleting a stray script below uploads works.
- **.htaccess:** only below wp-content in `rw` roots; written only at least one folder below wp-content/uploads/plugins/themes/mu-plugins (`shared_dirs()`); content only access blocks (`Goo1_MCP_File_Rules::htaccess_bad_lines()`, lines split at LF like Apache, no `\` line continuation, no lone CR, `Order deny,allow` only with `Deny from all` in the same section – mod_access_compat does not merge with the parent folder, so a lone `Order deny,allow` would lift a parent's deny); the bridge writes `HTACCESS_MARKER` as the first line and changes/deletes **only** files that start with it – any other .htaccess (Wordfence, the host, a plugin's pure `Deny from all`) is never changed or deleted; `fetch` cannot create one. After writing, `Goo1_MCP_File_Verify::htaccess()` requests the folder and a file in it; a 500 is rolled back.
- **Every change goes through `Goo1_MCP_File_Writer::save()` / `delete()`:** if_sha256/overwrite rules → PHP syntax (`token_get_all(TOKEN_PARSE)`), JSON (`json_error()` with line/column; `allow_invalid_json`), .htaccess rules → backup (`Goo1_MCP_Backups::create_file()`, table `{prefix}goo1_mcp_backups`, `object_type` `file`, `object_key` = sha1(path), base64 payload, last 10 per file – `prune_file()` only after a successful change, `discard_file()` when the file was put back –, 30 days via daily maintenance, max 5 MB else `no_backup=true`). The DB tools never return this table: `wp_db_query` refuses any statement naming `goo1_mcp_backups` (also after `prepare()`, `%i`), `wp_db_table_sample` omits `payload`. → atomic write (temp `.goo1-mcp-tmp-*` in the same folder + rename, permissions kept, new 0644/folders 0755) → `opcache_invalidate` → check → on failure the previous state is put back (new files/folders removed).
- **PHP check after saving** (`Goo1_MCP_File_Verify::php()`): loopback to the front page and `admin-ajax.php?action=goo1_mcp_file_check` with core's scrape parameters (`wp_scrape_key`/`wp_scrape_nonce` + transient `scrape_key_*`): WordPress appends the last fatal error (file, line, message) and skips recovery mode (no "technical issue" mail). Not tied to `DISALLOW_FILE_EDIT`. No scrape block + HTTP ≥ 500 counts as fatal; timeouts/challenges/caches = `inconclusive` (the change stays). After a rollback the check runs again and says whether the error persists. Deleting a PHP file is checked the same way.
- **edit with CRLF / mixed line endings:** when the file has CRLF and `old_string` has no CR, matching runs on the text with CRLF read as LF (`eol_spans()`), so an LF and a CRLF occurrence both count for the uniqueness check; each replacement takes the line ending of the text it replaces (`eol_like()`). Otherwise exact byte match, `new_string` unchanged.
- **Memory:** `read` uses `fgets()` with the read limit and skips lines in 64 KB pieces; hashes are streamed (`hash_hmac_file`), in read only up to max(5 MB, write limit); `save()`/`delete()` load the old content only for files that may need an undo (PHP, .inc, .htaccess), `edit` refuses files over the write limit.
- **Rights:** list/read/search/backups = scope `read` (`readOnlyHint`); write/edit/fetch/delete/restore = scope `full` + grant `file_write` ("Write files", 60 min / 24 h / permanent, master switch "Extra rights") + `rw` root. Audit flag `file_write`.
- **Secrets:** read/search output goes through `Goo1_MCP_Redactor::redact_text()`; search matches against the **redacted** text so it cannot probe a secret. A partial read that starts inside a private key block (state carried over the skipped lines, `private_key_open_after()`) is masked from its first line. Search splits lines at LF like read and masks per line from `Goo1_MCP_Redactor::secret_spans()` (ranges of the whole file), so line numbers fit read's `offset`. **edit** matches on the redacted view (secret ranges replaced by `[redacted]`, matches touching a mask ignored) and maps the hits back to the real file, so not found / ambiguous / unchanged never depend on a secret; `unredacted=true` with the grant matches the real text. PHP syntax error messages are redacted (the parser quotes the token). **File hashes** (`sha256`, `if_sha256`, `current_sha256`, `previous_sha256`, backups) are `Goo1_MCP_File_Access::hash()` = HMAC-SHA256 keyed with `wp_salt('auth')`, never the plain SHA-256 (offline guessing of short secrets); only fetch's expected `sha256` and `download_sha256` are plain. `unredacted=true` only with the grant (never via `fleet_call_read`). `edit` refuses `[redacted]` in old/new_string unless the file literally contains it; `write` refuses more `[redacted]` than the file has. `Goo1_MCP_Audit_Log::strip_file_content()` logs only the length of `content`/`old_string`/`new_string` for `/files/*` (also direct REST). All route checks (audit hook, `/mcp` envelope, `is_lean_route()`, content stripping) ignore case and a trailing slash, because WordPress matches routes case-insensitively.
- **fetch:** `wp_safe_remote_get` with `redirection => 0`; up to `FETCH_MAX_REDIRECTS` (3) redirects are followed by the trait itself and every target goes through `fetch_url_error()` (http(s), `wp_http_validate_url()`, not the site's own host – also with a trailing dot; `wp_http_validate_url()` lets the own host through, which would allow copying a blocked file reachable over HTTP into a shared folder). Streamed into `wp_tempnam()`, `limit_response_size` = limit+1, optional plain `sha256`. The redirect loop is `Goo1_MCP_File_Access::download()` / `url_error()`, also used by `wp_upload_media` from a URL (it used `download_url()`, which accepts the own host). Not covered: the site reached by its public IP or another domain on the same server.
- **Tripwire, not a boundary:** whoever may write PHP into a plugin folder can run code, also code that changes the bridge's own settings. Grant `file_write` sparingly and time-limited.

### Class Naming & Prefix
All classes use `Goo1_MCP_` prefix. Controllers extend `Goo1_MCP_Base_Controller` which provides `get_permission_callback($scope)`, `success($data)`, and `error($code, $msg, $status)`.

### Key Design Decisions
- **Custom auth over WP application passwords**: API keys are machine-to-machine with scoped permissions, not tied to WP user accounts
- **API keys in `wp_options`**: Only 1-5 keys expected; custom table is overkill. Keys stored as SHA-256 hashes.
- **Rate limiting via transients**: Fast, auto-expiring, works with object caching
- **Options controller blocks sensitive names**: Any option containing `password`, `secret`, `token`, `auth_key`, `auth_salt`, etc. is filtered
- **Database controller SQL validation**: Parses first keyword + scans full query for DDL. `DROP`/`ALTER`/`CREATE`/`TRUNCATE` always blocked regardless of permissions.
- **WooCommerce controller conditionally loaded**: Only when `class_exists('WooCommerce')` is true
- **OAuth server lives outside REST**: Discovery + authorize/token/register are served from an `init` priority-0 front-controller, not REST routes, so they bypass the auth/permission stack and have cookie context for the admin consent screen. OAuth endpoints are intentionally **not** audit-logged (they run before any token exists — an empty audit log during connector setup is expected, not a bug).
- **OAuth access tokens as transients**: Short-lived, auto-expiring, and validated by the same `Goo1_MCP_Auth` path as API keys. An index option (`goo1_mcp_oauth_token_index`) tracks live tokens for the admin UI since transients aren't enumerable.

### File Layout
```
goo1-mcp.php                    # Bootstrap, includes, OAuth front-controller, route registration, audit hook, CORS
includes/
  class-activator.php           # DB table creation, default options + OAuth upgrade path (also has Deactivator)
  class-auth.php                # Bearer token validation (API keys + OAuth tokens), key CRUD
  class-permissions.php         # Scope checking (read/full/db_write)
  class-rate-limiter.php        # Transient-based sliding window
  class-audit-log.php           # DB logging + purge + query
  class-oauth.php               # OAuth 2.1 AS: discovery, DCR, authorize+PKCE, token+refresh (rotation), connections, client/token storage
  class-redactor.php            # Secret redaction for all tool output
  class-guard.php               # Snapshot/restore of goo1_mcp_* options around indirect writes
  class-file-access.php         # File tools: shared folders, path resolution, block list
  class-file-rules.php          # File tools: text detection, PHP/JSON/.htaccess checks
  class-file-verify.php         # File tools: loopback after PHP / .htaccess changes
  class-file-writer.php         # File tools: backup, atomic write, rollback
admin/
  class-admin.php               # Menu pages, form handlers (keys, settings, purge, OAuth client/token revoke)
  views/{api-keys,audit-log,settings,oauth-clients}.php
rest/
  class-base-controller.php     # Abstract base: auth chain, response helpers, collection params
  class-posts-controller.php    # Posts/pages/CPT CRUD + post types list
  class-taxonomies-controller.php
  class-media-controller.php    # Supports multipart upload and base64
  class-comments-controller.php
  class-menus-controller.php    # Nav menus, locations, widgets
  class-users-controller.php    # Users + roles
  class-options-controller.php  # Options with blocklist filtering
  class-plugins-controller.php  # Plugins, themes (list/activate/install/update/delete), available updates, rewrite rules
  class-rest-proxy-controller.php # wp_rest_request / wp_rest_routes: internal rest_do_request to any non-goo1 route
  class-database-controller.php # SQL queries, table list, schema, sample
  class-site-health-controller.php # Health, error log, cron, hooks, transients
  class-files-controller.php    # File tools (read side) + trait-files-write.php (changing side)
  class-mcp-controller.php      # MCP JSON-RPC transport at /mcp (bridges tools/* to REST via rest_do_request)
  class-woocommerce-controller.php # Products, orders, customers, inventory, sales
mcp-server.json                 # MCP tool definitions mapping to REST endpoints (consumed by class-mcp-controller)
uninstall.php                   # Drops table, removes options (incl. OAuth) and transients
```

### Adding a New Endpoint
1. Create `rest/class-{name}-controller.php` extending `Goo1_MCP_Base_Controller`
2. Implement `register_routes()` using `self::NAMESPACE`
3. Use `$this->get_permission_callback('read'|'full'|'db_write')` for permission callbacks
4. Return via `$this->success($data)` or `$this->error($code, $msg, $status)`
5. Add `require_once` in `goo1-mcp.php` and add class name to the `$controllers` array in `rest_api_init`
6. Add corresponding tool definitions in `mcp-server.json` (this is what surfaces the endpoint to MCP `tools/list` / `tools/call`). Add `annotations` (readOnlyHint / destructiveHint / idempotentHint / openWorldHint) and, for optional integrations, `"requires": "<integration>"` (checked in `Goo1_MCP_MCP_Controller::tool_available()`).
7. Anything that returns stored data must go through `Goo1_MCP_Redactor` (respecting `Goo1_MCP_Permissions::allow_unredacted()`); anything that can write options indirectly must be wrapped with `Goo1_MCP_Guard::snapshot()` / `restore_if_changed()`.

### API Key Scopes
- `read`: GET requests only (except free SQL and the REST proxy, which need `full` since 1.6)
- `full`: All HTTP methods (POST, PUT, DELETE)
- `db_write`: Required additionally for SQL INSERT/UPDATE/DELETE (key must also be `full` scope)

OAuth-issued tokens carry the same `scope` + `db_write` flags, chosen by the admin on the consent screen.

### Database & Options
One custom table: `{prefix}goo1_mcp_audit_log` (created on activation via `dbDelta`).

`wp_options` keys:
- `goo1_mcp_api_keys` — serialized array of hashed static keys
- `goo1_mcp_settings` — settings incl. `file_roots`, `file_max_read_kb` (200), `file_max_write_kb` (2048), `file_max_fetch_mb` (50), `trusted_proxies`, `oauth_enabled`, `oauth_dcr_enabled`, `oauth_token_ttl`, `oauth_default_scope`, `db_write_enabled`, `default_rate_limit`, `audit_log_retention_days`
- `goo1_mcp_oauth_clients` — registered OAuth clients (DCR + manual); secret stored as SHA-256 hash
- `goo1_mcp_oauth_refresh` — refresh tokens (hashed)
- `goo1_mcp_oauth_token_index` — enumerable index of live access tokens for the admin UI
- `goo1_mcp_oauth_rt_used` — rotated refresh-token hashes (grace window + replay detection)
- `goo1_mcp_db_version` — triggers `Goo1_MCP_Activator::install()` on `plugins_loaded` after an update (activation hooks don't fire on update); daily cron `goo1_mcp_daily_maintenance` (audit retention, OAuth housekeeping)

Transients: `goo1_mcp_oauth_at_{hash}` (access tokens), `goo1_mcp_oauth_code_{hash}` (auth codes), `goo1_mcp_rl_*` (rate limiter).

## Debugging the MCP / OAuth connector

When Claude Desktop / claude.ai fails to connect, walk the handshake with curl (replace `SITE`):

```bash
# 1. Unauthenticated MCP endpoint must 401 with a WWW-Authenticate pointing at the metadata
curl -i -X POST https://SITE/wp-json/goo1-mcp/v1/mcp \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'

# 2. Discovery docs must return JSON. The active path is the non-blocked one:
curl -i https://SITE/goo1-mcp-oauth/.well-known/oauth-protected-resource
curl -i https://SITE/goo1-mcp-oauth/.well-known/oauth-authorization-server
#    The canonical root paths only work once hardening allows /.well-known/ (also needed for ACME):
curl -i https://SITE/.well-known/oauth-protected-resource     # 403 here ⇒ root .well-known is hardening-blocked
curl -i https://SITE/.well-known/oauth-authorization-server

# 3. Dynamic Client Registration must return 201 + JSON with a client_id
curl -i -X POST https://SITE/goo1-mcp-oauth/register \
  -H 'Content-Type: application/json' \
  -d '{"client_name":"test","redirect_uris":["https://claude.ai/api/mcp/auth_callback"],"token_endpoint_auth_method":"none"}'
```

`oauth_error=mcp_registration_failed` means discovery (step 2) or registration (step 3) did not return clean JSON. The most common cause is server/WAF/security hardening that **403-blocks the root `/.well-known/` directory** (an Apache "hide dotfiles" rule — the 403 body is the plain Apache error page, and it breaks ACME too). The path-based issuer above is the in-plugin workaround for exactly this; the proper server-side fix is to allow `/.well-known/` past the hardening (e.g. `RewriteRule "^\.well-known/" - [L]` ahead of the dotfile block, or the equivalent exception in the host's hardening mu-plugin/vhost). Other causes: a PHP notice/whitespace/BOM polluting the JSON body; `oauth_dcr_enabled` off (then no `registration_endpoint` is advertised); or pretty permalinks disabled so non-file paths don't reach `index.php`.

## Testing Endpoints

```bash
# Health check
curl -H "Authorization: Bearer goo1_mcp_<key>" https://yoursite.com/wp-json/goo1-mcp/v1/health

# List posts
curl -H "Authorization: Bearer goo1_mcp_<key>" https://yoursite.com/wp-json/goo1-mcp/v1/posts?per_page=5

# SQL query
curl -X POST -H "Authorization: Bearer goo1_mcp_<key>" \
  -H "Content-Type: application/json" \
  -d '{"sql":"SELECT ID, post_title FROM wp_posts WHERE post_status = %s LIMIT %d","params":["publish", 5]}' \
  https://yoursite.com/wp-json/goo1-mcp/v1/db/query
```
