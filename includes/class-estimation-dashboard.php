<?php
/**
 * Dashboard widget with recent submissions and 30-day stats.
 *
 * @package quotely-estimates-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estitofo_Dashboard {

	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );
	}

	public static function register_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! (int) Estitofo_Options::get( 'show_dashboard_widget', 1 ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'estitofo_dashboard',
			__( 'Estimation Tool', 'quotely-estimates-for-woocommerce' ),
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		global $wpdb;
		$table = $wpdb->prefix . 'estimation_submissions';

		$now    = current_time( 'mysql' );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days', strtotime( $now ) ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; prepared.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'publish' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$recent_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND created_at >= %s", 'publish', $cutoff ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$recent_total = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total),0) FROM {$table} WHERE status = %s AND created_at >= %s", 'publish', $cutoff ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$recent_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, total, workflow_status, created_at FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT 5", 'publish' ) );

		$top_products = self::top_products( 30, 5 );
		?>
		<div class="wc-est-dash">
			<div class="wc-est-dash-stats" style="display:flex;gap:20px;margin-bottom:12px;">
				<div><strong style="font-size:22px;display:block;"><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
					<span style="color:#646970;"><?php esc_html_e( 'All-time submissions', 'quotely-estimates-for-woocommerce' ); ?></span></div>
				<div><strong style="font-size:22px;display:block;"><?php echo esc_html( number_format_i18n( $recent_count ) ); ?></strong>
					<span style="color:#646970;"><?php esc_html_e( 'Last 30 days', 'quotely-estimates-for-woocommerce' ); ?></span></div>
				<div><strong style="font-size:22px;display:block;"><?php echo function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $recent_total ) ) : esc_html( number_format_i18n( $recent_total, 2 ) ); ?></strong>
					<span style="color:#646970;"><?php esc_html_e( 'Value (30 days)', 'quotely-estimates-for-woocommerce' ); ?></span></div>
			</div>

			<?php if ( $recent_rows ) : ?>
				<h3 style="margin-top:14px;"><?php esc_html_e( 'Latest', 'quotely-estimates-for-woocommerce' ); ?></h3>
				<ul class="wc-est-dash-recent" style="margin:0;padding:0;list-style:none;">
					<?php
					foreach ( $recent_rows as $row ) :
						$url = admin_url( 'admin.php?page=estimation-data' );
						?>
						<li style="padding:6px 0;border-bottom:1px solid #f0f0f1;display:flex;justify-content:space-between;align-items:center;">
							<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $row->name ); ?></a>
							<span style="color:#646970;font-size:12px;">
								<?php echo esc_html( self::workflow_label( $row->workflow_status ) ); ?>
								· <?php echo function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $row->total ) ) : esc_html( number_format_i18n( $row->total, 2 ) ); ?>
								· <?php echo esc_html( human_time_diff( strtotime( $row->created_at ), time() ) ); ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $top_products ) : ?>
				<h3 style="margin-top:14px;"><?php esc_html_e( 'Top products (30d)', 'quotely-estimates-for-woocommerce' ); ?></h3>
				<ol style="padding-left:20px;margin:0;">
					<?php foreach ( $top_products as $title => $count ) : ?>
						<li><?php echo esc_html( $title ); ?> &mdash; <?php echo esc_html( number_format_i18n( $count ) ); ?></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function workflow_label( $slug ) {
		$labels = self::statuses();
		return $labels[ $slug ] ?? $slug;
	}

	public static function statuses() {
		return apply_filters(
			'estitofo_workflow_statuses',
			array(
				'new'       => __( 'New', 'quotely-estimates-for-woocommerce' ),
				'contacted' => __( 'Contacted', 'quotely-estimates-for-woocommerce' ),
				'quoted'    => __( 'Quoted', 'quotely-estimates-for-woocommerce' ),
				'won'       => __( 'Won', 'quotely-estimates-for-woocommerce' ),
				'lost'      => __( 'Lost', 'quotely-estimates-for-woocommerce' ),
			)
		);
	}

	private static function top_products( $days = 30, $limit = 5 ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'estimation_submissions';
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . absint( $days ) . ' days', time() ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows   = $wpdb->get_col( $wpdb->prepare( "SELECT products FROM {$table} WHERE status = %s AND created_at >= %s LIMIT 500", 'publish', $cutoff ) );
		$counts = array();
		foreach ( $rows as $json ) {
			$items = json_decode( (string) $json, true );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				$title = isset( $item['title'] ) ? (string) $item['title'] : '';
				if ( '' === $title ) {
					continue;
				}
				$qty = isset( $item['quantity'] ) ? max( 1, absint( $item['quantity'] ) ) : 1;
				if ( ! isset( $counts[ $title ] ) ) {
					$counts[ $title ] = 0;
				}
				$counts[ $title ] += $qty;
			}
		}
		arsort( $counts );
		return array_slice( $counts, 0, $limit, true );
	}
}

Estitofo_Dashboard::init();
