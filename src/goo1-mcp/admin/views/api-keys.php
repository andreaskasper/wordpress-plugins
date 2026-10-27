<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$keys           = get_option( 'goo1_mcp_api_keys', array() );
$new_key        = get_transient( 'goo1_mcp_new_key' );
$admins         = Goo1_MCP_Admin::admin_users();
$grants_enabled = Goo1_MCP_Permissions::grants_enabled();
$grant_labels   = array(
	'unredacted' => __( 'Secrets in clear text', 'goo1-mcp' ),
	'sql_files'  => __( 'SQL file access', 'goo1-mcp' ),
);
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
			<p><code style="font-size: 14px; padding: 8px; background: #fff3cd; user-select: all;"><?php echo esc_html( $new_key ); ?></code><?php Goo1_MCP_Admin::copy_button( $new_key ); ?></p>
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
				<th><label for="key_user_id"><?php esc_html_e( 'Runs as', 'goo1-mcp' ); ?></label></th>
				<td>
					<select id="key_user_id" name="key_user_id">
						<?php foreach ( $admins as $a ) : ?>
							<option value="<?php echo esc_attr( $a->ID ); ?>" <?php selected( (int) $a->ID, get_current_user_id() ); ?>><?php echo esc_html( $a->user_login . ' (' . $a->display_name . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Requests with this key act as this administrator: capability checks, HTML filtering (unfiltered_html) and authorship work like in wp-admin.', 'goo1-mcp' ); ?></p>
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
			<?php if ( $grants_enabled ) : ?>
				<?php foreach ( $grant_labels as $g => $glabel ) : ?>
				<tr>
					<th><?php echo esc_html( $glabel ); ?></th>
					<td>
						<select name="key_grant[<?php echo esc_attr( $g ); ?>]">
							<option value=""><?php esc_html_e( 'Not granted', 'goo1-mcp' ); ?></option>
							<option value="60"><?php esc_html_e( 'For 60 minutes', 'goo1-mcp' ); ?></option>
							<option value="1440"><?php esc_html_e( 'For 24 hours', 'goo1-mcp' ); ?></option>
							<option value="perm"><?php esc_html_e( 'Permanently', 'goo1-mcp' ); ?></option>
						</select>
					</td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
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
					<th><?php esc_html_e( 'Runs as / extra rights', 'goo1-mcp' ); ?></th>
					<th>Rate Limit</th>
					<th>Created</th>
					<th>Last Used</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $keys as $key ) :
					$grants = Goo1_MCP_Permissions::sanitize_grants( isset( $key['grants'] ) ? $key['grants'] : array() );
					?>
					<tr>
						<td><?php echo esc_html( $key['label'] ); ?></td>
						<td><?php echo esc_html( $key['scope'] ); ?></td>
						<td><?php echo $key['db_write'] ? 'Yes' : 'No'; ?></td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'goo1_mcp_edit_key' ); ?>
								<input type="hidden" name="key_hash" value="<?php echo esc_attr( $key['key_hash'] ); ?>">
								<select name="key_user_id">
									<?php if ( empty( $key['user_id'] ) ) : ?>
										<option value="0" selected><?php esc_html_e( '— no user (legacy) —', 'goo1-mcp' ); ?></option>
									<?php endif; ?>
									<?php foreach ( $admins as $a ) : ?>
										<option value="<?php echo esc_attr( $a->ID ); ?>" <?php selected( (int) $a->ID, (int) ( $key['user_id'] ?? 0 ) ); ?>><?php echo esc_html( $a->user_login ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ( $grants_enabled ) : ?>
									<?php foreach ( $grant_labels as $g => $glabel ) : ?>
										<br><small><?php echo esc_html( $glabel ); ?>:</small>
										<?php Goo1_MCP_Admin::grant_select( 'key_grant[' . $g . ']', $grants[ $g ] ); ?>
									<?php endforeach; ?>
								<?php endif; ?>
								<input type="submit" name="goo1_mcp_edit_key" class="button button-small" value="<?php esc_attr_e( 'Save', 'goo1-mcp' ); ?>">
							</form>
						</td>
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
		<?php if ( ! $grants_enabled ) : ?>
			<p class="description"><?php esc_html_e( 'Extra rights (secrets in clear text, SQL file access) are switched off globally. Enable them under Settings to grant them per key.', 'goo1-mcp' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
