<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = Goo1_MCP_OAuth::settings();
?>
<div class="wrap">
	<h1>goo1 Claude Bridge &mdash; Settings</h1>

	<?php settings_errors( 'goo1_mcp' ); ?>

	<form method="post">
		<?php wp_nonce_field( 'goo1_mcp_settings' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="default_rate_limit">Default Rate Limit</label></th>
				<td>
					<input type="number" id="default_rate_limit" name="default_rate_limit"
						value="<?php echo esc_attr( $settings['default_rate_limit'] ); ?>" min="1" max="1000" class="small-text">
					<span>requests per minute (for new keys)</span>
				</td>
			</tr>
			<tr>
				<th><label for="audit_log_retention_days">Audit Log Retention</label></th>
				<td>
					<input type="number" id="audit_log_retention_days" name="audit_log_retention_days"
						value="<?php echo esc_attr( $settings['audit_log_retention_days'] ); ?>" min="1" max="365" class="small-text">
					<span>days</span>
				</td>
			</tr>
			<tr>
				<th><label for="db_write_enabled">Database Writes</label></th>
				<td>
					<label>
						<input type="checkbox" id="db_write_enabled" name="db_write_enabled" value="1"
							<?php checked( $settings['db_write_enabled'] ); ?>>
						Allow API keys to have database write permission (global toggle)
					</label>
					<p class="description">Even when enabled, each key must individually have DB write permission granted.</p>
				</td>
			</tr>
			<tr>
				<th><label for="grants_enabled"><?php esc_html_e( 'Extra rights', 'goo1-mcp' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" id="grants_enabled" name="grants_enabled" value="1" <?php checked( ! empty( $settings['grants_enabled'] ) ); ?>>
						<?php esc_html_e( 'Allow granting "Secrets in clear text" and "SQL file access" to individual keys/connections (master switch)', 'goo1-mcp' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off by default. Even with a grant, secrets stay redacted unless a call explicitly asks for unredacted=true; such calls are flagged in the audit log. Bridge settings can never be changed through the bridge itself.', 'goo1-mcp' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Secrets', 'goo1-mcp' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="seal_export_targets"><?php esc_html_e( 'Secret export targets', 'goo1-mcp' ); ?></label></th>
				<td>
					<textarea id="seal_export_targets" name="seal_export_targets" rows="4" class="large-text code" placeholder="johnlindo.com&#10;www.example.org"><?php echo esc_textarea( isset( $settings['seal_export_targets'] ) ? $settings['seal_export_targets'] : '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Sites this site may hand its stored secrets to (sealed, e.g. UpdraftPlus S3 keys for a new site). Exact hosts, one per line, optional :port (no wildcards). Each entry trusts every full-scope key/connection of THIS site to hand over its credentials to that host. Empty = no exports.', 'goo1-mcp' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'OAuth Connector', 'goo1-mcp' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="oauth_enabled"><?php esc_html_e( 'Enable OAuth', 'goo1-mcp' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" id="oauth_enabled" name="oauth_enabled" value="1" <?php checked( ! empty( $settings['oauth_enabled'] ) ); ?>>
						<?php esc_html_e( 'Allow MCP clients (Claude Desktop / claude.ai) to connect via OAuth 2.1.', 'goo1-mcp' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><label for="oauth_dcr_enabled"><?php esc_html_e( 'Dynamic Client Registration', 'goo1-mcp' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" id="oauth_dcr_enabled" name="oauth_dcr_enabled" value="1" <?php checked( ! empty( $settings['oauth_dcr_enabled'] ) ); ?>>
						<?php esc_html_e( 'Let clients register automatically (recommended). If off, create clients manually under Connector (OAuth).', 'goo1-mcp' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><label for="oauth_token_ttl"><?php esc_html_e( 'Access Token Lifetime', 'goo1-mcp' ); ?></label></th>
				<td>
					<input type="number" id="oauth_token_ttl" name="oauth_token_ttl" value="<?php echo esc_attr( $settings['oauth_token_ttl'] ); ?>" min="60" max="86400" class="small-text">
					<span><?php esc_html_e( 'seconds (clients refresh automatically)', 'goo1-mcp' ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="oauth_refresh_ttl_days"><?php esc_html_e( 'Connection Lifetime', 'goo1-mcp' ); ?></label></th>
				<td>
					<input type="number" id="oauth_refresh_ttl_days" name="oauth_refresh_ttl_days" value="<?php echo esc_attr( $settings['oauth_refresh_ttl_days'] ); ?>" min="1" max="365" class="small-text">
					<span><?php esc_html_e( 'days without use until a connection must be re-approved (refresh tokens rotate on every use)', 'goo1-mcp' ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="oauth_default_scope"><?php esc_html_e( 'Default Consent Scope', 'goo1-mcp' ); ?></label></th>
				<td>
					<select id="oauth_default_scope" name="oauth_default_scope">
						<option value="read" <?php selected( $settings['oauth_default_scope'], 'read' ); ?>><?php esc_html_e( 'Read-only', 'goo1-mcp' ); ?></option>
						<option value="full" <?php selected( $settings['oauth_default_scope'], 'full' ); ?>><?php esc_html_e( 'Full access', 'goo1-mcp' ); ?></option>
						<option value="full_dbwrite" <?php selected( $settings['oauth_default_scope'], 'full_dbwrite' ); ?>><?php esc_html_e( 'Full access + DB write', 'goo1-mcp' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Pre-selected option on the approval screen. You can still change it per connection.', 'goo1-mcp' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<input type="submit" name="goo1_mcp_save_settings" class="button button-primary" value="Save Settings">
		</p>
	</form>

	<hr>
	<h2>API Information</h2>
	<table class="form-table">
		<tr>
			<th>REST Namespace</th>
			<td><code><?php echo esc_html( rest_url( 'goo1-mcp/v1/' ) ); ?></code><?php Goo1_MCP_Admin::copy_button( rest_url( 'goo1-mcp/v1/' ) ); ?></td>
		</tr>
		<tr>
			<th>Authentication</th>
			<td><code>Authorization: Bearer &lt;your-api-key&gt;</code> <?php esc_html_e( 'or an OAuth access token', 'goo1-mcp' ); ?></td>
		</tr>
		<tr>
			<th>MCP Connector URL</th>
			<td><code><?php echo esc_html( Goo1_MCP_OAuth::mcp_resource_url() ); ?></code><?php Goo1_MCP_Admin::copy_button( Goo1_MCP_OAuth::mcp_resource_url() ); ?></td>
		</tr>
		<tr>
			<th>MCP Connector URL (lean)</th>
			<td><code><?php echo esc_html( Goo1_MCP_OAuth::mcp_resource_url( true ) ); ?></code><?php Goo1_MCP_Admin::copy_button( Goo1_MCP_OAuth::mcp_resource_url( true ) ); ?>
				<p class="description"><?php esc_html_e( 'Same tools, but offered as four meta tools (find, describe, call read, call write) — about 5 % of the context the full list needs. Recommended when many sites are connected.', 'goo1-mcp' ); ?></p></td>
		</tr>
		<tr>
			<th>Plugin Version</th>
			<td><?php echo esc_html( GOO1_MCP_VERSION ); ?></td>
		</tr>
	</table>
</div>
