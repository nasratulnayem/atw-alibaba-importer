<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AWI_Url_Import {
	private const AJAX_NONCE = 'awi_url_import_nonce';
	private const RUN_PREFIX = 'awi_url_import_';

	public static function init(): void {
		add_action( 'wp_ajax_awi_url_import_create_run', array( __CLASS__, 'ajax_create_run' ) );
		add_action( 'wp_ajax_awi_url_import_update_run', array( __CLASS__, 'ajax_update_run' ) );
		add_action( 'wp_ajax_awi_url_import_get_run', array( __CLASS__, 'ajax_get_run' ) );
		add_action( 'wp_ajax_awi_url_import_recent_runs', array( __CLASS__, 'ajax_recent_runs' ) );
		add_action( 'wp_ajax_awi_url_import_clear_runs', array( __CLASS__, 'ajax_clear_runs' ) );
		add_action( 'wp_ajax_awi_get_quota', array( __CLASS__, 'ajax_get_quota' ) );
		add_action( 'admin_post_awi_url_import_failed_log', array( __CLASS__, 'handle_failed_log' ) );
	}

	public static function ajax_get_quota(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! self::can_manage() ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		$status = AWI_Rate_Limiter::get_status( (int) get_current_user_id() );
		wp_send_json_success( array( 'quota' => $status ) );
	}

	public static function can_manage(): bool {
		$cap = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
		return current_user_can( $cap );
	}

	public static function get_ajax_nonce(): string {
		return wp_create_nonce( self::AJAX_NONCE );
	}

	public static function get_categories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$out[] = array(
				'id'     => (int) $term->term_id,
				'name'   => (string) $term->name,
				'slug'   => (string) $term->slug,
				'parent' => (int) $term->parent,
				'path'   => self::get_category_path( (int) $term->term_id ),
			);
		}

		return $out;
	}

	public static function get_latest_run(): array {
		$runs = self::get_recent_runs( 1, true );
		return ! empty( $runs[0] ) && is_array( $runs[0] ) ? $runs[0] : array();
	}

	public static function get_recent_runs( int $limit = 8, bool $include_failed_items = false ): array {
		$paths = self::get_run_file_paths();
		if ( ! $paths ) {
			return array();
		}

		usort(
			$paths,
			static function ( string $a, string $b ): int {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);

		$out = array();
		foreach ( array_slice( $paths, 0, max( 1, $limit ) ) as $path ) {
			$run = self::load_run_from_path( $path );
			if ( ! $run ) {
				continue;
			}
			$out[] = self::prepare_run_for_response( $run, $include_failed_items );
		}

		return $out;
	}

	public static function get_run( string $run_id, bool $include_failed_items = true ): array {
		$run = self::load_run( $run_id );
		if ( ! $run ) {
			return array();
		}
		return self::prepare_run_for_response( $run, $include_failed_items );
	}

	public static function create_run( array $urls, int $category_id, string $source_run_id = '' ) {
		$paths = self::ensure_storage_paths();
		if ( is_wp_error( $paths ) ) {
			return $paths;
		}

		$urls = self::normalize_urls( $urls );
		if ( ! $urls ) {
			return new WP_Error( 'awi_url_import_empty', 'Paste at least one valid Alibaba product URL.' );
		}

		$term = get_term( $category_id, 'product_cat' );
		if ( ! $term instanceof WP_Term ) {
			return new WP_Error( 'awi_url_import_bad_category', 'Select a valid WooCommerce category.' );
		}

		$run_id = self::generate_run_id();
		$now    = gmdate( 'c' );

		$run = array(
			'id'            => $run_id,
			'status'        => 'pending',
			'created_at'    => $now,
			'updated_at'    => $now,
			'started_at'    => '',
			'finished_at'   => '',
			'category_id'   => (int) $term->term_id,
			'category_name' => (string) $term->name,
			'category_path' => self::get_category_path( (int) $term->term_id ),
			'total'         => count( $urls ),
			'processed'     => 0,
			'success'       => 0,
			'failed'        => 0,
			'latest_message'=> 'Run created.',
			'source_run_id' => self::sanitize_run_id( $source_run_id ),
			'urls'          => array_values( $urls ),
			'results'       => array(),
			'failed_items'  => array(),
		);

		self::write_run( $run );
		self::write_failed_log( $run );

		return self::prepare_run_for_response( $run, true );
	}

	public static function apply_event( string $run_id, array $event ) {
		$run = self::load_run( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'awi_url_import_missing_run', 'Import run not found.' );
		}

		$now   = gmdate( 'c' );
		$type  = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';
		$state = isset( $event['state'] ) ? sanitize_key( (string) $event['state'] ) : '';

		if ( $type === 'state' ) {
			if ( $state === 'start' ) {
				$run['status']        = 'running';
				$run['started_at']    = $run['started_at'] ? (string) $run['started_at'] : $now;
				$run['latest_message']= isset( $event['message'] ) ? sanitize_text_field( (string) $event['message'] ) : 'Import started.';
			} elseif ( $state === 'done' ) {
				$run['status']        = ! empty( $run['failed'] ) ? 'completed_with_errors' : 'completed';
				$run['finished_at']   = $now;
				$run['latest_message']= isset( $event['message'] ) ? sanitize_text_field( (string) $event['message'] ) : 'Import finished.';
			} elseif ( $state === 'stopped' ) {
				$run['status']        = 'stopped';
				$run['finished_at']   = $now;
				$run['latest_message']= isset( $event['message'] ) ? sanitize_text_field( (string) $event['message'] ) : 'Import stopped.';
			} elseif ( $state === 'error' ) {
				$run['status']        = 'failed';
				$run['finished_at']   = $now;
				$run['latest_message']= isset( $event['message'] ) ? sanitize_text_field( (string) $event['message'] ) : 'Import failed before completion.';
			}
		} elseif ( $type === 'item' ) {
			$url = isset( $event['url'] ) ? trim( (string) $event['url'] ) : '';
			if ( $url === '' ) {
				return new WP_Error( 'awi_url_import_missing_url', 'Missing item URL.' );
			}

			$status = ! empty( $event['ok'] ) ? 'success' : 'failed';
			$item   = array(
				'url'        => esc_url_raw( $url ),
				'status'     => $status,
				'message'    => isset( $event['message'] ) ? sanitize_text_field( (string) $event['message'] ) : '',
				'error'      => isset( $event['error'] ) ? sanitize_textarea_field( (string) $event['error'] ) : '',
				'updated_at' => $now,
			);

			if ( ! isset( $run['results'] ) || ! is_array( $run['results'] ) ) {
				$run['results'] = array();
			}
			$run['results'][ $item['url'] ] = $item;
			$run['status']                  = 'running';
			$run['started_at']              = $run['started_at'] ? (string) $run['started_at'] : $now;
			$run['latest_message']          = $status === 'success'
				? ( $item['message'] !== '' ? $item['message'] : 'Imported successfully.' )
				: ( $item['error'] !== '' ? $item['error'] : 'Import failed.' );
		}

		$run['updated_at'] = $now;
		$run               = self::recalculate_run( $run );

		if ( $run['processed'] >= $run['total'] && $run['finished_at'] === '' && $run['status'] === 'running' ) {
			$run['status']      = ! empty( $run['failed'] ) ? 'completed_with_errors' : 'completed';
			$run['finished_at'] = $now;
		}

		self::write_run( $run );
		self::write_failed_log( $run );

		return self::prepare_run_for_response( $run, true );
	}

	public static function ajax_create_run(): void {
		self::assert_ajax_permission();

		$urls_input     = isset( $_POST['urls'] ) ? wp_unslash( $_POST['urls'] ) : array();
		$category_id    = isset( $_POST['category_id'] ) ? (int) $_POST['category_id'] : 0;
		$source_run_id  = isset( $_POST['source_run_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['source_run_id'] ) ) : '';
		$raw_urls       = is_array( $urls_input ) ? $urls_input : preg_split( '/[\r\n,]+/', (string) $urls_input );
		$run            = self::create_run( is_array( $raw_urls ) ? $raw_urls : array(), $category_id, $source_run_id );

		if ( is_wp_error( $run ) ) {
			wp_send_json_error( array( 'message' => $run->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'run' => $run ) );
	}

	public static function ajax_update_run(): void {
		self::assert_ajax_permission();

		$run_id    = isset( $_POST['run_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['run_id'] ) ) : '';
		$event_raw = isset( $_POST['event'] ) ? (string) wp_unslash( $_POST['event'] ) : '';
		$event     = json_decode( $event_raw, true );
		if ( ! is_array( $event ) ) {
			wp_send_json_error( array( 'message' => 'Invalid event payload.' ), 400 );
		}

		$run = self::apply_event( $run_id, $event );
		if ( is_wp_error( $run ) ) {
			wp_send_json_error( array( 'message' => $run->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'run' => $run ) );
	}

	public static function ajax_get_run(): void {
		self::assert_ajax_permission();

		$run_id = isset( $_GET['run_id'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['run_id'] ) ) : '';
		$run    = self::get_run( $run_id, true );
		if ( ! $run ) {
			wp_send_json_error( array( 'message' => 'Import run not found.' ), 404 );
		}

		wp_send_json_success( array( 'run' => $run ) );
	}

	public static function ajax_recent_runs(): void {
		self::assert_ajax_permission();
		wp_send_json_success(
			array(
				'runs' => self::get_recent_runs( 8, false ),
			)
		);
	}

	public static function ajax_clear_runs(): void {
		self::assert_ajax_permission();

		$cleared = self::clear_all_runs();
		if ( is_wp_error( $cleared ) ) {
			wp_send_json_error( array( 'message' => $cleared->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'cleared' => (int) $cleared,
				'runs'    => array(),
			)
		);
	}

	public static function handle_failed_log(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this log.', 'awi' ) );
		}

		$run_id = isset( $_GET['run_id'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['run_id'] ) ) : '';
		if ( ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? (string) wp_unslash( $_GET['_wpnonce'] ) : '', 'awi_url_import_failed_log_' . $run_id ) ) {
			wp_die( esc_html__( 'Invalid log link.', 'awi' ) );
		}

		$run = self::load_run( $run_id );
		if ( ! $run ) {
			wp_die( esc_html__( 'Import run not found.', 'awi' ) );
		}

		$paths = self::ensure_storage_paths();
		if ( is_wp_error( $paths ) ) {
			wp_die( esc_html( $paths->get_error_message() ) );
		}

		$log_path = self::get_failed_log_path( $run_id );
		if ( ! file_exists( $log_path ) ) {
			self::write_failed_log( $run );
		}

		if ( ! file_exists( $log_path ) ) {
			wp_die( esc_html__( 'Failed log is not available.', 'awi' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		header( 'Content-Disposition: inline; filename="failed-log.txt"' );
		readfile( $log_path );
		exit;
	}

	private static function assert_ajax_permission(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! self::can_manage() ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
	}

	private static function normalize_urls( array $urls ): array {
		$out = array();
		foreach ( $urls as $url ) {
			$clean = self::normalize_single_url( (string) $url );
			if ( $clean === '' ) {
				continue;
			}
			$out[] = $clean;
		}

		return array_values( array_unique( $out ) );
	}

	private static function normalize_single_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( trim( (string) $parts['host'] ) );
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( ! preg_match( '/(^|\\.)alibaba\\.com$/', $host ) ) {
			return '';
		}
		if ( strpos( $path, '/product-detail/' ) === false ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	private static function generate_run_id(): string {
		return self::RUN_PREFIX . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false, false );
	}

	private static function sanitize_run_id( string $run_id ): string {
		$run_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $run_id );
		return is_string( $run_id ) ? $run_id : '';
	}

	private static function recalculate_run( array $run ): array {
		$results      = isset( $run['results'] ) && is_array( $run['results'] ) ? $run['results'] : array();
		$success      = 0;
		$failed       = 0;
		$failed_items = array();

		foreach ( $results as $url => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$status = isset( $item['status'] ) ? sanitize_key( (string) $item['status'] ) : '';
			if ( $status === 'success' ) {
				$success++;
			} elseif ( $status === 'failed' ) {
				$failed++;
				$failed_items[] = array(
					'url'        => esc_url_raw( is_string( $url ) ? $url : (string) ( $item['url'] ?? '' ) ),
					'error'      => isset( $item['error'] ) ? sanitize_textarea_field( (string) $item['error'] ) : '',
					'updated_at' => isset( $item['updated_at'] ) ? sanitize_text_field( (string) $item['updated_at'] ) : '',
				);
			}
		}

		$run['success']      = $success;
		$run['failed']       = $failed;
		$run['processed']    = $success + $failed;
		$run['failed_items'] = $failed_items;

		return $run;
	}

	private static function prepare_run_for_response( array $run, bool $include_failed_items ): array {
		$run = self::recalculate_run( $run );
		$id  = isset( $run['id'] ) ? self::sanitize_run_id( (string) $run['id'] ) : '';

		$out = array(
			'id'             => $id,
			'status'         => isset( $run['status'] ) ? sanitize_key( (string) $run['status'] ) : 'pending',
			'created_at'     => isset( $run['created_at'] ) ? sanitize_text_field( (string) $run['created_at'] ) : '',
			'updated_at'     => isset( $run['updated_at'] ) ? sanitize_text_field( (string) $run['updated_at'] ) : '',
			'started_at'     => isset( $run['started_at'] ) ? sanitize_text_field( (string) $run['started_at'] ) : '',
			'finished_at'    => isset( $run['finished_at'] ) ? sanitize_text_field( (string) $run['finished_at'] ) : '',
			'category_id'    => isset( $run['category_id'] ) ? (int) $run['category_id'] : 0,
			'category_name'  => isset( $run['category_name'] ) ? sanitize_text_field( (string) $run['category_name'] ) : '',
			'category_path'  => isset( $run['category_path'] ) ? sanitize_text_field( (string) $run['category_path'] ) : '',
			'total'          => isset( $run['total'] ) ? (int) $run['total'] : 0,
			'processed'      => isset( $run['processed'] ) ? (int) $run['processed'] : 0,
			'success'        => isset( $run['success'] ) ? (int) $run['success'] : 0,
			'failed'         => isset( $run['failed'] ) ? (int) $run['failed'] : 0,
			'pending'        => max( 0, (int) ( $run['total'] ?? 0 ) - (int) ( $run['processed'] ?? 0 ) ),
			'latest_message' => isset( $run['latest_message'] ) ? sanitize_text_field( (string) $run['latest_message'] ) : '',
			'source_run_id'  => isset( $run['source_run_id'] ) ? self::sanitize_run_id( (string) $run['source_run_id'] ) : '',
			'log_url'        => self::get_failed_log_url( $id ),
		);

		if ( $include_failed_items ) {
			$failed_items = isset( $run['failed_items'] ) && is_array( $run['failed_items'] ) ? $run['failed_items'] : array();
			$out['failed_items'] = array_values(
				array_map(
					static function ( array $item ): array {
						return array(
							'url'        => isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '',
							'error'      => isset( $item['error'] ) ? sanitize_textarea_field( (string) $item['error'] ) : '',
							'updated_at' => isset( $item['updated_at'] ) ? sanitize_text_field( (string) $item['updated_at'] ) : '',
						);
					},
					$failed_items
				)
			);
		}

		return $out;
	}

	private static function get_category_path( int $term_id ): string {
		if ( $term_id <= 0 ) {
			return '';
		}

		$crumbs = array();
		$seen   = array();
		$curr   = $term_id;

		while ( $curr > 0 && ! isset( $seen[ $curr ] ) ) {
			$seen[ $curr ] = true;
			$term          = get_term( $curr, 'product_cat' );
			if ( ! $term instanceof WP_Term ) {
				break;
			}
			array_unshift( $crumbs, (string) $term->name );
			$curr = (int) $term->parent;
		}

		return implode( ' > ', $crumbs );
	}

	private static function write_run( array $run ): void {
		$paths = self::ensure_storage_paths();
		if ( is_wp_error( $paths ) ) {
			return;
		}

		$path = self::get_run_path( isset( $run['id'] ) ? (string) $run['id'] : '' );
		if ( $path === '' ) {
			return;
		}

		file_put_contents( $path, wp_json_encode( $run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
	}

	private static function write_failed_log( array $run ): void {
		$paths = self::ensure_storage_paths();
		if ( is_wp_error( $paths ) ) {
			return;
		}

		$run_id   = isset( $run['id'] ) ? (string) $run['id'] : '';
		$log_path = self::get_failed_log_path( $run_id );
		if ( $log_path === '' ) {
			return;
		}

		$lines   = array();
		$lines[] = 'ATW URL Import Failed Log';
		$lines[] = 'Run ID: ' . $run_id;
		$lines[] = 'Category: ' . (string) ( $run['category_path'] ?? '' );
		$lines[] = 'Status: ' . (string) ( $run['status'] ?? '' );
		$lines[] = 'Created At: ' . (string) ( $run['created_at'] ?? '' );
		$lines[] = 'Updated At: ' . (string) ( $run['updated_at'] ?? '' );
		$lines[] = 'Totals: total=' . (int) ( $run['total'] ?? 0 ) . ' success=' . (int) ( $run['success'] ?? 0 ) . ' failed=' . (int) ( $run['failed'] ?? 0 );
		$lines[] = str_repeat( '=', 72 );

		$failed_items = isset( $run['failed_items'] ) && is_array( $run['failed_items'] ) ? $run['failed_items'] : array();
		if ( ! $failed_items ) {
			$lines[] = 'No failed URLs for this run.';
		} else {
			foreach ( $failed_items as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$lines[] = sprintf( '%d. %s', $index + 1, (string) ( $item['url'] ?? '' ) );
				$lines[] = 'Reason: ' . (string) ( $item['error'] ?? 'Unknown error.' );
				$lines[] = 'Last Updated: ' . (string) ( $item['updated_at'] ?? '' );
				$lines[] = str_repeat( '-', 72 );
			}
		}

		$content = implode( "\n", $lines ) . "\n";
		file_put_contents( $log_path, $content, LOCK_EX );
		file_put_contents( self::get_latest_failed_log_path(), $content, LOCK_EX );
	}

	private static function load_run( string $run_id ): array {
		$path = self::get_run_path( $run_id );
		if ( $path === '' || ! file_exists( $path ) ) {
			return array();
		}
		return self::load_run_from_path( $path );
	}

	private static function load_run_from_path( string $path ): array {
		$json = file_get_contents( $path );
		if ( ! is_string( $json ) || $json === '' ) {
			return array();
		}

		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	private static function get_run_file_paths(): array {
		$paths = self::ensure_storage_paths();
		if ( is_wp_error( $paths ) ) {
			return array();
		}

		$files = glob( trailingslashit( $paths['runs_dir'] ) . 'run-*.json' );
		return is_array( $files ) ? $files : array();
	}

	private static function clear_all_runs() {
		$paths = self::ensure_storage_paths();
		if ( is_wp_error( $paths ) ) {
			return $paths;
		}

		$run_files = glob( trailingslashit( $paths['runs_dir'] ) . 'run-*.json' );
		$log_files = glob( trailingslashit( $paths['logs_dir'] ) . 'failed-log-*.txt' );
		$files     = array_merge(
			is_array( $run_files ) ? $run_files : array(),
			is_array( $log_files ) ? $log_files : array()
		);

		$cleared = 0;
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || $file === '' || ! file_exists( $file ) ) {
				continue;
			}
			if ( ! @unlink( $file ) ) {
				return new WP_Error( 'awi_url_import_clear_failed', 'Could not clear one or more import history files.' );
			}
			$cleared++;
		}

		$latest_log = self::get_latest_failed_log_path();
		if ( $latest_log !== '' && file_exists( $latest_log ) && ! @unlink( $latest_log ) ) {
			return new WP_Error( 'awi_url_import_clear_failed', 'Could not clear the latest failed log.' );
		}

		return $cleared;
	}

	private static function get_failed_log_url( string $run_id ): string {
		$run_id = self::sanitize_run_id( $run_id );
		if ( $run_id === '' ) {
			return '';
		}

		return add_query_arg(
			array(
				'action'   => 'awi_url_import_failed_log',
				'run_id'   => $run_id,
				'_wpnonce' => wp_create_nonce( 'awi_url_import_failed_log_' . $run_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	private static function get_run_path( string $run_id ): string {
		$run_id = self::sanitize_run_id( $run_id );
		if ( $run_id === '' ) {
			return '';
		}

		$paths = self::ensure_storage_paths();
		if ( is_wp_error( $paths ) ) {
			return '';
		}

		return trailingslashit( $paths['runs_dir'] ) . 'run-' . $run_id . '.json';
	}

	private static function get_failed_log_path( string $run_id ): string {
		$run_id = self::sanitize_run_id( $run_id );
		if ( $run_id === '' ) {
			return '';
		}

		$paths = self::ensure_storage_paths();
		if ( is_wp_error( $paths ) ) {
			return '';
		}

		return trailingslashit( $paths['logs_dir'] ) . 'failed-log-' . $run_id . '.txt';
	}

	private static function get_latest_failed_log_path(): string {
		$paths = self::ensure_storage_paths();
		if ( is_wp_error( $paths ) ) {
			return '';
		}

		return trailingslashit( $paths['logs_dir'] ) . 'failed-log.txt';
	}

	private static function ensure_storage_paths() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'awi_url_import_uploads', (string) $uploads['error'] );
		}

		$base_dir = trailingslashit( (string) $uploads['basedir'] ) . 'atw-url-import';
		$runs_dir = trailingslashit( $base_dir ) . 'runs';
		$logs_dir = trailingslashit( $base_dir ) . 'logs';

		foreach ( array( $base_dir, $runs_dir, $logs_dir ) as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'awi_url_import_storage', 'Failed to create import storage directory.' );
			}
		}

		return array(
			'base_dir' => $base_dir,
			'runs_dir' => $runs_dir,
			'logs_dir' => $logs_dir,
		);
	}
}
