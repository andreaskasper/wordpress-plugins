<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$oauth    = Goo1_MCP_OAuth::instance();
$settings = Goo1_MCP_OAuth::settings();
$clients  = get_option( Goo1_MCP_OAuth::CLIENTS_OPTION, array() );
$tokens   = $oauth->active_tokens();
?>
<div class="wrap">
	<h1>goo1 Claude Bridge &mdash; Connector (OAuth)</h1>

	<?php settings_errors( 'goo1_mcp' ); ?>

	<?php if ( empty( $settings['oauth_enabled'] ) ) : ?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'OAuth is currently disabled. Enable it under Settings for connectors to work.', 'goo1-mcp' ); ?>
		</p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Connect Claude Desktop / claude.ai', 'goo1-mcp' ); ?></h2>
	<p><?php esc_html_e( 'Add a custom connector in Claude and paste this URL. Claude will discover the OAuth endpoints automatically and walk you through approving the connection.', 'goo1-mcp' ); ?></p>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'MCP Connector URL', 'goo1-mcp' ); ?></th>
			<td><code style="font-size:14px;user-select:all;"><?php echo esc_html( Goo1_MCP_OAuth::mcp_resource_url() ); ?></code></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Authorization Server', 'goo1-mcp' ); ?></th>
			<td><code><?php echo esc_html( Goo1_MCP_OAuth::issuer() ); ?></code></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Dynamic Registration', 'goo1-mcp' ); ?></th>
			<td>
				<?php echo ! empty( $settings['oauth_dcr_enabled'] ) ? '<span style="color:#008a20;">' . esc_html__( 'Enabled — Claude registers automatically (no manual client needed).', 'goo1-mcp' ) . '</span>' : '<span style="color:#b32d2e;">' . esc_html__( 'Disabled — use a manual client below.', 'goo1-mcp' ) . '</span>'; ?>
			</td>
		</tr>
	</table>
	<p class="description">
		<?php esc_html_e( 'Discovery endpoints:', 'goo1-mcp' ); ?>
		<code><?php echo esc_html( Goo1_MCP_OAuth::protected_resource_url() ); ?></code>,
		<code><?php echo esc_html( home_url( '/.well-known/oauth-authorization-server' ) ); ?></code>
	</p>

	<hr>

	<h2><?php esc_html_e( 'Registered Clients', 'goo1-mcp' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Clients register automatically when you connect them in Claude. Revoke one here to disconnect it.', 'goo1-mcp' ); ?></p>
	<?php if ( empty( $clients ) ) : ?>
		<p><?php esc_html_e( 'No clients yet. Add the connector URL above in Claude to register one automatically.', 'goo1-mcp' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'goo1-mcp' ); ?></th>
					<th><?php esc_html_e( 'Client ID', 'goo1-mcp' ); ?></th>
					<th><?php esc_html_e( 'Source', 'goo1-mcp' ); ?></th>
					<th><?php esc_html_e( 'Type', 'goo1-mcp' ); ?></th>
					<th><?php esc_html_e( 'Redirect URIs', 'goo1-mcp' ); ?></th>
					<th><?php esc_html_e( 'Created', 'goo1-mcp' ); ?></th>
					<th><?php esc_html_e( 'Action', 'goo1-mcp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $clients as $client ) : ?>
					<tr>
						<td><?php echo esc_html( $client['client_name'] ); ?></td>
						<td><code><?php echo esc_html( $client['client_id'] ); ?></code></td>
						<td><?php echo esc_html( $client['source'] ); ?></td>
						<td><?php echo empty( $client['client_secret_hash'] ) ? esc_html__( 'public (PKCE)', 'goo1-mcp' ) : esc_html__( 'confidential', 'goo1-mcp' ); ?></td>
						<td><?php echo $client['redirect_uris'] ? esc_html( implode( ', ', $client['redirect_uris'] ) ) : '<em>' . esc_html__( 'any https/loopback', 'goo1-mcp' ) . '</em>'; ?></td>
						<td><?php echo esc_html( $client['created_at'] ); ?></td>
						<td>
							<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this client and all its tokens?', 'goo1-mcp' ) ); ?>');">
								<?php wp_nonce_field( 'goo1_mcp_revoke_client' ); ?>
								<input type="hidden" name="client_id" value="<?php echo esc_attr( $client['client_id'] ); ?>">
								<input type="submit" name="goo1_mcp_revoke_client" class="button button-small button-link-delete" value="<?php esc_attr_e( 'Revoke', 'goo1-mcp' ); ?>">
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Active Access Tokens', 'goo1-mcp' ); ?></h2>
	<?php if ( empty( $tokens ) ) : ?>
		<p><?php esc_html_e( 'No active tokens.', 'goo1-mcp' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Client', 'goo1-mcp' ); ?></th>
					<th><?php esc_html_e( 'Scope', 'goo1-mcp' ); ?></th>
					<th><?php esc_html_e( 'DB Write', 'goo1-mcp' ); ?></th>
					<th><?php esc_html_e( 'User', 'goo1-mcp' ); ?></th>
					<th><?php esc_html_e( 'Issued', 'goo1-mcp' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'goo1-mcp' ); ?></th>
					<th><?php esc_html_e( 'Action', 'goo1-mcp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tokens as $token ) :
					$user = get_userdata( (int) $token['user_id'] ); ?>
					<tr>
						<td><?php echo esc_html( $token['client_name'] ); ?></td>
						<td><?php echo esc_html( $token['scope'] ); ?></td>
						<td><?php echo ! empty( $token['db_write'] ) ? 'Yes' : 'No'; ?></td>
						<td><?php echo $user ? esc_html( $user->user_login ) : esc_html( $token['user_id'] ); ?></td>
						<td><?php echo esc_html( $token['created_at'] ); ?></td>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $token['expires'] ) ); ?> UTC</td>
						<td>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'goo1_mcp_revoke_token' ); ?>
								<input type="hidden" name="token_hash" value="<?php echo esc_attr( $token['hash'] ); ?>">
								<input type="submit" name="goo1_mcp_revoke_token" class="button button-small button-link-delete" value="<?php esc_attr_e( 'Revoke', 'goo1-mcp' ); ?>">
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
