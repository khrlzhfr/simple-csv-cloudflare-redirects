<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tools → Redirects.
 */
final class RFCF_Admin {

	const LOG_OPTION = 'rfcf_log';
	const LOG_MAX    = 500;
	const SLUG       = 'rfcf-redirects';
	const STATUSES   = [ 301, 302, 303, 307, 308 ];

	/** Single Redirect rule limits per Cloudflare plan. Cloudflare's own error is the final word. */
	const PLAN_LIMITS = [
		'free'       => 10,
		'pro'        => 25,
		'business'   => 50,
		'enterprise' => 300,
	];

	public static function init() {
		$self = new self();
		add_action( 'admin_menu', [ $self, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $self, 'enqueue' ] );
		foreach ( [ 'save', 'toggle', 'delete', 'move' ] as $a ) {
			add_action( 'admin_post_rfcf_' . $a . '_rule', [ $self, 'handle_' . $a . '_rule' ] );
		}
		add_action( 'admin_post_rfcf_upload_csv', [ $self, 'handle_upload_csv' ] );
		add_action( 'admin_post_rfcf_download_csv', [ $self, 'handle_download_csv' ] );
		foreach ( [ 'save', 'toggle', 'delete', 'move' ] as $a ) {
			add_action( 'admin_post_rfcf_csv_' . $a, [ $self, 'handle_csv_' . $a ] );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( RFCF_FILE ), [ $self, 'action_links' ] );
	}

	/* ------------------------------------------------------------------ *
	 * Cloudflare configuration
	 * ------------------------------------------------------------------ */

	private function token() {
		$cf = RFCF_Config::cloudflare();
		return $cf ? $cf['token'] : '';
	}

	private function api() {
		return new RFCF_API( $this->token() );
	}

	/** @return array zone_id => domain */
	private function allowed_zones() {
		return $this->resolve_domains()['zones'];
	}

