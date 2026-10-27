<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$state = Goo1_MCP_ManageWP_Controller::worker_state();
$key   = ( $state['active'] && function_exists( 'mwp_get_potential_key' ) ) ? mwp_get_potential_key() : '';
?>
<div class="wrap">
	<h1>goo1 Claude Bridge &mdash; ManageWP</h1>
	<p><?php esc_html_e( 'Use this page to connect the site in ManageWP. Claude never sees the key: copy it here and paste it into ManageWP yourself.', 'goo1-mcp' ); ?></p>

	<?php if ( $state['godaddy_managed'] ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'This is GoDaddy Managed WordPress: the ManageWP connection runs through GoDaddy\'s system plugin. Do not install the Worker here.', 'goo1-mcp' ); ?></p></div>
	<?php endif; ?>

	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'Worker plugin', 'goo1-mcp' ); ?></th>
			<td>
				<?php
				if ( ! $state['installed'] ) {
					esc_html_e( 'not installed', 'goo1-mcp' );
				} else {
					echo esc_html( 'v' . $state['version'] . ' — ' . ( $state['active'] ? __( 'active', 'goo1-mcp' ) : __( 'inactive', 'goo1-mcp' ) ) );
				}
				?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Status', 'goo1-mcp' ); ?></th>
			<td>
				<?php if ( $state['connected'] ) : ?>
					<strong style="color:#008a20"><?php esc_html_e( 'connected', 'goo1-mcp' ); ?></strong>
					<?php foreach ( $state['connections'] as $c ) : ?>
						<br><small><?php echo esc_html( sprintf( 'ManageWP site %s — since %s, last contact %s', $c['managewp_site_id'], $c['connected_since'] ? $c['connected_since'] : '?', $c['last_contact'] ? $c['last_contact'] : '—' ) ); ?></small>
					<?php endforeach; ?>
				<?php else : ?>
					<strong style="color:#b32d2e"><?php esc_html_e( 'not connected', 'goo1-mcp' ); ?></strong>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Website URL for ManageWP', 'goo1-mcp' ); ?></th>
			<td><code><?php echo esc_html( home_url() ); ?></code><?php Goo1_MCP_Admin::copy_button( home_url() ); ?>
				<p class="description"><?php esc_html_e( 'Enter it exactly like this (http/https, www or not).', 'goo1-mcp' ); ?></p></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Connection key', 'goo1-mcp' ); ?></th>
			<td>
				<?php if ( $key ) : ?>
					<input type="password" readonly value="<?php echo esc_attr( $key ); ?>" class="regular-text" id="goo1-mwp-key" autocomplete="off">
					<button type="button" class="button" onclick="var i=document.getElementById('goo1-mwp-key');i.type=i.type==='password'?'text':'password';"><?php esc_html_e( 'Show', 'goo1-mcp' ); ?></button>
					<?php Goo1_MCP_Admin::copy_button( $key ); ?>
					<p class="description"><?php esc_html_e( 'Valid for 24 hours and only until it has been used once. In ManageWP: Add website → URL → "Use connection key instead" → paste.', 'goo1-mcp' ); ?></p>
				<?php else : ?>
					<em><?php esc_html_e( 'Activate the ManageWP Worker plugin first.', 'goo1-mcp' ); ?></em>
				<?php endif; ?>
			</td>
		</tr>
	</table>
</div>
