<?php
defined( 'ABSPATH' ) || exit;

/**
 * List tables, built on the core WP_List_Table (loaded only on our screen).
 */
function rfcf_load_list_tables() {
	if ( class_exists( 'RFCF_Rules_Table' ) ) {
		return;
	}
	if ( ! class_exists( 'WP_List_Table' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}

	final class RFCF_Rules_Table extends WP_List_Table {

		private $admin;
		private $zone;
		private $total;

		public function __construct( RFCF_Admin $admin, $zone, array $rules ) {
			parent::__construct(
				[
					'singular' => 'redirect-rule',
					'plural'   => 'redirect-rules',
					'ajax'     => false,
				]
			);
			$this->admin = $admin;
			$this->zone  = $zone;
			$this->items = [];
			foreach ( array_values( $rules ) as $i => $r ) {
				$r['_index']   = $i;
				$this->items[] = $r;
			}
			$this->total = count( $this->items );
		}

		public function get_columns() {
			return [
				'order'       => __( 'Order', 'simple-csv-cloudflare-redirects' ),
				'description' => __( 'Description', 'simple-csv-cloudflare-redirects' ),
				'when'        => __( 'When', 'simple-csv-cloudflare-redirects' ),
				'target'      => __( 'Redirect to', 'simple-csv-cloudflare-redirects' ),
				'status'      => __( 'Status code', 'simple-csv-cloudflare-redirects' ),
				'enabled'     => __( 'Enabled', 'simple-csv-cloudflare-redirects' ),
			];
		}

		public function prepare_items() {
			$this->_column_headers = [ $this->get_columns(), [], [], 'description' ];
		}

		public function no_items() {
			esc_html_e( 'No redirect rules yet.', 'simple-csv-cloudflare-redirects' );
		}

		protected function get_primary_column_name() {
			return 'description';
		}

		public function column_order( $rule ) {
			return (int) $rule['_index'] + 1;
		}

		public function column_description( $rule ) {
			$f     = $this->admin->rule_to_form( $rule );
			$label = '' !== $f['description'] ? $f['description'] : __( '(no description)', 'simple-csv-cloudflare-redirects' );

			if ( 'redirect' !== ( $rule['action'] ?? '' ) ) {
				return '<strong>' . esc_html( $label ) . '</strong>';
			}

			$id   = $rule['id'];
			$edit = $this->admin->page_url( [ 'action' => 'edit', 'zone' => $this->zone, 'rule' => $id ] );
			$out  = sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $edit ), esc_html( $label ) );

			$actions = [
				'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'simple-csv-cloudflare-redirects' ) ),
			];
			if ( $rule['_index'] > 0 ) {
				$actions['move_up'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->admin->action_url( 'rfcf_move_rule', $this->zone, $id, [ 'dir' => 'up' ] ) ), esc_html__( 'Move up', 'simple-csv-cloudflare-redirects' ) );
			}
			if ( $rule['_index'] < $this->total - 1 ) {
				$actions['move_down'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->admin->action_url( 'rfcf_move_rule', $this->zone, $id, [ 'dir' => 'down' ] ) ), esc_html__( 'Move down', 'simple-csv-cloudflare-redirects' ) );
			}
			$actions['toggle'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->admin->action_url( 'rfcf_toggle_rule', $this->zone, $id ) ),
				$f['enabled'] ? esc_html__( 'Disable', 'simple-csv-cloudflare-redirects' ) : esc_html__( 'Enable', 'simple-csv-cloudflare-redirects' )
			);
			$actions['delete'] = sprintf(
				'<a class="submitdelete" href="%s" onclick="return showNotice.warn();">%s</a>',
				esc_url( $this->admin->action_url( 'rfcf_delete_rule', $this->zone, $id ) ),
				esc_html__( 'Delete', 'simple-csv-cloudflare-redirects' )
			);

			return $out . $this->row_actions( $actions );
		}

		public function column_when( $rule ) {
			$f = $this->admin->rule_to_form( $rule );
			return '<code>' . esc_html( 'simple' === $f['mode'] ? $f['source_url'] : $f['expression'] ) . '</code>';
		}

		public function column_target( $rule ) {
			$f = $this->admin->rule_to_form( $rule );
			return '<code>' . esc_html( 'simple' === $f['mode'] ? $f['target_url'] : $f['target_value'] ) . '</code>';
		}

		public function column_status( $rule ) {
			return (int) $this->admin->rule_to_form( $rule )['status_code'];
		}

		public function column_enabled( $rule ) {
			return ! empty( $rule['enabled'] ) ? esc_html__( 'Yes', 'simple-csv-cloudflare-redirects' ) : esc_html__( 'No', 'simple-csv-cloudflare-redirects' );
		}
	}

	final class RFCF_Log_Table extends WP_List_Table {

		private $admin;

		public function __construct( RFCF_Admin $admin ) {
			parent::__construct(
				[
					'singular' => 'log-entry',
					'plural'   => 'log-entries',
					'ajax'     => false,
				]
			);
			$this->admin = $admin;
		}

		public function get_columns() {
			return [
				'time'    => __( 'Date', 'simple-csv-cloudflare-redirects' ),
				'user'    => __( 'User', 'simple-csv-cloudflare-redirects' ),
				'zone'    => __( 'Where', 'simple-csv-cloudflare-redirects' ),
				'event'   => __( 'Action', 'simple-csv-cloudflare-redirects' ),
				'rule'    => __( 'Rule', 'simple-csv-cloudflare-redirects' ),
				'details' => __( 'Details', 'simple-csv-cloudflare-redirects' ),
			];
		}

		public function prepare_items() {
			$this->_column_headers = [ $this->get_columns(), [], [], 'event' ];

			$all      = $this->admin->log_entries();
			$per_page = 50;
			$page     = $this->get_pagenum();

			$this->items = array_slice( $all, ( $page - 1 ) * $per_page, $per_page );
			$this->set_pagination_args(
				[
					'total_items' => count( $all ),
					'per_page'    => $per_page,
				]
			);
		}

		public function no_items() {
			esc_html_e( 'No changes recorded yet.', 'simple-csv-cloudflare-redirects' );
		}

		public function column_time( $e ) {
			return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $e['time'] ) );
		}

		public function column_user( $e ) {
			$u = get_userdata( (int) $e['user'] );
			return $u ? esc_html( $u->display_name ) : esc_html__( '(deleted user)', 'simple-csv-cloudflare-redirects' );
		}

		public function column_zone( $e ) {
			return esc_html( $e['zone'] );
		}

		public function column_event( $e ) {
			return esc_html( $this->admin->event_label( $e['event'] ) );
		}

		public function column_rule( $e ) {
			return esc_html( $e['rule'] );
		}

		public function column_details( $e ) {
			$before = (string) ( $e['before'] ?? '' );
			$after  = (string) ( $e['after'] ?? '' );
			if ( '' !== $before && '' !== $after ) {
				return esc_html__( 'Before:', 'simple-csv-cloudflare-redirects' ) . ' <code>' . esc_html( $before ) . '</code><br>'
					. esc_html__( 'After:', 'simple-csv-cloudflare-redirects' ) . ' <code>' . esc_html( $after ) . '</code>';
			}
			$one = '' !== $before ? $before : $after;
			return '' !== $one ? '<code>' . esc_html( $one ) . '</code>' : '';
		}
	}

	final class RFCF_CSV_Table extends WP_List_Table {

		private $admin;
		private $rows;
		private $file;

		public function __construct( RFCF_Admin $admin, array $rows, $file ) {
			parent::__construct(
				[
					'singular' => 'csv-rule',
					'plural'   => 'csv-rules',
					'ajax'     => false,
				]
			);
			$this->admin = $admin;
			$this->rows  = array_values( $rows );
			$this->file  = (string) $file;
		}

		public function get_columns() {
			return [
				'order'       => __( 'Order', 'simple-csv-cloudflare-redirects' ),
				'description' => __( 'Description', 'simple-csv-cloudflare-redirects' ),
				'source'      => __( 'Source', 'simple-csv-cloudflare-redirects' ),
				'target'      => __( 'Redirect to', 'simple-csv-cloudflare-redirects' ),
				'status'      => __( 'Status code', 'simple-csv-cloudflare-redirects' ),
				'enabled'     => __( 'Enabled', 'simple-csv-cloudflare-redirects' ),
			];
		}

		public function prepare_items() {
			$this->_column_headers = [ $this->get_columns(), [], [], 'description' ];

			$per_page = 100;
			$page     = $this->get_pagenum();
			$offset   = ( $page - 1 ) * $per_page;

			$this->items = [];
			foreach ( array_slice( $this->rows, $offset, $per_page ) as $i => $row ) {
				$row['_index'] = $offset + $i;
				$this->items[] = $row;
			}
			$this->set_pagination_args(
				[
					'total_items' => count( $this->rows ),
					'per_page'    => $per_page,
				]
			);
		}

		public function no_items() {
			esc_html_e( 'No CSV rules yet.', 'simple-csv-cloudflare-redirects' );
		}

		protected function get_primary_column_name() {
			return 'description';
		}

		public function column_order( $row ) {
			return (int) $row['_index'] + 1;
		}

		public function column_description( $row ) {
			$i     = (int) $row['_index'];
			$label = '' !== $row['description'] ? $row['description'] : __( '(no description)', 'simple-csv-cloudflare-redirects' );
			$edit  = $this->admin->page_url( [ 'action' => 'csv-edit', 'row' => $i + 1 ] );
			$out   = sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $edit ), esc_html( $label ) );

			$actions = [
				'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'simple-csv-cloudflare-redirects' ) ),
			];
			if ( $i > 0 ) {
				$actions['move_up'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->admin->csv_action_url( 'rfcf_csv_move', $i, $this->file, [ 'dir' => 'up' ] ) ), esc_html__( 'Move up', 'simple-csv-cloudflare-redirects' ) );
			}
			if ( $i < count( $this->rows ) - 1 ) {
				$actions['move_down'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->admin->csv_action_url( 'rfcf_csv_move', $i, $this->file, [ 'dir' => 'down' ] ) ), esc_html__( 'Move down', 'simple-csv-cloudflare-redirects' ) );
			}
			$actions['toggle'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->admin->csv_action_url( 'rfcf_csv_toggle', $i, $this->file ) ),
				$row['enabled'] ? esc_html__( 'Disable', 'simple-csv-cloudflare-redirects' ) : esc_html__( 'Enable', 'simple-csv-cloudflare-redirects' )
			);
			$actions['delete'] = sprintf(
				'<a class="submitdelete" href="%s" onclick="return showNotice.warn();">%s</a>',
				esc_url( $this->admin->csv_action_url( 'rfcf_csv_delete', $i, $this->file ) ),
				esc_html__( 'Delete', 'simple-csv-cloudflare-redirects' )
			);

			return $out . $this->row_actions( $actions );
		}

		public function column_source( $row ) {
			return '<code>' . esc_html( $row['source'] ) . '</code>';
		}

		public function column_target( $row ) {
			return '<code>' . esc_html( $row['target'] ) . '</code>';
		}

		public function column_status( $row ) {
			return (int) $row['status'];
		}

		public function column_enabled( $row ) {
			return $row['enabled'] ? esc_html__( 'Yes', 'simple-csv-cloudflare-redirects' ) : esc_html__( 'No', 'simple-csv-cloudflare-redirects' );
		}
	}
}
