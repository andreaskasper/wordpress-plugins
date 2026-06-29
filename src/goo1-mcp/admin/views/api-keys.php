<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$keys    = get_option( 'goo1_mcp_api_keys', array() );
$new_key = get_transient( 'goo1_mcp_new_key' );
if ( $new_key ) {
	delete_transient( 'goo1_mcp_new_key' );
}
?>
<div class="wrap">
	<h1>goo1 Claude Bridge &mdash; API Keys</h1>

	<?php settings_errors( 'goo1_mcp' ); ?>

	<?php if ( $new_key ) : ?>
		<div class="notice notice-warning">
			<p><strong>Your new API key (copy now, it will not be shown again):</strong></p>
			<p><code style="font-size: 14px; padding: 8px; background: #fff3cd; user-select: all;"><?php echo esc_html( $new_key ); ?></code></p>
		</div>
	<?php endif; ?>

	<h2>Create New Key</h2>
	<form method="post">
		<?php wp_nonce_field( 'goo1_mcp_create_key' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="key_label">Label</label></th>
				<td><input type="text" id="key_label" name="key_label" class="regular-text" required placeholder="e.g. Claude Desktop"></td>
			</tr>
			<tr>
				<th><label for="key_scope">Scope</label></th>
				<td>
					<select id="key_scope" name="key_scope">
						<option value="read">Read Only</option>
						<option value="full">Full Access</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="key_db_write">Database Write</label></th>
				<td>
					<label>
						<input type="checkbox" id="key_db_write" name="key_db_write" value="1">
						Allow direct SQL write queries (INSERT, UPDATE, DELETE)
					</label>
				</td>
			</tr>
			<tr>
				<th><label for="key_rate_limit">Rate Limit</label></th>
				<td>
					<input type="number" id="key_rate_limit" name="key_rate_limit" value="60" min="1" max="1000" class="small-text">
					<span>requests per minute</span>
				</td>
			</tr>
		</table>
		<p class="submit">
			<input type="submit" name="goo1_mcp_create_key" class="button button-primary" value="Create API Key">
		</p>
	</form>

	<h2>Active Keys</h2>
	<?php if ( empty( $keys ) ) : ?>
		<p>No API keys yet. Create one above.</p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Label</th>
					<th>Scope</th>
					<th>DB Write</th>
					<th>Rate Limit</th>
					<th>Created</th>
					<th>Last Used</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $keys as $key ) : ?>
					<tr>
						<td><?php echo esc_html( $key['label'] ); ?></td>
						<td><?php echo esc_html( $key['scope'] ); ?></td>
						<td><?php echo $key['db_write'] ? 'Yes' : 'No'; ?></td>
						<td><?php echo esc_html( $key['rate_limit'] ); ?>/min</td>
						<td><?php echo esc_html( $key['created_at'] ); ?></td>
						<td><?php echo $key['last_used'] ? esc_html( $key['last_used'] ) : 'Never'; ?></td>
						<td>
							<form method="post" style="display:inline;" onsubmit="return confirm('Revoke this key?');">
								<?php wp_nonce_field( 'goo1_mcp_revoke_key' ); ?>
								<input type="hidden" name="key_hash" value="<?php echo esc_attr( $key['key_hash'] ); ?>">
								<input type="submit" name="goo1_mcp_revoke_key" class="button button-small button-link-delete" value="Revoke">
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
