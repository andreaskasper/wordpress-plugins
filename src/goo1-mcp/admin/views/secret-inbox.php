<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$rid = isset( $_GET['request'] ) ? sanitize_text_field( wp_unslash( $_GET['request'] ) ) : '';
$req = $rid ? Goo1_MCP_Seal::get_request( $rid ) : null;
$done = isset( $_GET['done'] );
?>
<div class="wrap">
	<h1>goo1 Claude Bridge &mdash; <?php esc_html_e( 'Secret inbox', 'goo1-mcp' ); ?></h1>
	<?php settings_errors( 'goo1_mcp' ); ?>

	<?php if ( $done ) : ?>
		<div class="notice notice-success"><p><?php esc_html_e( 'Saved. The secret is stored encrypted for this request only. Tell Claude to continue — it will use the request id, never the secret itself.', 'goo1-mcp' ); ?></p></div>
	<?php elseif ( ! $req ) : ?>
		<p><?php esc_html_e( 'This request does not exist or has expired (30 minutes). Ask Claude to create a new one.', 'goo1-mcp' ); ?></p>
	<?php else : ?>
		<p>
			<?php
			printf(
				/* translators: 1: purpose, 2: label */
				esc_html__( 'Enter the secret for request %1$s (%2$s). It is encrypted immediately and can only be used by this site; Claude never sees it.', 'goo1-mcp' ),
				'<code>' . esc_html( $rid ) . '</code>',
				esc_html( $req['purpose'] . ( $req['label'] ? ': ' . $req['label'] : '' ) )
			);
			?>
		</p>
		<form method="post" autocomplete="off">
			<?php wp_nonce_field( 'goo1_mcp_secret_inbox' ); ?>
			<input type="hidden" name="request" value="<?php echo esc_attr( $rid ); ?>">
			<table class="form-table">
				<tr><th><label for="gs_access">Access Key</label></th><td><input type="text" id="gs_access" name="gs_access" class="regular-text" autocomplete="off" required></td></tr>
				<tr><th><label for="gs_secret">Secret Key</label></th><td><input type="password" id="gs_secret" name="gs_secret" class="regular-text" autocomplete="new-password" required></td></tr>
			</table>
			<p class="submit"><input type="submit" name="goo1_mcp_secret_inbox" class="button button-primary" value="<?php esc_attr_e( 'Save encrypted', 'goo1-mcp' ); ?>"></p>
		</form>
	<?php endif; ?>
</div>
