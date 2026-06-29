<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Estitofo_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'estimation',
				'plural'   => 'estimations',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		$columns = array(
			'cb'      => '<input type="checkbox" />',
			'id'      => __( 'ID', 'quotely-estimates-for-woocommerce' ),
			'name'    => __( 'Customer', 'quotely-estimates-for-woocommerce' ),
			'contact' => __( 'Contact', 'quotely-estimates-for-woocommerce' ),
			'total'   => __( 'Total', 'quotely-estimates-for-woocommerce' ),
			'flow'    => __( 'Status', 'quotely-estimates-for-woocommerce' ),
			'date'    => __( 'Date', 'quotely-estimates-for-woocommerce' ),
			'actions' => __( 'Actions', 'quotely-estimates-for-woocommerce' ),
		);
		return apply_filters( 'estitofo_list_columns', $columns );
	}

	protected function get_sortable_columns() {
		return array(
			'id'    => array( 'id', false ),
			'name'  => array( 'name', false ),
			'total' => array( 'total', false ),
			'date'  => array( 'created_at', true ),
		);
	}

	protected function column_default( $item, $column_name ) {
		$value = '';
		switch ( $column_name ) {
			case 'id':
				$value = absint( $item->id );
				break;
			case 'name':
				$extra = '';
				if ( ! empty( $item->company ) ) {
					$extra = '<br><small style="color:#646970;">' . esc_html( $item->company ) . '</small>';
				}
				$value = '<strong>' . esc_html( $item->name ) . '</strong>' . $extra;
				break;
			case 'contact':
				$email = esc_html( $item->email );
				$phone = esc_html( $item->phone );
				$value = '<a href="mailto:' . esc_attr( $item->email ) . '">' . $email . '</a><br><span>' . $phone . '</span>';
				break;
			case 'total':
				$value = wp_kses_post( wc_price( $item->total ) );
				break;
			case 'flow':
				$value = $this->status_select( $item );
				break;
			case 'date':
				$value = esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->created_at ) ) );
				break;
			case 'actions':
				$value = $this->row_actions_html( $item );
				break;
		}
		/**
		 * Filter the rendered HTML for a single column cell. Add-ons hook
		 * this to fill in the cells of columns they registered via the
		 * `estitofo_list_columns` filter.
		 *
		 * @param string $value      Default cell HTML (empty for unknown columns).
		 * @param string $column_name
		 * @param object $item       Submission row.
		 */
		return apply_filters( 'estitofo_list_column_value', $value, $column_name, $item );
	}

	private function status_select( $item ) {
		$current  = $item->workflow_status ?? 'new';
		$statuses = Estitofo_Dashboard::statuses();
		$html     = '<select class="wc-est-status" data-id="' . esc_attr( $item->id ) . '">';
		foreach ( $statuses as $slug => $label ) {
			$html .= '<option value="' . esc_attr( $slug ) . '" ' . selected( $current, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select>';
		return $html;
	}

	private function row_actions_html( $item ) {
		$view  = sprintf(
			'<button type="button" class="button view-products-btn" data-id="%d">%s</button> ',
			absint( $item->id ),
			esc_html__( 'View', 'quotely-estimates-for-woocommerce' )
		);
		$reply = sprintf(
			' <a class="button" href="mailto:%s?subject=%s">%s</a>',
			esc_attr( $item->email ),
			rawurlencode(
				sprintf(
				/* translators: %d: estimation id */
					__( 'Re: your estimation #%d', 'quotely-estimates-for-woocommerce' ),
					(int) $item->id
				)
			),
			esc_html__( 'Reply', 'quotely-estimates-for-woocommerce' )
		);
		$notes_btn = sprintf(
			' <button type="button" class="button edit-notes-btn" data-id="%d">%s</button>',
			absint( $item->id ),
			esc_html__( 'Notes', 'quotely-estimates-for-woocommerce' )
		);
		return $view . $reply . $notes_btn;
	}

	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="estimation[]" value="%d" />',
			absint( $item->id )
		);
	}

	protected function get_bulk_actions() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter; bulk-action nonce checked on submit.
		$status  = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : 'publish';
		$actions = ( 'trash' === $status )
			? array(
				'restore' => __( 'Restore', 'quotely-estimates-for-woocommerce' ),
				'delete'  => __( 'Delete Permanently', 'quotely-estimates-for-woocommerce' ),
				'export'  => __( 'Export to CSV', 'quotely-estimates-for-woocommerce' ),
			)
			: array(
				'trash'  => __( 'Move to Trash', 'quotely-estimates-for-woocommerce' ),
				'export' => __( 'Export to CSV', 'quotely-estimates-for-woocommerce' ),
			);
		return apply_filters( 'estitofo_list_bulk_actions', $actions );
	}

	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$from = isset( $_REQUEST['from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['from'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to = isset( $_REQUEST['to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['to'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flow = isset( $_REQUEST['flow'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['flow'] ) ) : '';
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="wc-est-from"><?php esc_html_e( 'From', 'quotely-estimates-for-woocommerce' ); ?></label>
			<input type="date" id="wc-est-from" name="from" value="<?php echo esc_attr( $from ); ?>">
			<label class="screen-reader-text" for="wc-est-to"><?php esc_html_e( 'To', 'quotely-estimates-for-woocommerce' ); ?></label>
			<input type="date" id="wc-est-to" name="to" value="<?php echo esc_attr( $to ); ?>">
			<label class="screen-reader-text" for="wc-est-flow"><?php esc_html_e( 'Status', 'quotely-estimates-for-woocommerce' ); ?></label>
			<select id="wc-est-flow" name="flow">
				<option value=""><?php esc_html_e( 'All statuses', 'quotely-estimates-for-woocommerce' ); ?></option>
				<?php foreach ( Estitofo_Dashboard::statuses() as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $flow, $slug ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'quotely-estimates-for-woocommerce' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	public function prepare_items() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'estimation_submissions';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : 'publish';
		$status = in_array( $status, array( 'publish', 'trash' ), true ) ? $status : 'publish';

		$where_sql = $wpdb->prepare( 'WHERE status = %s', $status );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_REQUEST['s'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$search     = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) . '%';
			$where_sql .= $wpdb->prepare( ' AND (name LIKE %s OR email LIKE %s OR phone LIKE %s OR company LIKE %s)', $search, $search, $search, $search );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flow = isset( $_REQUEST['flow'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['flow'] ) ) : '';
		if ( '' !== $flow && array_key_exists( $flow, Estitofo_Dashboard::statuses() ) ) {
			$where_sql .= $wpdb->prepare( ' AND workflow_status = %s', $flow );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from = isset( $_REQUEST['from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['from'] ) ) : '';
		if ( $from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$where_sql .= $wpdb->prepare( ' AND created_at >= %s', $from . ' 00:00:00' );
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to = isset( $_REQUEST['to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['to'] ) ) : '';
		if ( $to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$where_sql .= $wpdb->prepare( ' AND created_at <= %s', $to . ' 23:59:59' );
		}

		$allowed_orderby = array( 'id', 'name', 'total', 'created_at' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested_orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
		$orderby           = in_array( $requested_orderby, $allowed_orderby, true ) ? $requested_orderby : 'created_at';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) ) : 'DESC';
		$order = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; $where_sql prepared.
		$total_items = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name} {$where_sql}" );

		$per_page     = $this->get_items_per_page( 'estimations_per_page', 20 );
		$current_page = $this->get_pagenum();
		$offset       = max( 0, ( $current_page - 1 ) * $per_page );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);
        // phpcs:enable

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => $per_page > 0 ? ceil( $total_items / $per_page ) : 0,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	protected function get_views() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'estimation_submissions';
		$base_url   = admin_url( 'admin.php?page=estimation-data' );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : 'publish';

		$links = array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count_publish    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE status = 'publish'" );
		$links['publish'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
			esc_url( $base_url ),
			'publish' === $current ? ' class="current"' : '',
			esc_html__( 'All', 'quotely-estimates-for-woocommerce' ),
			number_format_i18n( $count_publish )
		);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count_trash    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE status = 'trash'" );
		$links['trash'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
			esc_url( add_query_arg( 'status', 'trash', $base_url ) ),
			'trash' === $current ? ' class="current"' : '',
			esc_html__( 'Trash', 'quotely-estimates-for-woocommerce' ),
			number_format_i18n( $count_trash )
		);

		return $links;
	}
}

if ( ! class_exists( 'Estimation_List_Table' ) ) {
	class_alias( 'Estitofo_List_Table', 'Estimation_List_Table' );
}
