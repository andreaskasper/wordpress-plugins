<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$label    = isset( $_GET['label'] ) ? sanitize_text_field( wp_unslash( $_GET['label'] ) ) : '';
$method   = isset( $_GET['method'] ) ? sanitize_text_field( wp_unslash( $_GET['method'] ) ) : '';
$result   = Goo1_MCP_Audit_Log::instance()->get_entries( array(
	'page'     => $page,
	'per_page' => 50,
	'label'    => $label,
	'method'   => $method,
) );
?>
<div class="wrap">
	<h1>goo1 Claude Bridge &mdash; Audit Log</h1>

	<?php settings_errors( 'goo1_mcp' ); ?>

	<form method="get" style="margin-bottom: 16px;">
		<input type="hidden" name="page" value="goo1-mcp-audit">
		<label>Key: <input type="text" name="label" value="<?php echo esc_attr( $label ); ?>" class="regular-text" placeholder="Filter by key label"></label>
		<label style="margin-left:8px;">Method:
			<select name="method">
				<option value="">All</option>
				<?php foreach ( array( 'GET', 'POST', 'PUT', 'DELETE' ) as $m ) : ?>
					<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $method, $m ); ?>><?php echo esc_html( $m ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<input type="submit" class="button" value="Filter">
	</form>

	<form method="post" style="margin-bottom: 16px;">
		<?php wp_nonce_field( 'goo1_mcp_purge_log' ); ?>
		<input type="submit" name="goo1_mcp_purge_log" class="button" value="Purge Old Entries" onclick="return confirm('Purge entries older than the retention period?');">
	</form>

	<?php if ( empty( $result['items'] ) ) : ?>
		<p>No log entries found.</p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Time</th>
					<th>Key</th>
					<th>Method</th>
					<th>Endpoint</th>
					<th>Status</th>
					<th>IP</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['items'] as $item ) : ?>
					<tr>
						<td><?php echo esc_html( $item->created_at ); ?></td>
						<td><?php echo esc_html( $item->api_key_label ); ?></td>
						<td><?php echo esc_html( $item->method ); ?></td>
						<td><code><?php echo esc_html( $item->endpoint ); ?></code></td>
						<td><?php echo esc_html( $item->response_code ); ?></td>
						<td><?php echo esc_html( $item->ip_address ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $result['pages'] > 1 ) : ?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo paginate_links( array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $result['page'],
						'total'   => $result['pages'],
					) );
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