	/** Turns the configured domains (or zone IDs) into zone_id => name, cached. */
	private function resolve_domains( $refresh = false ) {
		$out = [ 'zones' => [], 'errors' => [] ];
		$c   = RFCF_Config::cloudflare();
		if ( ! $c ) {
			return $out;
		}

		$key = 'rfcf_domains_' . md5( $c['token'] . '|' . implode( ',', $c['domains'] ) );
		if ( ! $refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$api = $this->api();
		foreach ( $c['domains'] as $item ) {
			$z = preg_match( '/^[a-f0-9]{32}$/', $item ) ? $api->get_zone( $item ) : $api->find_zone( $item );
			if ( is_wp_error( $z ) ) {
				$out['errors'][] = $item . ': ' . $this->error_message( $z );
			} else {
				$out['zones'][ $z['id'] ] = $z['name'];
			}
		}
		asort( $out['zones'] );

		set_transient( $key, $out, $out['errors'] ? 5 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/** @return array{plan:string,limit:int}|null */
	private function zone_plan( $zone ) {
		$key    = 'rfcf_plan_' . $zone;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}
		$z    = $this->api()->get_zone( $zone );
		$plan = [];
		if ( ! is_wp_error( $z ) ) {
			$id = strtolower( (string) ( $z['plan']['legacy_id'] ?? '' ) );
			if ( isset( self::PLAN_LIMITS[ $id ] ) ) {
				$plan = [
					'plan'  => (string) ( $z['plan']['name'] ?? ucfirst( $id ) ),
					'limit' => self::PLAN_LIMITS[ $id ],
				];
			}
		}
		set_transient( $key, $plan, HOUR_IN_SECONDS );
		return $plan ? $plan : null;
	}

	/* ------------------------------------------------------------------ *
	 * Change log (per site)
	 * ------------------------------------------------------------------ */

	public function log_entries() {
		$log = get_option( self::LOG_OPTION, [] );
		return is_array( $log ) ? $log : [];
	}

	private function log( $event, $zone_name, $rule = '', $before = '', $after = '' ) {
		$log = $this->log_entries();
		array_unshift(
			$log,
			[
				'time'   => time(),
				'user'   => get_current_user_id(),
				'zone'   => (string) $zone_name,
				'event'  => $event,
				'rule'   => (string) $rule,
				'before' => (string) $before,
				'after'  => (string) $after,
			]
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	public function event_label( $event ) {
		$labels = [
			'added'    => __( 'Added', 'simple-csv-cloudflare-redirects' ),
			'updated'  => __( 'Edited', 'simple-csv-cloudflare-redirects' ),
			'enabled'  => __( 'Enabled', 'simple-csv-cloudflare-redirects' ),
			'disabled' => __( 'Disabled', 'simple-csv-cloudflare-redirects' ),
			'deleted'  => __( 'Deleted', 'simple-csv-cloudflare-redirects' ),
			'moved'    => __( 'Moved', 'simple-csv-cloudflare-redirects' ),
			'csv'      => __( 'CSV uploaded', 'simple-csv-cloudflare-redirects' ),
		];
		return $labels[ $event ] ?? $event;
	}

	/** One-line summary of a rule for the log. */
	private function summarize( array $rule ) {
		$f    = $this->rule_to_form( $rule );
		$when = 'simple' === $f['mode'] ? $f['source_url'] : $f['expression'];
		$then = 'simple' === $f['mode'] ? $f['target_url'] : $f['target_value'];
		$s    = sprintf( '%s → %s (%d)', $when, $then, $f['status_code'] );
		if ( $f['preserve_query_string'] ) {
			$s .= ' +query';
		}
		if ( ! $f['enabled'] ) {
			$s .= ' [' . __( 'disabled', 'simple-csv-cloudflare-redirects' ) . ']';
		}
		return $s;
	}

	private function rule_label( array $rule ) {
		return '' !== ( $rule['description'] ?? '' ) ? $rule['description'] : __( '(no description)', 'simple-csv-cloudflare-redirects' );
	}

	/* ------------------------------------------------------------------ *
	 * URLs, notices, guards
	 * ------------------------------------------------------------------ */

	public function page_url( $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'tools.php' ) );
	}

	/** URL of the Cloudflare Rules tab. */
	public function cf_url( $args = [] ) {
		return $this->page_url( array_merge( [ 'tab' => 'cloudflare' ], $args ) );
	}

	public function action_url( $action, $zone, $rule_id, $extra = [] ) {
		return wp_nonce_url(
			add_query_arg(
				array_merge(
					[
						'action' => $action,
						'zone'   => $zone,
						'rule'   => $rule_id,
					],
					$extra
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $rule_id
		);
	}

	private function notice( $type, $message ) {
		set_transient( 'rfcf_notice_' . get_current_user_id(), [ 'type' => $type, 'message' => $message ], 60 );
	}

	private function render_notices() {
		$key = 'rfcf_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( $n ) {
			delete_transient( $key );
			wp_admin_notice( esc_html( $n['message'] ), [ 'type' => $n['type'], 'dismissible' => true ] );
		}
	}

	private function error_message( WP_Error $e ) {
		$msg  = $e->get_error_message();
		$data = $e->get_error_data();
		if ( isset( $data['status'] ) && in_array( (int) $data['status'], [ 401, 403 ], true ) ) {
			$msg .= ' ' . __( 'Check that the API token has the Single Redirect permission (Read, or Edit for changes) on this zone.', 'simple-csv-cloudflare-redirects' );
		}
		return $msg;
	}

	private function guard( $nonce_action ) {
		if ( ! RFCF_Config::user_can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'simple-csv-cloudflare-redirects' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	/** Zone ID from the request, only if it is on the allowed list. */
	private function requested_zone( $source ) {
		$zone  = isset( $source['zone'] ) ? sanitize_key( wp_unslash( $source['zone'] ) ) : '';
		$zones = $this->allowed_zones();
		return ( $zone && isset( $zones[ $zone ] ) ) ? $zone : '';
	}

	private function deny_zone() {
		wp_die( esc_html__( 'This website is not on the allowed list.', 'simple-csv-cloudflare-redirects' ), 403 );
	}

	private function back_to_list( $zone ) {
		wp_safe_redirect( $this->cf_url( [ 'zone' => $zone ] ) );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Menu
	 * ------------------------------------------------------------------ */

	/**
	 * A posted text field, unslashed and sanitised.
	 * Nonces are checked by guard() in every handler before this is called.
	 */
	private function post_text( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
	}

	/**
	 * A posted URL, path or rule expression, unslashed and sanitised with sanitize_line().
	 * sanitize_text_field() would strip %-encoded characters these values need.
	 */
	private function post_line( $key ) {
		return isset( $_POST[ $key ] ) ? $this->sanitize_line( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in guard(); sanitised by sanitize_line().
	}

	/** Single line of plain text: control characters removed, tags stripped, trimmed. */
	private function sanitize_line( $value ) {
		return trim( wp_strip_all_tags( preg_replace( '/[\x00-\x1F\x7F]/', ' ', (string) $value ) ) );
	}

	/** Whether a posted checkbox is ticked. */
	private function post_flag( $key ) {
		return ! empty( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
	}

	/** Screen hook of Tools → Redirects. */
	private $hook = '';

	public function menu() {
		if ( ! RFCF_Config::user_can_manage() ) {
			return;
		}
		$this->hook = (string) add_management_page(
			__( 'Redirects', 'simple-csv-cloudflare-redirects' ),
			__( 'Redirects', 'simple-csv-cloudflare-redirects' ),
			'read',
			self::SLUG,
			[ $this, 'render_page' ]
		);
	}

	/** Shows the matching fields when switching a Cloudflare rule between URL pattern and custom expression. */
	public function enqueue( $hook ) {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		wp_register_script( 'rfcf-admin', false, [], RFCF_VERSION, true );
		wp_enqueue_script( 'rfcf-admin' );
		wp_add_inline_script(
			'rfcf-admin',
			"( function () {\n"
			. "\tvar form = document.getElementById( 'rfcf-rule-form' );\n"
			. "\tif ( ! form ) { return; }\n"
			. "\tform.querySelectorAll( 'input[name=\"mode\"]' ).forEach( function ( radio ) {\n"
			. "\t\tradio.addEventListener( 'change', function () {\n"
			. "\t\t\tvar simple = this.value === 'simple';\n"
			. "\t\t\tform.querySelectorAll( '.rfcf-simple' ).forEach( function ( row ) { row.hidden = ! simple; } );\n"
			. "\t\t\tform.querySelectorAll( '.rfcf-custom' ).forEach( function ( row ) { row.hidden = simple; } );\n"
			. "\t\t} );\n"
			. "\t} );\n"
			. '} )();'
		);
	}

	public function action_links( $links ) {
		if ( RFCF_Config::user_can_manage() ) {
			array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( $this->page_url() ), esc_html__( 'Redirects', 'simple-csv-cloudflare-redirects' ) ) );
		}
		return $links;
	}

	/* ------------------------------------------------------------------ *
	 * Page
	 * ------------------------------------------------------------------ */

	public function render_page() {
		if ( ! RFCF_Config::user_can_manage() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
		$refresh = ! empty( $_GET['refresh'] ) && current_user_can( 'manage_options' );
		// phpcs:enable

		$cf       = (bool) RFCF_Config::cloudflare();
		$resolved = $cf ? $this->resolve_domains( $refresh ) : [ 'zones' => [], 'errors' => [] ];
		$cf_ready = (bool) $resolved['zones'];

		if ( in_array( $action, [ 'csv-add', 'csv-edit' ], true ) ) {
			$this->render_csv_edit_screen( $action );
			return;
		}
		if ( $cf_ready && in_array( $action, [ 'add', 'edit' ], true ) ) {
			$this->render_edit_screen( $action );
			return;
		}

		$tabs = [ 'csv' => __( 'CSV Rules', 'simple-csv-cloudflare-redirects' ) ];
		if ( $cf ) {
			$tabs['cloudflare'] = __( 'Cloudflare Rules', 'simple-csv-cloudflare-redirects' );
		}
		$tabs['log']   = __( 'Change log', 'simple-csv-cloudflare-redirects' );
		$tabs['setup'] = __( 'Setup', 'simple-csv-cloudflare-redirects' );
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'csv';
		}
		$csv_file = RFCF_CSV::current_file();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Redirects', 'simple-csv-cloudflare-redirects' ); ?></h1>
			<?php if ( 'csv' === $tab ) : ?>
				<a href="<?php echo esc_url( $this->page_url( [ 'action' => 'csv-add' ] ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Rule', 'simple-csv-cloudflare-redirects' ); ?></a>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rfcf_download_csv' ), 'rfcf_download_csv' ) ); ?>" class="page-title-action"><?php echo $csv_file ? esc_html__( 'Download CSV', 'simple-csv-cloudflare-redirects' ) : esc_html__( 'Download Template', 'simple-csv-cloudflare-redirects' ); ?></a>
			<?php elseif ( 'cloudflare' === $tab && $cf_ready ) : ?>
				<a href="<?php echo esc_url( $this->page_url( [ 'action' => 'add', 'zone' => $this->current_zone() ] ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Rule', 'simple-csv-cloudflare-redirects' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php
			$this->render_notices();
			if ( 'cloudflare' === $tab ) {
				$this->render_config_errors( $resolved['errors'] );
			}
			?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Secondary menu', 'simple-csv-cloudflare-redirects' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( $this->page_url( 'csv' === $key ? [] : [ 'tab' => $key ] ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'setup' === $tab ) {
				$this->render_instructions();
			} elseif ( 'log' === $tab ) {
				$this->render_log_tab();
			} elseif ( 'cloudflare' === $tab ) {
				if ( $cf_ready ) {
					$this->render_rules_tab();
				}
			} else {
				$this->render_csv_tab( $csv_file );
			}
			?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * CSV Rules tab
	 * ------------------------------------------------------------------ */

	private function render_csv_tab( $file ) {
		$csv   = RFCF_CSV::current();
		$count = count( $csv['rows'] );
		if ( $file ) {
			printf(
				'<p>%s %s</p>',
				esc_html(
					sprintf(
						/* translators: 1: number of rules, 2: file name, 3: date */
						_n( '%1$d rule in %2$s, last changed %3$s.', '%1$d rules in %2$s, last changed %3$s.', $count, 'simple-csv-cloudflare-redirects' ),
						$count,
						$csv['file'],
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) filemtime( $file ) )
					)
				),
				esc_html__( 'WordPress checks enabled rules from top to bottom and applies the first one that matches.', 'simple-csv-cloudflare-redirects' )
			);
		} else {
			echo '<p>' . esc_html__( 'No CSV rules yet. Add rules here, or download the template, fill it in with a spreadsheet app and upload it below.', 'simple-csv-cloudflare-redirects' ) . '</p>';
		}

		rfcf_load_list_tables();
		$table = new RFCF_CSV_Table( $this, $csv['rows'], $csv['file'] );
		$table->prepare_items();
		?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php $table->display(); ?>
		</form>
		<h2><?php esc_html_e( 'Upload CSV', 'simple-csv-cloudflare-redirects' ); ?></h2>
		<p><?php esc_html_e( 'Uploading replaces all CSV rules with the file’s contents. The file is checked first; if any row has a problem, nothing changes. Download the CSV first if you want a copy of the current rules.', 'simple-csv-cloudflare-redirects' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="rfcf_upload_csv">
			<?php wp_nonce_field( 'rfcf_upload_csv' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rfcf-csv"><?php esc_html_e( 'CSV file', 'simple-csv-cloudflare-redirects' ); ?></label></th>
					<td>
						<input type="file" id="rfcf-csv" name="csv" accept=".csv,text/csv" required>
						<p class="description"><?php esc_html_e( 'Columns: description, source, target, status, preserve_query_string, enabled. Only source and target are required.', 'simple-csv-cloudflare-redirects' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Upload CSV', 'simple-csv-cloudflare-redirects' ) ); ?>
		</form>
		<?php
	}

	/** Admin-post URL for a CSV row action, tied to the file the page was showing. */
	public function csv_action_url( $action, $index, $file, $extra = [] ) {
		return wp_nonce_url(
			add_query_arg(
				array_merge(
					[
						'action' => $action,
						'row'    => $index + 1,
						'file'   => $file,
					],
					$extra
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . ( $index + 1 )
		);
	}

	private function csv_blank_form() {
		return [
			'row'                   => 0,
			'file'                  => '',
			'description'           => '',
			'source'                => '',
			'target'                => '',
			'status'                => 301,
			'preserve_query_string' => false,
			'enabled'               => true,
			'position'              => 'bottom',
		];
	}

	private function render_csv_edit_screen( $action ) {
		$csv     = RFCF_CSV::current();
		$editing = 'csv-edit' === $action;
		$n       = isset( $_GET['row'] ) ? absint( $_GET['row'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, or nonce checked in guard().
		$form    = $this->csv_blank_form();
		$error   = '';

		if ( $editing ) {
			if ( ! isset( $csv['rows'][ $n - 1 ] ) ) {
				$error = __( 'That CSV rule no longer exists.', 'simple-csv-cloudflare-redirects' );
			} else {
				$form = array_merge( $form, $csv['rows'][ $n - 1 ], [ 'row' => $n ] );
			}
		}
		$form['file'] = $csv['file'];

		$state_key = 'rfcf_csvform_' . get_current_user_id();
		$restored  = get_transient( $state_key );
		if ( is_array( $restored ) ) {
			delete_transient( $state_key );
			$form = array_merge( $form, $restored, [ 'file' => $csv['file'] ] );
		}
		?>
		<div class="wrap">
			<h1><?php echo $editing ? esc_html__( 'Edit CSV Rule', 'simple-csv-cloudflare-redirects' ) : esc_html__( 'Add CSV Rule', 'simple-csv-cloudflare-redirects' ); ?></h1>
			<?php $this->render_notices(); ?>

			<?php if ( $error ) : ?>
				<?php wp_admin_notice( esc_html( $error ), [ 'type' => 'error' ] ); ?>
				<p><a href="<?php echo esc_url( $this->page_url() ); ?>">&larr; <?php esc_html_e( 'Back to Redirects', 'simple-csv-cloudflare-redirects' ); ?></a></p>
			</div>
				<?php
				return;
			endif;
			?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rfcf_csv_save">
				<input type="hidden" name="row" value="<?php echo (int) $form['row']; ?>">
				<input type="hidden" name="file" value="<?php echo esc_attr( $form['file'] ); ?>">
				<?php wp_nonce_field( 'rfcf_csv_save' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rfcf-csv-desc"><?php esc_html_e( 'Description', 'simple-csv-cloudflare-redirects' ); ?></label></th>
						<td><input type="text" id="rfcf-csv-desc" name="description" class="regular-text" value="<?php echo esc_attr( $form['description'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="rfcf-csv-src"><?php esc_html_e( 'Source path', 'simple-csv-cloudflare-redirects' ); ?></label></th>
						<td>
							<input type="text" id="rfcf-csv-src" name="source" class="large-text code" value="<?php echo esc_attr( $form['source'] ); ?>" required>
							<p class="description"><?php esc_html_e( 'A path on this site starting with /, for example /old/*. Use * as a wildcard. Matching ignores letter case, trailing slashes and the query string.', 'simple-csv-cloudflare-redirects' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rfcf-csv-tgt"><?php esc_html_e( 'Redirect to', 'simple-csv-cloudflare-redirects' ); ?></label></th>
						<td>
							<input type="text" id="rfcf-csv-tgt" name="target" class="large-text code" value="<?php echo esc_attr( $form['target'] ); ?>" required>
							<p class="description"><?php esc_html_e( 'A path on this site or a full URL, for example /new/${1} or https://example.org/new/${1}. ${1}, ${2}… insert what each * matched.', 'simple-csv-cloudflare-redirects' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rfcf-csv-status"><?php esc_html_e( 'Status code', 'simple-csv-cloudflare-redirects' ); ?></label></th>
						<td>
							<select id="rfcf-csv-status" name="status">
								<?php foreach ( RFCF_CSV::STATUSES as $code ) : ?>
									<option value="<?php echo (int) $code; ?>" <?php selected( (int) $form['status'], $code ); ?>><?php echo (int) $code; ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( '301 permanent, 302 temporary. 307 and 308 keep the request method.', 'simple-csv-cloudflare-redirects' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'simple-csv-cloudflare-redirects' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><span><?php esc_html_e( 'Options', 'simple-csv-cloudflare-redirects' ); ?></span></legend>
								<label><input type="checkbox" name="preserve_query_string" value="1" <?php checked( $form['preserve_query_string'] ); ?>> <?php esc_html_e( 'Preserve query string', 'simple-csv-cloudflare-redirects' ); ?></label><br>
								<label><input type="checkbox" name="enabled" value="1" <?php checked( $form['enabled'] ); ?>> <?php esc_html_e( 'Enabled', 'simple-csv-cloudflare-redirects' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<?php if ( ! $editing ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Position', 'simple-csv-cloudflare-redirects' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><span><?php esc_html_e( 'Position', 'simple-csv-cloudflare-redirects' ); ?></span></legend>
									<label><input type="radio" name="position" value="bottom" <?php checked( $form['position'], 'bottom' ); ?>> <?php esc_html_e( 'Last (checked after existing rules)', 'simple-csv-cloudflare-redirects' ); ?></label><br>
									<label><input type="radio" name="position" value="top" <?php checked( $form['position'], 'top' ); ?>> <?php esc_html_e( 'First (checked before existing rules)', 'simple-csv-cloudflare-redirects' ); ?></label>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Put specific rules above broad wildcard rules. You can move rules later from the list.', 'simple-csv-cloudflare-redirects' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
				</table>

				<p class="submit">
					<?php submit_button( $editing ? __( 'Update Rule', 'simple-csv-cloudflare-redirects' ) : __( 'Add Rule', 'simple-csv-cloudflare-redirects' ), 'primary', 'submit', false ); ?>
					<a href="<?php echo esc_url( $this->page_url() ); ?>" class="button"><?php esc_html_e( 'Cancel', 'simple-csv-cloudflare-redirects' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/** Stops if the CSV changed since the page that sent this request was loaded. */
	private function csv_check_fresh( $posted_file, $back ) {
		$current = (string) RFCF_CSV::current()['file'];
		if ( sanitize_file_name( (string) $posted_file ) !== $current ) {
			$this->notice( 'error', __( 'The CSV changed since you opened this page, so nothing was saved. Check the list and try again.', 'simple-csv-cloudflare-redirects' ) );
			wp_safe_redirect( $back );
			exit;
		}
	}

	private function csv_save_or_fail( array $rows, $back ) {
		$res = RFCF_CSV::save_rows( $rows );
		if ( is_wp_error( $res ) ) {
			$this->notice( 'error', $res->get_error_message() );
			wp_safe_redirect( $back );
			exit;
		}
	}

	public function handle_csv_save() {
		$this->guard( 'rfcf_csv_save' );
		$n    = absint( $this->post_text( 'row' ) );
		$list = $this->page_url();
		$back = $this->page_url( $n ? [ 'action' => 'csv-edit', 'row' => $n ] : [ 'action' => 'csv-add' ] );

		$status = absint( $this->post_text( 'status' ) );
		$row    = [
			'description'           => $this->post_text( 'description' ),
			'source'                => $this->post_line( 'source' ),
			'target'                => $this->post_line( 'target' ),
			'status'                => (string) ( $status ? $status : 301 ),
			'preserve_query_string' => $this->post_flag( 'preserve_query_string' ),
			'enabled'               => $this->post_flag( 'enabled' ),
		];
		$position = 'top' === $this->post_text( 'position' ) ? 'top' : 'bottom';

		$problem = RFCF_CSV::validate( $row );
		if ( $problem ) {
			set_transient( 'rfcf_csvform_' . get_current_user_id(), array_merge( $row, [ 'row' => $n, 'position' => $position ] ), 5 * MINUTE_IN_SECONDS );
			$this->notice( 'error', ucfirst( $problem ) );
			wp_safe_redirect( $back );
			exit;
		}
		$row['status'] = (int) $row['status'];

		$this->csv_check_fresh( $this->post_text( 'file' ), $back );
		$rows = RFCF_CSV::current()['rows'];

		if ( $n ) {
			if ( ! isset( $rows[ $n - 1 ] ) ) {
				$this->notice( 'error', __( 'That CSV rule no longer exists.', 'simple-csv-cloudflare-redirects' ) );
				wp_safe_redirect( $list );
				exit;
			}
			$before         = $rows[ $n - 1 ];
			$rows[ $n - 1 ] = $row;
			$this->csv_save_or_fail( $rows, $back );
			$this->log( 'updated', 'CSV', $this->csv_label( $row ), RFCF_CSV::summarize( $before ), RFCF_CSV::summarize( $row ) );
			$this->notice( 'success', __( 'CSV rule updated.', 'simple-csv-cloudflare-redirects' ) );
		} else {
			if ( count( $rows ) >= RFCF_CSV::MAX_ROWS ) {
				$this->notice( 'error', __( 'The CSV already has the maximum number of rules.', 'simple-csv-cloudflare-redirects' ) );
				wp_safe_redirect( $list );
				exit;
			}
			if ( 'top' === $position ) {
				array_unshift( $rows, $row );
			} else {
				$rows[] = $row;
			}
			$this->csv_save_or_fail( $rows, $back );
			$this->log( 'added', 'CSV', $this->csv_label( $row ), '', RFCF_CSV::summarize( $row ) );
			$this->notice( 'success', __( 'CSV rule added.', 'simple-csv-cloudflare-redirects' ) );
		}
		wp_safe_redirect( $list );
		exit;
	}

	/** Shared start for CSV row actions. @return array{0:array,1:int} rows, 0-based index */
	private function csv_row_action_start( $action ) {
		$n = isset( $_GET['row'] ) ? absint( $_GET['row'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, or nonce checked in guard().
		$this->guard( $action . '_' . $n );
		$list = $this->page_url();
		$this->csv_check_fresh( wp_unslash( $_GET['file'] ?? '' ), $list ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, or nonce checked in guard().

		$rows = RFCF_CSV::current()['rows'];
		if ( ! isset( $rows[ $n - 1 ] ) ) {
			$this->notice( 'error', __( 'That CSV rule no longer exists.', 'simple-csv-cloudflare-redirects' ) );
			wp_safe_redirect( $list );
			exit;
		}
		return [ $rows, $n - 1 ];
	}

	private function csv_label( array $row ) {
		return '' !== $row['description'] ? $row['description'] : __( '(no description)', 'simple-csv-cloudflare-redirects' );
	}

	public function handle_csv_toggle() {
		list( $rows, $i ) = $this->csv_row_action_start( 'rfcf_csv_toggle' );
		$rows[ $i ]['enabled'] = ! $rows[ $i ]['enabled'];
		$this->csv_save_or_fail( $rows, $this->page_url() );

		$on = $rows[ $i ]['enabled'];
		$this->log( $on ? 'enabled' : 'disabled', 'CSV', $this->csv_label( $rows[ $i ] ), '', RFCF_CSV::summarize( $rows[ $i ] ) );
		$this->notice( 'success', $on ? __( 'CSV rule enabled.', 'simple-csv-cloudflare-redirects' ) : __( 'CSV rule disabled.', 'simple-csv-cloudflare-redirects' ) );
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	public function handle_csv_delete() {
		list( $rows, $i ) = $this->csv_row_action_start( 'rfcf_csv_delete' );
		$gone = $rows[ $i ];
		array_splice( $rows, $i, 1 );
		$this->csv_save_or_fail( $rows, $this->page_url() );

		$this->log( 'deleted', 'CSV', $this->csv_label( $gone ), RFCF_CSV::summarize( $gone ) );
		$this->notice( 'success', __( 'CSV rule deleted.', 'simple-csv-cloudflare-redirects' ) );
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	public function handle_csv_move() {
		list( $rows, $i ) = $this->csv_row_action_start( 'rfcf_csv_move' );
		$dir = ( isset( $_GET['dir'] ) && 'down' === $_GET['dir'] ) ? 'down' : 'up'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, or nonce checked in guard().
		$j   = 'up' === $dir ? $i - 1 : $i + 1;

		if ( isset( $rows[ $j ] ) ) {
			$tmp        = $rows[ $i ];
			$rows[ $i ] = $rows[ $j ];
			$rows[ $j ] = $tmp;
			$this->csv_save_or_fail( $rows, $this->page_url() );

			/* translators: %d: rule position */
			$pos = __( 'Position %d', 'simple-csv-cloudflare-redirects' );
			$this->log( 'moved', 'CSV', $this->csv_label( $tmp ), sprintf( $pos, $i + 1 ), sprintf( $pos, $j + 1 ) );
			$this->notice( 'success', __( 'CSV rule moved.', 'simple-csv-cloudflare-redirects' ) );
		}
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	public function handle_upload_csv() {
		$this->guard( 'rfcf_upload_csv' );
		$back = $this->page_url();

		$f = $_FILES['csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $f || ! isset( $f['error'] ) || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
			$this->notice( 'error', __( 'The upload failed. Choose a CSV file and try again.', 'simple-csv-cloudflare-redirects' ) );
			wp_safe_redirect( $back );
			exit;
		}
		if ( 'csv' !== strtolower( pathinfo( (string) $f['name'], PATHINFO_EXTENSION ) ) ) {
			$this->notice( 'error', __( 'Only .csv files can be uploaded.', 'simple-csv-cloudflare-redirects' ) );
			wp_safe_redirect( $back );
			exit;
		}
		if ( (int) $f['size'] > RFCF_CSV::MAX_BYTES ) {
			$this->notice( 'error', __( 'The file is larger than 2 MB.', 'simple-csv-cloudflare-redirects' ) );
			wp_safe_redirect( $back );
			exit;
		}

		$content = (string) file_get_contents( $f['tmp_name'] );
		$parsed  = RFCF_CSV::parse( $content );
		if ( $parsed['errors'] ) {
			$shown = array_slice( $parsed['errors'], 0, 10 );
			$more  = count( $parsed['errors'] ) - count( $shown );
			$msg   = __( 'The CSV was not uploaded. Fix these problems and try again:', 'simple-csv-cloudflare-redirects' ) . ' ' . implode( ' ', $shown );
			if ( $more > 0 ) {
				/* translators: %d: number of further problems */
				$msg .= ' ' . sprintf( _n( '(and %d more problem)', '(and %d more problems)', $more, 'simple-csv-cloudflare-redirects' ), $more );
			}
			$this->notice( 'error', $msg );
			wp_safe_redirect( $back );
			exit;
		}

		$old       = RFCF_CSV::current_file();
		$old_count = $old ? count( RFCF_CSV::parse( (string) file_get_contents( $old ) )['rows'] ) : 0;

		$new = RFCF_CSV::store( $content );
		if ( is_wp_error( $new ) ) {
			$this->notice( 'error', $new->get_error_message() );
			wp_safe_redirect( $back );
			exit;
		}

		$count = count( $parsed['rows'] );
		/* translators: 1: file name, 2: number of rules */
		$fmt = __( '%1$s (%2$d rules)', 'simple-csv-cloudflare-redirects' );
		$this->log(
			'csv',
			'CSV',
			basename( $new ),
			$old ? sprintf( $fmt, basename( $old ), $old_count ) : '',
			sprintf( $fmt, basename( $new ), $count )
		);
		/* translators: %d: number of redirects */
		$this->notice( 'success', sprintf( _n( 'CSV uploaded with %d rule.', 'CSV uploaded with %d rules.', $count, 'simple-csv-cloudflare-redirects' ), $count ) );
		wp_safe_redirect( $back );
		exit;
	}

	public function handle_download_csv() {
		$this->guard( 'rfcf_download_csv' );
		$file = RFCF_CSV::current_file();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . ( $file ? basename( $file ) : 'redirects-template.csv' ) . '"' );
		if ( $file ) {
			readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams this plugin's own CSV as a download.
		} else {
			echo RFCF_CSV::TEMPLATE; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain CSV download, not HTML; the template is a constant.
		}
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Setup tab
	 * ------------------------------------------------------------------ */

	private function render_instructions() {
		$single = "define( 'RFCF_CONFIG', [\n"
			. "\t'token'   => 'cfut_your-api-token',\n"
			. "\t'domains' => [ 'example.com', 'another.example.net' ],\n"
			. "] );";

		$site  = get_current_blog_id();
		$multi = "define( 'RFCF_CONFIG', [\n"
			. "\t// Defaults for every site\n"
			. "\t'token'   => 'cfut_your-api-token',\n"
			. "\t'domains' => [ 'example.com' ],\n\n"
			. "\t// This site (ID {$site}): replaces only the keys it sets\n"
			. "\t{$site} => [\n"
			. "\t\t'domains' => [ 'another.example.net' ],\n"
			. "\t\t'roles'   => [ 'administrator' ],\n"
			. "\t],\n\n"
			. "\t// Switch a site off entirely\n"
			. "\t// 5 => false,\n"
			. "] );";

		$csv_dir = ltrim( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( RFCF_CSV::dir() ) ), '/' ) . '/';
		?>
		<h2><?php esc_html_e( 'CSV Rules', 'simple-csv-cloudflare-redirects' ); ?></h2>
		<p><?php esc_html_e( 'CSV rules work without any setup. Add and edit them on the CSV Rules tab, or download the CSV, edit it in a spreadsheet app and upload it again.', 'simple-csv-cloudflare-redirects' ); ?></p>
		<pre><code><?php echo esc_html( RFCF_CSV::TEMPLATE ); ?></code></pre>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'description is a note for people; it isn’t used for matching.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'source is a path on this site, starting with /. Use * as a wildcard. Letter case, trailing slashes and the query string are ignored when matching.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'target is a path on this site or a full URL. ${1}, ${2}… insert what each * matched.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'status is 301 (default), 302, 303, 307 or 308.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'preserve_query_string is yes or no (default no). Yes passes the visitor’s query string on to the target.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'enabled is yes or no (default yes). Disabled rules stay in the file but are skipped.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'Only source and target are required. Rules are checked from top to bottom and the first match wins, even when a page exists at that path.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li>
				<?php
				/* translators: %s: folder path */
				echo esc_html( sprintf( __( 'The file is kept in %s. Every change saves a new file named with the time of the change and removes the old one.', 'simple-csv-cloudflare-redirects' ), $csv_dir ) );
				?>
			</li>
			<li><?php esc_html_e( 'CSV rules run inside WordPress, so they don’t apply to pages served from a cache or to files such as PDFs. Use Cloudflare Rules for those.', 'simple-csv-cloudflare-redirects' ); ?></li>
		</ul>

		<h2><?php esc_html_e( 'Cloudflare Rules (optional)', 'simple-csv-cloudflare-redirects' ); ?></h2>
		<?php if ( ! RFCF_Config::cloudflare() ) : ?>
			<?php wp_admin_notice( esc_html__( 'Cloudflare Rules are not set up for this site yet. Follow the steps below, then reload this page.', 'simple-csv-cloudflare-redirects' ), [ 'type' => 'warning', 'additional_classes' => [ 'inline' ] ] ); ?>
		<?php endif; ?>
		<p><?php esc_html_e( 'Cloudflare Rules redirect at Cloudflare’s edge, before a request reaches this server. The Free plan allows 10 rules per domain.', 'simple-csv-cloudflare-redirects' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Log in to the Cloudflare dashboard and open My Profile.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'Under API credentials, open API Tokens.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'Select Create Token, then Get started next to Create Custom Token.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'Give the token a name, for example “WordPress redirects – your site name”.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'Under Permissions, add two rows: Zone → Single Redirect → Edit, and Zone → Zone → Read.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'Under Zone Resources, choose Include → Specific zone and pick each domain this site should manage. Add a row per domain.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'Optional: under Client IP Address Filtering, allow only this web server’s IP address.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'Select Continue to summary, then Create Token. Copy the token now; Cloudflare shows it only once. It starts with cfut_.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'Open wp-config.php and paste the following above the line that says “That’s all, stop editing!”:', 'simple-csv-cloudflare-redirects' ); ?></li>
		</ol>
		<pre><code><?php echo esc_html( is_multisite() ? $multi : $single ); ?></code></pre>
		<ol start="10">
			<li><?php esc_html_e( 'Replace the token and domains with your own. Domains are the names of the zones in Cloudflare (or their 32-character zone IDs).', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'Save the file and reload this page.', 'simple-csv-cloudflare-redirects' ); ?></li>
		</ol>

		<h2><?php esc_html_e( 'Good to know', 'simple-csv-cloudflare-redirects' ); ?></h2>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'Administrators and editors can manage redirects by default. Add \'roles\' => [ \'administrator\' ] to RFCF_CONFIG to change that.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<li><?php esc_html_e( 'An account API token (Manage Account → Account API Tokens, starts with cfat_) also works, with the same permissions.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<?php if ( is_multisite() ) : ?>
				<li><?php esc_html_e( 'On multisite, the top-level settings apply to every site. An entry keyed by site ID replaces only the settings it lists for that site; false switches the plugin off for that site. Network super admins always have access.', 'simple-csv-cloudflare-redirects' ); ?></li>
			<?php endif; ?>
		</ul>
		<?php
	}

	/** Domains in wp-config that could not be loaded. Details for administrators only. */
	private function render_config_errors( array $errors ) {
		if ( ! $errors ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_admin_notice( esc_html__( 'Some websites could not be loaded from Cloudflare. Ask an administrator to check the Redirects configuration.', 'simple-csv-cloudflare-redirects' ), [ 'type' => 'warning' ] );
			return;
		}
		$items = '';
		foreach ( $errors as $e ) {
			$items .= '<li>' . esc_html( $e ) . '</li>';
		}
		wp_admin_notice(
			'<p>' . esc_html__( 'These domains in RFCF_CONFIG (wp-config.php) could not be loaded:', 'simple-csv-cloudflare-redirects' ) . '</p><ul>' . $items . '</ul>'
			. '<p><a href="' . esc_url( $this->cf_url( [ 'refresh' => 1 ] ) ) . '">' . esc_html__( 'Try again', 'simple-csv-cloudflare-redirects' ) . '</a></p>',
			[ 'type' => 'warning', 'paragraph_wrap' => false ]
		);
	}

	private function current_zone() {
		$zone = $this->requested_zone( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, or nonce checked in guard().
		return $zone ? $zone : (string) key( $this->allowed_zones() );
	}

	private function render_log_tab() {
		rfcf_load_list_tables();
		$table = new RFCF_Log_Table( $this );
		$table->prepare_items();
		?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="tab" value="log">
			<?php $table->display(); ?>
		</form>
		<?php
	}

	private function render_rules_tab() {
		$zones = $this->allowed_zones();
		$zone  = $this->current_zone();

		if ( count( $zones ) > 1 ) {
			$links = [];
			foreach ( $zones as $id => $name ) {
				$links[] = sprintf(
					'<li><a href="%s"%s>%s</a>',
					esc_url( $this->cf_url( [ 'zone' => $id ] ) ),
					$id === $zone ? ' class="current" aria-current="page"' : '',
					esc_html( $name )
				);
			}
			echo wp_kses_post( '<ul class="subsubsub">' . implode( ' |</li>', $links ) . '</li></ul><br class="clear">' );
		} else {
			/* translators: %s: domain */
			echo '<p>' . esc_html( sprintf( __( 'Website: %s', 'simple-csv-cloudflare-redirects' ), $zones[ $zone ] ) ) . '</p>';
		}

		$ep = $this->api()->get_entrypoint( $zone );
		if ( is_wp_error( $ep ) ) {
			wp_admin_notice( esc_html( $this->error_message( $ep ) ), [ 'type' => 'error', 'additional_classes' => [ 'inline' ] ] );
			return;
		}
		$rules = $ep ? (array) ( $ep['rules'] ?? [] ) : [];
		$count = count( $rules );
		$plan  = $this->zone_plan( $zone );

		if ( $plan ) {
			/* translators: 1: rules used, 2: rule limit, 3: Cloudflare plan name */
			$usage = sprintf( __( '%1$d of %2$d redirect rules used (%3$s).', 'simple-csv-cloudflare-redirects' ), $count, $plan['limit'], $plan['plan'] );
		} else {
			/* translators: %d: number of rules */
			$usage = sprintf( _n( '%d redirect rule.', '%d redirect rules.', $count, 'simple-csv-cloudflare-redirects' ), $count );
		}
		echo '<p>' . esc_html( $usage ) . ' ' . esc_html__( 'Cloudflare checks rules from top to bottom and applies the first one that matches.', 'simple-csv-cloudflare-redirects' ) . '</p>';

		if ( $plan && $count >= $plan['limit'] ) {
			wp_admin_notice( esc_html__( 'This website has reached its redirect rule limit. Delete or combine rules before adding new ones.', 'simple-csv-cloudflare-redirects' ), [ 'type' => 'warning', 'additional_classes' => [ 'inline' ] ] );
		}

		rfcf_load_list_tables();
		$table = new RFCF_Rules_Table( $this, $zone, $rules );
		$table->prepare_items();
		$table->display();
	}

	private function render_edit_screen( $action ) {
		$zones   = $this->allowed_zones();
		$zone    = $this->current_zone();
		$rule_id = isset( $_GET['rule'] ) ? sanitize_key( wp_unslash( $_GET['rule'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, or nonce checked in guard().
		$form    = $this->blank_form();
		$error   = null;

		if ( 'edit' === $action ) {
			$found = $this->find_rule( $zone, $rule_id );
			if ( is_wp_error( $found ) ) {
				$error = $this->error_message( $found );
			} else {
				$form = $this->rule_to_form( $found['rule'] );
			}
		}

		$state_key = 'rfcf_form_' . get_current_user_id();
		$restored  = get_transient( $state_key );
		if ( is_array( $restored ) ) {
			delete_transient( $state_key );
			$form = array_merge( $form, $restored );
			$zone = isset( $zones[ $restored['zone'] ?? '' ] ) ? $restored['zone'] : $zone;
		}

		$editing = 'edit' === $action;
		$simple  = 'simple' === $form['mode'];
		?>
		<div class="wrap">
			<h1><?php echo $editing ? esc_html__( 'Edit Redirect Rule', 'simple-csv-cloudflare-redirects' ) : esc_html__( 'Add Redirect Rule', 'simple-csv-cloudflare-redirects' ); ?></h1>
			<?php $this->render_notices(); ?>

			<?php if ( $error ) : ?>
				<?php wp_admin_notice( esc_html( $error ), [ 'type' => 'error' ] ); ?>
				<p><a href="<?php echo esc_url( $this->cf_url( [ 'zone' => $zone ] ) ); ?>">&larr; <?php esc_html_e( 'Back to Redirects', 'simple-csv-cloudflare-redirects' ); ?></a></p>
			</div>
				<?php
				return;
			endif;
			?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="rfcf-rule-form">
				<input type="hidden" name="action" value="rfcf_save_rule">
				<input type="hidden" name="rule_id" value="<?php echo esc_attr( $form['id'] ); ?>">
				<?php wp_nonce_field( 'rfcf_save_rule' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rfcf-zone"><?php esc_html_e( 'Website', 'simple-csv-cloudflare-redirects' ); ?></label></th>
						<td>
							<?php if ( $editing ) : ?>
								<input type="hidden" name="zone" value="<?php echo esc_attr( $zone ); ?>">
								<?php echo esc_html( $zones[ $zone ] ); ?>
							<?php else : ?>
								<select id="rfcf-zone" name="zone">
									<?php foreach ( $zones as $id => $zname ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $zone ); ?>><?php echo esc_html( $zname ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rfcf-desc"><?php esc_html_e( 'Description', 'simple-csv-cloudflare-redirects' ); ?></label></th>
						<td><input type="text" id="rfcf-desc" name="description" class="regular-text" value="<?php echo esc_attr( $form['description'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rule type', 'simple-csv-cloudflare-redirects' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><span><?php esc_html_e( 'Rule type', 'simple-csv-cloudflare-redirects' ); ?></span></legend>
								<label><input type="radio" name="mode" value="simple" <?php checked( $simple ); ?>> <?php esc_html_e( 'URL pattern with wildcards', 'simple-csv-cloudflare-redirects' ); ?></label><br>
								<label><input type="radio" name="mode" value="custom" <?php checked( ! $simple ); ?>> <?php esc_html_e( 'Custom expression', 'simple-csv-cloudflare-redirects' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr class="rfcf-simple"<?php echo $simple ? '' : ' hidden'; ?>>
						<th scope="row"><label for="rfcf-src"><?php esc_html_e( 'Request URL', 'simple-csv-cloudflare-redirects' ); ?></label></th>
						<td>
							<input type="text" id="rfcf-src" name="source_url" class="large-text code" value="<?php echo esc_attr( $form['source_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'Full URL including https://, for example https://example.org/old/*. Use * as a wildcard. Matching includes the query string and ignores letter case.', 'simple-csv-cloudflare-redirects' ); ?></p>
						</td>
					</tr>
					<tr class="rfcf-simple"<?php echo $simple ? '' : ' hidden'; ?>>
						<th scope="row"><label for="rfcf-tgt"><?php esc_html_e( 'Target URL', 'simple-csv-cloudflare-redirects' ); ?></label></th>
						<td>
							<input type="text" id="rfcf-tgt" name="target_url" class="large-text code" value="<?php echo esc_attr( $form['target_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'For example https://example.org/new/${1}. Use ${1}, ${2}… to insert what each * matched.', 'simple-csv-cloudflare-redirects' ); ?></p>
						</td>
					</tr>
					<tr class="rfcf-custom"<?php echo $simple ? ' hidden' : ''; ?>>
						<th scope="row"><label for="rfcf-expr"><?php esc_html_e( 'Expression', 'simple-csv-cloudflare-redirects' ); ?></label></th>
						<td>
							<textarea id="rfcf-expr" name="expression" rows="3" class="large-text code"><?php echo esc_textarea( $form['expression'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'A Cloudflare Rules language expression, for example (http.host eq "example.org" and http.request.uri.path eq "/old").', 'simple-csv-cloudflare-redirects' ); ?></p>
						</td>
					</tr>
					<tr class="rfcf-custom"<?php echo $simple ? ' hidden' : ''; ?>>
						<th scope="row"><label for="rfcf-tv"><?php esc_html_e( 'Redirect to', 'simple-csv-cloudflare-redirects' ); ?></label></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><span><?php esc_html_e( 'Target type', 'simple-csv-cloudflare-redirects' ); ?></span></legend>
								<label><input type="radio" name="target_type" value="static" <?php checked( $form['target_type'], 'static' ); ?>> <?php esc_html_e( 'Static URL', 'simple-csv-cloudflare-redirects' ); ?></label><br>
								<label><input type="radio" name="target_type" value="dynamic" <?php checked( $form['target_type'], 'dynamic' ); ?>> <?php esc_html_e( 'Dynamic expression', 'simple-csv-cloudflare-redirects' ); ?></label>
							</fieldset>
							<p><input type="text" id="rfcf-tv" name="target_value" class="large-text code" value="<?php echo esc_attr( $form['target_value'] ); ?>"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rfcf-status"><?php esc_html_e( 'Status code', 'simple-csv-cloudflare-redirects' ); ?></label></th>
						<td>
							<select id="rfcf-status" name="status_code">
								<?php foreach ( self::STATUSES as $code ) : ?>
									<option value="<?php echo (int) $code; ?>" <?php selected( (int) $form['status_code'], $code ); ?>><?php echo (int) $code; ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( '301 permanent, 302 temporary. 307 and 308 keep the request method.', 'simple-csv-cloudflare-redirects' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'simple-csv-cloudflare-redirects' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><span><?php esc_html_e( 'Options', 'simple-csv-cloudflare-redirects' ); ?></span></legend>
								<label><input type="checkbox" name="preserve_query_string" value="1" <?php checked( $form['preserve_query_string'] ); ?>> <?php esc_html_e( 'Preserve query string', 'simple-csv-cloudflare-redirects' ); ?></label><br>
								<label><input type="checkbox" name="enabled" value="1" <?php checked( $form['enabled'] ); ?>> <?php esc_html_e( 'Enabled', 'simple-csv-cloudflare-redirects' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<?php if ( ! $editing ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Position', 'simple-csv-cloudflare-redirects' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><span><?php esc_html_e( 'Position', 'simple-csv-cloudflare-redirects' ); ?></span></legend>
									<label><input type="radio" name="position" value="bottom" <?php checked( $form['position'], 'bottom' ); ?>> <?php esc_html_e( 'Last (checked after existing rules)', 'simple-csv-cloudflare-redirects' ); ?></label><br>
									<label><input type="radio" name="position" value="top" <?php checked( $form['position'], 'top' ); ?>> <?php esc_html_e( 'First (checked before existing rules)', 'simple-csv-cloudflare-redirects' ); ?></label>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Put specific rules above broad wildcard rules. You can move rules later from the list.', 'simple-csv-cloudflare-redirects' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
				</table>

				<p class="submit">
					<?php submit_button( $editing ? __( 'Update Rule', 'simple-csv-cloudflare-redirects' ) : __( 'Add Rule', 'simple-csv-cloudflare-redirects' ), 'primary', 'submit', false ); ?>
					<a href="<?php echo esc_url( $this->cf_url( [ 'zone' => $zone ] ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'simple-csv-cloudflare-redirects' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Rule <-> form conversion
	 * ------------------------------------------------------------------ */

	private function blank_form() {
		return [
			'id'                    => '',
			'description'           => '',
			'mode'                  => 'simple',
			'source_url'            => '',
			'target_url'            => '',
			'expression'            => '',
			'target_type'           => 'static',
			'target_value'          => '',
			'status_code'           => 301,
			'preserve_query_string' => false,
			'enabled'               => true,
			'position'              => 'bottom',
		];
	}

	/** Cloudflare raw string literal. */
	private function raw( $s ) {
		return false === strpos( $s, '"' ) ? 'r"' . $s . '"' : 'r#"' . $s . '"#';
	}

	private function parse_wildcard_expression( $expr ) {
		if ( preg_match( '/^\(?\s*http\.request\.full_uri\s+wildcard\s+r(#?)"(.*?)"\1\s*\)?$/s', trim( $expr ), $m ) ) {
			return $m[2];
		}
		return null;
	}

	private function parse_wildcard_replace( $expr, $source ) {
		if ( preg_match( '/^wildcard_replace\(\s*http\.request\.full_uri\s*,\s*r(#?)"(.*?)"\1\s*,\s*r(#?)"(.*?)"\3\s*\)$/s', trim( $expr ), $m ) && $m[2] === $source ) {
			return $m[4];
		}
		return null;
	}

	public function rule_to_form( array $rule ) {
		$fv = $rule['action_parameters']['from_value'] ?? [];
		$tu = $fv['target_url'] ?? [];
		$f  = array_merge(
			$this->blank_form(),
			[
				'id'                    => $rule['id'] ?? '',
				'description'           => $rule['description'] ?? '',
				'mode'                  => 'custom',
				'expression'            => $rule['expression'] ?? '',
				'target_type'           => isset( $tu['expression'] ) ? 'dynamic' : 'static',
				'target_value'          => $tu['expression'] ?? ( $tu['value'] ?? '' ),
				'status_code'           => (int) ( $fv['status_code'] ?? 301 ),
				'preserve_query_string' => ! empty( $fv['preserve_query_string'] ),
				'enabled'               => ! empty( $rule['enabled'] ),
			]
		);

		$src = $this->parse_wildcard_expression( $f['expression'] );
		if ( null !== $src ) {
			$tgt = 'static' === $f['target_type'] ? $f['target_value'] : $this->parse_wildcard_replace( $f['target_value'], $src );
			if ( null !== $tgt ) {
				$f['mode']       = 'simple';
				$f['source_url'] = $src;
				$f['target_url'] = $tgt;
			}
		}
		return $f;
	}

	private function posted_form() {
		$status = absint( $this->post_text( 'status_code' ) );
		return [
			'id'                    => sanitize_key( $this->post_text( 'rule_id' ) ),
			'description'           => $this->post_text( 'description' ),
			'mode'                  => 'custom' === $this->post_text( 'mode' ) ? 'custom' : 'simple',
			'source_url'            => $this->post_line( 'source_url' ),
			'target_url'            => $this->post_line( 'target_url' ),
			'expression'            => $this->post_line( 'expression' ),
			'target_type'           => 'dynamic' === $this->post_text( 'target_type' ) ? 'dynamic' : 'static',
			'target_value'          => $this->post_line( 'target_value' ),
			'status_code'           => $status ? $status : 301,
			'preserve_query_string' => $this->post_flag( 'preserve_query_string' ),
			'enabled'               => $this->post_flag( 'enabled' ),
			'position'              => 'top' === $this->post_text( 'position' ) ? 'top' : 'bottom',
		];
	}

	/** @return array|WP_Error Cloudflare rule body. */
	private function form_to_rule( array $f, $zone_name ) {
		if ( ! in_array( $f['status_code'], self::STATUSES, true ) ) {
			return new WP_Error( 'rfcf', __( 'Choose a valid status code.', 'simple-csv-cloudflare-redirects' ) );
		}

		if ( 'simple' === $f['mode'] ) {
			if ( ! preg_match( '#^(?:https?|\*)://([^/?\#]+)#i', $f['source_url'], $m ) ) {
				return new WP_Error( 'rfcf', __( 'Request URL must start with https:// (or http://).', 'simple-csv-cloudflare-redirects' ) );
			}
			$host = strtolower( preg_replace( '/:\d+$/', '', $m[1] ) );
			$base = ltrim( preg_replace( '/^\*/', '', $host ), '.' );
			if ( $base !== $zone_name && substr( $base, -strlen( '.' . $zone_name ) ) !== '.' . $zone_name ) {
				/* translators: %s: domain */
				return new WP_Error( 'rfcf', sprintf( __( 'Request URL must be on %s or one of its subdomains.', 'simple-csv-cloudflare-redirects' ), $zone_name ) );
			}
			if ( ! preg_match( '#^https?://\S+$#i', $f['target_url'] ) ) {
				return new WP_Error( 'rfcf', __( 'Target URL must be a full URL starting with https:// (no spaces).', 'simple-csv-cloudflare-redirects' ) );
			}
			if ( false !== strpos( $f['source_url'] . $f['target_url'], '"#' ) ) {
				return new WP_Error( 'rfcf', __( 'URLs cannot contain the sequence "#.', 'simple-csv-cloudflare-redirects' ) );
			}
			$expression = 'http.request.full_uri wildcard ' . $this->raw( $f['source_url'] );
			$target     = false !== strpos( $f['target_url'], '${' )
				? [ 'expression' => 'wildcard_replace(http.request.full_uri, ' . $this->raw( $f['source_url'] ) . ', ' . $this->raw( $f['target_url'] ) . ')' ]
				: [ 'value' => $f['target_url'] ];
		} else {
			if ( '' === $f['expression'] || '' === $f['target_value'] ) {
				return new WP_Error( 'rfcf', __( 'Both the expression and the redirect target are required.', 'simple-csv-cloudflare-redirects' ) );
			}
			$expression = $f['expression'];
			$target     = 'dynamic' === $f['target_type'] ? [ 'expression' => $f['target_value'] ] : [ 'value' => $f['target_value'] ];
		}

		return [
			'action'            => 'redirect',
			'description'       => $f['description'],
			'expression'        => $expression,
			'enabled'           => (bool) $f['enabled'],
			'action_parameters' => [
				'from_value' => [
					'target_url'            => $target,
					'status_code'           => (int) $f['status_code'],
					'preserve_query_string' => (bool) $f['preserve_query_string'],
				],
			],
		];
	}

	/** The fields Cloudflare needs when PATCHing an existing rule. */
	private function rule_body( array $r ) {
		return [
			'action'            => $r['action'],
			'expression'        => $r['expression'],
			'description'       => $r['description'] ?? '',
			'action_parameters' => $r['action_parameters'] ?? [],
			'enabled'           => ! empty( $r['enabled'] ),
		];
	}

	/** @return array{ruleset:array,rule:array,index:int}|WP_Error */
	private function find_rule( $zone, $rule_id ) {
		$ep = $this->api()->get_entrypoint( $zone );
		if ( is_wp_error( $ep ) ) {
			return $ep;
		}
		foreach ( array_values( $ep ? (array) $ep['rules'] : [] ) as $i => $r ) {
			if ( $r['id'] === $rule_id ) {
				return [ 'ruleset' => $ep, 'rule' => $r, 'index' => $i ];
			}
		}
		return new WP_Error( 'rfcf_gone', __( 'That rule no longer exists.', 'simple-csv-cloudflare-redirects' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Handlers (admin-post.php)
	 * ------------------------------------------------------------------ */

	public function handle_save_rule() {
		$this->guard( 'rfcf_save_rule' );
		$zone = $this->requested_zone( $_POST );
		if ( ! $zone ) {
			$this->deny_zone();
		}
		$zones = $this->allowed_zones();
		$form  = $this->posted_form();
		$back  = $this->page_url( array_filter( [ 'action' => $form['id'] ? 'edit' : 'add', 'zone' => $zone, 'rule' => $form['id'] ] ) );

		$fail = function ( $message ) use ( $form, $zone, $back ) {
			set_transient( 'rfcf_form_' . get_current_user_id(), array_merge( $form, [ 'zone' => $zone ] ), 5 * MINUTE_IN_SECONDS );
			$this->notice( 'error', $message );
			wp_safe_redirect( $back );
			exit;
		};

		$rule = $this->form_to_rule( $form, $zones[ $zone ] );
		if ( is_wp_error( $rule ) ) {
			$fail( $rule->get_error_message() );
		}

		$api = $this->api();

		if ( $form['id'] ) {
			$found = $this->find_rule( $zone, $form['id'] );
			if ( is_wp_error( $found ) ) {
				$fail( $this->error_message( $found ) );
			}
			$res = $api->update_rule( $zone, $found['ruleset']['id'], $form['id'], $rule );
			if ( is_wp_error( $res ) ) {
				$fail( $this->error_message( $res ) );
			}
			$this->log( 'updated', $zones[ $zone ], $this->rule_label( $rule ), $this->summarize( $found['rule'] ), $this->summarize( $rule ) );
			$this->notice( 'success', __( 'Redirect rule updated.', 'simple-csv-cloudflare-redirects' ) );
			$this->back_to_list( $zone );
		}

		$ep = $api->get_entrypoint( $zone );
		if ( is_wp_error( $ep ) ) {
			$fail( $this->error_message( $ep ) );
		}
		if ( ! $ep ) {
			$res = $api->create_entrypoint( $zone, $rule );
		} else {
			if ( 'top' === $form['position'] && ! empty( $ep['rules'] ) ) {
				$rule['position'] = [ 'index' => 1 ];
			}
			$res = $api->add_rule( $zone, $ep['id'], $rule );
		}
		if ( is_wp_error( $res ) ) {
			$fail( $this->error_message( $res ) );
		}

		$this->log( 'added', $zones[ $zone ], $this->rule_label( $rule ), '', $this->summarize( $rule ) );
		$this->notice( 'success', __( 'Redirect rule added.', 'simple-csv-cloudflare-redirects' ) );
		$this->back_to_list( $zone );
	}

	/** Shared start for the row actions: checks, then finds the rule. */
	private function row_action_start( $action ) {
		$rule_id = isset( $_GET['rule'] ) ? sanitize_key( wp_unslash( $_GET['rule'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, or nonce checked in guard().
		$this->guard( $action . '_' . $rule_id );
		$zone = $this->requested_zone( $_GET );
		if ( ! $zone ) {
			$this->deny_zone();
		}
		$found = $this->find_rule( $zone, $rule_id );
		if ( is_wp_error( $found ) ) {
			$this->notice( 'error', $this->error_message( $found ) );
			$this->back_to_list( $zone );
		}
		return [ $zone, $found ];
	}

	public function handle_toggle_rule() {
		list( $zone, $found ) = $this->row_action_start( 'rfcf_toggle_rule' );
		$r               = $found['rule'];
		$body            = $this->rule_body( $r );
		$body['enabled'] = ! $body['enabled'];

		$res = $this->api()->update_rule( $zone, $found['ruleset']['id'], $r['id'], $body );
		if ( is_wp_error( $res ) ) {
			$this->notice( 'error', $this->error_message( $res ) );
		} else {
			$this->log( $body['enabled'] ? 'enabled' : 'disabled', $this->allowed_zones()[ $zone ], $this->rule_label( $r ), '', $this->summarize( $body ) );
			$this->notice( 'success', $body['enabled'] ? __( 'Redirect rule enabled.', 'simple-csv-cloudflare-redirects' ) : __( 'Redirect rule disabled.', 'simple-csv-cloudflare-redirects' ) );
		}
		$this->back_to_list( $zone );
	}

	public function handle_delete_rule() {
		list( $zone, $found ) = $this->row_action_start( 'rfcf_delete_rule' );
		$r                    = $found['rule'];

		$res = $this->api()->delete_rule( $zone, $found['ruleset']['id'], $r['id'] );
		if ( is_wp_error( $res ) ) {
			$this->notice( 'error', $this->error_message( $res ) );
		} else {
			$this->log( 'deleted', $this->allowed_zones()[ $zone ], $this->rule_label( $r ), $this->summarize( $r ) );
			$this->notice( 'success', __( 'Redirect rule deleted.', 'simple-csv-cloudflare-redirects' ) );
		}
		$this->back_to_list( $zone );
	}

	public function handle_move_rule() {
		list( $zone, $found ) = $this->row_action_start( 'rfcf_move_rule' );
		$dir                  = ( isset( $_GET['dir'] ) && 'down' === $_GET['dir'] ) ? 'down' : 'up'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, or nonce checked in guard().
		$rules                = array_values( (array) $found['ruleset']['rules'] );
		$i                    = $found['index'];
		$j                    = 'up' === $dir ? $i - 1 : $i + 1;

		if ( ! isset( $rules[ $j ] ) ) {
			$this->back_to_list( $zone );
		}

		$body             = $this->rule_body( $found['rule'] );
		$body['position'] = 'up' === $dir ? [ 'before' => $rules[ $j ]['id'] ] : [ 'after' => $rules[ $j ]['id'] ];

		$res = $this->api()->update_rule( $zone, $found['ruleset']['id'], $found['rule']['id'], $body );
		if ( is_wp_error( $res ) ) {
			$this->notice( 'error', $this->error_message( $res ) );
		} else {
			/* translators: %d: rule position */
			$pos = __( 'Position %d', 'simple-csv-cloudflare-redirects' );
			$this->log( 'moved', $this->allowed_zones()[ $zone ], $this->rule_label( $found['rule'] ), sprintf( $pos, $i + 1 ), sprintf( $pos, $j + 1 ) );
			$this->notice( 'success', __( 'Redirect rule moved.', 'simple-csv-cloudflare-redirects' ) );
		}
		$this->back_to_list( $zone );
	}
}
