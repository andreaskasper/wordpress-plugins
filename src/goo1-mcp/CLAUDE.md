# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**goo1 WP Claude Bridge** (`goo1-mcp`) is a WordPress plugin that exposes WordPress functionality via a REST API for Claude AI integration. It provides ~45 REST endpoints covering content, users, database, site health, and WooCommerce. It can be used two ways: with static scoped API keys (`Authorization: Bearer goo1_mcp_…`) **or** as a remote MCP connector (Claude Desktop / claude.ai) via a built-in OAuth 2.1 server and a native MCP JSON-RPC transport endpoint. Pure PHP, no build tools.

## Architecture

### Two access surfaces
1. **REST API** under `goo1-mcp/v1` — the ~45 endpoints below, each guarded by token + scope. Driven directly with a static API key, or internally by the MCP transport.
2. **MCP transport** at `goo1-mcp/v1/mcp` (`rest/class-mcp-controller.php`) — a single JSON-RPC route speaking the MCP Streamable-HTTP protocol (`initialize` / `tools/list` / `tools/call` / `ping`). Tool definitions come from `mcp-server.json`; `tools/call` is dispatched *internally* via `rest_do_request()` to the REST controllers above, so scope checks, rate limiting and audit logging all still apply per tool.
3. **OAuth 2.1 server** (`includes/class-oauth.php`) — lets an MCP client obtain a Bearer token without a pre-shared API key (discovery + DCR + auth-code/PKCE + refresh). See the OAuth section below.

### REST API Namespace
All REST endpoints live under `goo1-mcp/v1`. The namespace constant is defined in `rest/class-base-controller.php`.

### Request Lifecycle
1. Bearer token extracted from `Authorization` header
2. `Goo1_MCP_Auth` validates token hash: first against the static API key store (`wp_options` → `goo1_mcp_api_keys`), then falls back to an OAuth access token (transient `goo1_mcp_oauth_at_{hash}`). Both resolve to the same "key data" shape (`scope`, `db_write`, `label`, `rate_limit`).
3. `Goo1_MCP_Rate_Limiter` checks transient-based sliding window (60 req/min default)
4. `Goo1_MCP_Permissions` verifies scope (`read`, `full`, `db_write`)
5. Controller handles request
6. `rest_post_dispatch` filter writes audit log entry to `{prefix}goo1_mcp_audit_log` table, and on a 401 adds a `WWW-Authenticate: Bearer resource_metadata="…"` header pointing at the OAuth protected-resource metadata (RFC 9728 / MCP auth spec)

### OAuth 2.1 connector flow (`includes/class-oauth.php`)
`Goo1_MCP_OAuth` is a self-contained authorization server, served **outside** the REST stack from a front-controller hooked on `init` priority 0 (`maybe_handle_request()`), so it runs with full WordPress + cookie context and bypasses REST permissions. It intercepts these paths (matched against the request URI, relative to the WP home path):
- `/.well-known/oauth-protected-resource` **and** `/goo1-mcp-oauth/.well-known/oauth-protected-resource` — Protected Resource Metadata (RFC 9728). `resource` = the `/mcp` URL, `authorization_servers` = the (path-based) issuer. Matched with a prefix so the path-suffixed variant (`…/wp-json/goo1-mcp/v1/mcp`) also resolves.
- `/.well-known/oauth-authorization-server` (and `…/openid-configuration`) **and** `/goo1-mcp-oauth/.well-known/oauth-authorization-server` (and `…/openid-configuration`) — Authorization Server Metadata (RFC 8414). Advertises the authorize/token/register endpoints; `registration_endpoint` only appears when DCR is enabled.

**Dual discovery location (hardening workaround).** Many servers 403-block the entire root `/.well-known/` directory (a "hide dotfiles" hardening rule — which also breaks ACME/Let's Encrypt). To survive that, the **issuer is path-based** (`home_url('/goo1-mcp-oauth')`), so the OIDC-style "well-known appended to the issuer path" discovery URL — `/goo1-mcp-oauth/.well-known/oauth-authorization-server` — is reachable even with root `.well-known` blocked, and the `WWW-Authenticate: resource_metadata="…"` pointer (used verbatim by the client, RFC 9728) targets `/goo1-mcp-oauth/.well-known/oauth-protected-resource`. The canonical root `/.well-known/` handlers are kept too, so once hardening allows them the standard path also resolves. This is why `issuer()` / `protected_resource_url()` in `class-oauth.php` return `/goo1-mcp-oauth`-prefixed URLs rather than the bare host.
- `/goo1-mcp-oauth/register` — Dynamic Client Registration (RFC 7591), POST only.
- `/goo1-mcp-oauth/authorize` — auth-code flow + PKCE (S256/plain). Requires a logged-in `manage_options` admin who picks a scope on an HTML consent page; issues a single-use code (transient, 10 min TTL).
- `/goo1-mcp-oauth/token` — token + refresh. Issues a Bearer access token stored as a transient (`oauth_token_ttl`, default 3600s) plus a non-rotating refresh token in `goo1_mcp_oauth_refresh`.

Issued access tokens are plain Bearer tokens validated by `Goo1_MCP_Auth` alongside the static keys, so every existing endpoint accepts them transparently. The consent scope choice maps to `read` / `full` / `full`+`db_write`. CORS headers (incl. `Mcp-Protocol-Version` / `Mcp-Session-Id`) are emitted for the claude.ai web client. **Routing note:** because discovery and OAuth endpoints are intercepted on `init` (not registered as rewrite rules), the request must actually reach WordPress's `index.php`. A server/WAF/security layer that serves `/.well-known/*` from disk or 403s non-standard paths will break the connector handshake before any code runs — that is the first thing to check when registration fails.

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
  class-oauth.php               # OAuth 2.1 AS: discovery, DCR, authorize+PKCE, token+refresh, client/token storage
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
  class-plugins-controller.php  # Plugins, themes, rewrite rules
  class-database-controller.php # SQL queries, table list, schema, sample
  class-site-health-controller.php # Health, error log, cron, hooks, transients
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
6. Add corresponding tool definitions in `mcp-server.json` (this is what surfaces the endpoint to MCP `tools/list` / `tools/call`)

### API Key Scopes
- `read`: GET requests only on all endpoints
- `full`: All HTTP methods (POST, PUT, DELETE)
- `db_write`: Required additionally for SQL INSERT/UPDATE/DELETE (key must also be `full` scope)

OAuth-issued tokens carry the same `scope` + `db_write` flags, chosen by the admin on the consent screen.

### Database & Options
One custom table: `{prefix}goo1_mcp_audit_log` (created on activation via `dbDelta`).

`wp_options` keys:
- `goo1_mcp_api_keys` — serialized array of hashed static keys
- `goo1_mcp_settings` — settings incl. `oauth_enabled`, `oauth_dcr_enabled`, `oauth_token_ttl`, `oauth_default_scope`, `db_write_enabled`, `default_rate_limit`, `audit_log_retention_days`
- `goo1_mcp_oauth_clients` — registered OAuth clients (DCR + manual); secret stored as SHA-256 hash
- `goo1_mcp_oauth_refresh` — refresh tokens (hashed)
- `goo1_mcp_oauth_token_index` — enumerable index of live access tokens for the admin UI

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
