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
			<td><code><?php echo esc_html( home_url( '/wp-json/goo1-mcp/v1/' ) ); ?></code></td>
		</tr>
		<tr>
			<th>Authentication</th>
			<td><code>Authorization: Bearer &lt;your-api-key&gt;</code> <?php esc_html_e( 'or an OAuth access token', 'goo1-mcp' ); ?></td>
		</tr>
		<tr>
			<th>MCP Connector URL</th>
			<td><code><?php echo esc_html( Goo1_MCP_OAuth::mcp_resource_url() ); ?></code></td>
		</tr>
		<tr>
			<th>Plugin Version</th>
			<td><?php echo esc_html( GOO1_MCP_VERSION ); ?></td>
		</tr>
	</table>
</div>
