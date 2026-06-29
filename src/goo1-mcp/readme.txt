=== goo1 WP Claude Bridge ===
Contributors: goo1
Tags: api, rest, claude, ai, mcp, automation
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.260629
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes WordPress functionality via REST API for Claude AI integration. Manage posts, pages, users, database, and more through a secure API.

== Description ==

goo1 WP Claude Bridge provides a comprehensive REST API that allows Claude AI to interact with your WordPress site. It covers content management, user administration, site configuration, direct database access, and WooCommerce integration.

**Features:**

* Full CRUD for posts, pages, custom post types, taxonomies, media, comments, and users
* Navigation menu and widget management
* Plugin and theme management (list, activate, deactivate)
* WordPress options read/write with sensitive option filtering
* Direct SQL query execution with safety controls
* Database schema introspection (tables, columns, indexes)
* Site health dashboard (versions, debug state, constants)
* PHP error log reader
* Cron job listing and manual execution
* Action/filter hook introspection
* Transient management
* WooCommerce support (products, orders, customers, inventory, sales reports)
* Bearer token authentication with scoped API keys
* OAuth 2.1 remote MCP connector (Claude Desktop / claude.ai) with PKCE and Dynamic Client Registration
* Native MCP JSON-RPC transport endpoint (/wp-json/goo1-mcp/v1/mcp)
* Per-key rate limiting
* Full audit logging

**Security:**

* Custom API keys independent of WordPress user accounts
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
