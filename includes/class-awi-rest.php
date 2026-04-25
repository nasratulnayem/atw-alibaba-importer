<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AWI_Rest {
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'setup_cors' ), 999 );
	}

	// ── Usage accumulator (reset per product import) ─────────────────────────
	private static $usage_accumulator = array();

	private static function reset_usage_accumulator(): void {
		self::$usage_accumulator = array();
	}

	private static function push_usage( string $model, string $provider, string $call_type, int $input_tokens, int $output_tokens ): void {
		self::$usage_accumulator[] = array(
			'model'         => $model,
			'provider'      => $provider,
			'call_type'     => $call_type,
			'input_tokens'  => $input_tokens,
			'output_tokens' => $output_tokens,
		);
	}

	public static function flush_usage_to_db( int $product_id, string $product_title ): void {
		foreach ( self::$usage_accumulator as $entry ) {
			self::log_ai_usage(
				$product_id,
				$product_title,
				$entry['model'],
				$entry['provider'],
				$entry['call_type'],
				$entry['input_tokens'],
				$entry['output_tokens']
			);
		}
		self::reset_usage_accumulator();
	}

	// ── Usage / pricing ──────────────────────────────────────────────────────

	public static function get_model_pricing( string $model ): array {
		$prices = array(
			// OpenAI — $ per 1M tokens
			'gpt-4o-mini'              => array( 'input' => 0.15,  'output' => 0.60 ),
			'gpt-4o-mini-2024-07-18'   => array( 'input' => 0.15,  'output' => 0.60 ),
			'gpt-4o'                   => array( 'input' => 2.50,  'output' => 10.00 ),
			'gpt-4o-2024-11-20'        => array( 'input' => 2.50,  'output' => 10.00 ),
			'gpt-4o-2024-08-06'        => array( 'input' => 2.50,  'output' => 10.00 ),
			'gpt-4.1'                  => array( 'input' => 2.00,  'output' => 8.00 ),
			'gpt-4.1-mini'             => array( 'input' => 0.40,  'output' => 1.60 ),
			'gpt-4.1-nano'             => array( 'input' => 0.10,  'output' => 0.40 ),
			'gpt-3.5-turbo'            => array( 'input' => 0.50,  'output' => 1.50 ),
			'gpt-3.5-turbo-0125'       => array( 'input' => 0.50,  'output' => 1.50 ),
			'o1-mini'                  => array( 'input' => 1.10,  'output' => 4.40 ),
			// Gemini — $ per 1M tokens
			'gemini-2.5-flash'         => array( 'input' => 0.15,  'output' => 0.60 ),
			'gemini-2.5-flash-preview-04-17' => array( 'input' => 0.15, 'output' => 0.60 ),
			'gemini-2.0-flash'         => array( 'input' => 0.10,  'output' => 0.40 ),
			'gemini-1.5-flash'         => array( 'input' => 0.075, 'output' => 0.30 ),
			'gemini-1.5-flash-8b'      => array( 'input' => 0.0375,'output' => 0.15 ),
			'gemini-1.5-pro'           => array( 'input' => 1.25,  'output' => 5.00 ),
		);
		return isset( $prices[ $model ] ) ? $prices[ $model ] : array( 'input' => 0.0, 'output' => 0.0 );
	}

	public static function calculate_cost( string $model, int $input_tokens, int $output_tokens ): float {
		$pricing = self::get_model_pricing( $model );
		return ( $input_tokens * $pricing['input'] + $output_tokens * $pricing['output'] ) / 1000000;
	}

	public static function create_usage_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'awi_usage_log';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE IF NOT EXISTS {$table} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			product_title VARCHAR(255) NOT NULL DEFAULT '',
			model       VARCHAR(100) NOT NULL DEFAULT '',
			provider    VARCHAR(20)  NOT NULL DEFAULT '',
			call_type   VARCHAR(30)  NOT NULL DEFAULT '',
			input_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
			output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			cost_usd    DECIMAL(14,8) NOT NULL DEFAULT 0.00000000,
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY product_id (product_id),
			KEY model (model),
			KEY created_at (created_at)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function log_ai_usage( int $product_id, string $product_title, string $model, string $provider, string $call_type, int $input_tokens, int $output_tokens ): void {
		if ( $input_tokens === 0 && $output_tokens === 0 ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'awi_usage_log',
			array(
				'product_id'    => $product_id,
				'product_title' => mb_substr( $product_title, 0, 255 ),
				'model'         => $model,
				'provider'      => $provider,
				'call_type'     => $call_type,
				'input_tokens'  => $input_tokens,
				'output_tokens' => $output_tokens,
				'cost_usd'      => self::calculate_cost( $model, $input_tokens, $output_tokens ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%f' )
		);
	}

	private static function extract_basic_auth_credentials( WP_REST_Request $request ): array {
		$src  = '';
		$user = '';
		$pass = '';

		// Collect raw Authorization header from every source Apache/Nginx/FastCGI may use.
		$raw_header = '';
		foreach ( array(
			$request->get_header( 'authorization' ),            // WP parsed header (works on most hosts)
			isset( $_SERVER['HTTP_AUTHORIZATION'] )          ? $_SERVER['HTTP_AUTHORIZATION']          : '',
			isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '',
			// FastCGI / CGI fallback (requires RewriteRule in .htaccess or server config)
			isset( $_SERVER['HTTP_X_AUTHORIZATION'] )        ? $_SERVER['HTTP_X_AUTHORIZATION']        : '',
		) as $candidate ) {
			if ( is_string( $candidate ) && trim( $candidate ) !== '' ) {
				$raw_header = trim( $candidate );
				break;
			}
		}

		if ( $raw_header !== '' ) {
			$src = 'header';
			if ( preg_match( '/^Basic\s+(.+)$/i', $raw_header, $m ) ) {
				$decoded = base64_decode( trim( (string) $m[1] ), true );
				if ( is_string( $decoded ) && strpos( $decoded, ':' ) !== false ) {
					$parts = explode( ':', $decoded, 2 );
					$user  = isset( $parts[0] ) ? (string) $parts[0] : '';
					$pass  = isset( $parts[1] ) ? (string) $parts[1] : '';
				}
			}
		}

		// Fallback to PHP_AUTH_* (populated by Apache mod_php automatically).
		if ( $user === '' && isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			$src  = 'php_auth';
			$user = (string) $_SERVER['PHP_AUTH_USER'];
			$pass = (string) $_SERVER['PHP_AUTH_PW'];
		}

		return array(
			'source'   => $src,
			'username' => $user,
			'password' => $pass,
		);
	}

	private static function maybe_authenticate_request( WP_REST_Request $request ) {
		if ( is_user_logged_in() && get_current_user_id() ) {
			return wp_get_current_user();
		}

		if ( ! function_exists( 'wp_authenticate_application_password' ) ) {
			return new WP_Error( 'awi_no_app_passwords', 'Application Passwords auth is not available.', array( 'status' => 500 ) );
		}

		$creds = self::extract_basic_auth_credentials( $request );
		$user  = trim( (string) $creds['username'] );
		$pass  = (string) $creds['password'];
		if ( $user === '' || $pass === '' ) {
			return null;
		}

		// Help WP core's app-password validator on hosts that do not populate PHP_AUTH_*.
		if ( ! isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			$_SERVER['PHP_AUTH_USER'] = $user;
			$_SERVER['PHP_AUTH_PW']   = $pass;
		}

		$authenticated = wp_authenticate_application_password( null, $user, $pass );
		if ( $authenticated instanceof WP_User ) {
			wp_set_current_user( $authenticated->ID );
			return $authenticated;
		}

		if ( is_wp_error( $authenticated ) ) {
			return new WP_Error(
				'awi_app_password_auth_failed',
				$authenticated->get_error_message(),
				array(
					'status'        => 401,
					'inner_code'    => $authenticated->get_error_code(),
					'inner_message' => $authenticated->get_error_message(),
				)
			);
		}

		return null;
	}

	public static function register_routes(): void {
		register_rest_route(
			'awi/v1',
			'/import',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'callback'            => array( __CLASS__, 'handle_import' ),
			)
		);

			register_rest_route(
				'awi/v1',
				'/ping',
				array(
					'methods'             => 'GET',
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
					'callback'            => array( __CLASS__, 'handle_ping' ),
				)
			);

			register_rest_route(
				'awi/v1',
				'/categories',
				array(
					'methods'             => 'GET',
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
					'callback'            => array( __CLASS__, 'handle_categories' ),
				)
			);

			register_rest_route(
				'awi/v1',
				'/settings',
				array(
					array(
						'methods'             => 'GET',
						'permission_callback' => array( __CLASS__, 'permissions_check' ),
						'callback'            => array( __CLASS__, 'handle_get_settings' ),
					),
					array(
						'methods'             => 'POST',
						'permission_callback' => array( __CLASS__, 'permissions_check' ),
						'callback'            => array( __CLASS__, 'handle_set_settings' ),
					),
				)
			);

		register_rest_route(
			'awi/v1',
			'/connect',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'connect_permissions_check' ),
				'callback'            => array( __CLASS__, 'handle_connect' ),
			)
		);

		// /pending-connect is intentionally unauthenticated: the Chrome extension calls it
		// to collect credentials immediately after /connect creates them. At that point the
		// extension has no credentials yet, so requiring auth would break the bootstrap flow.
		// Security is provided by: (a) a 48-hex-char (192-bit) cryptographically random token,
		// (b) token is single-use and deleted on first successful read, (c) 5-minute TTL,
		// (d) IP binding — the retrieving IP must match the IP that called /connect.
		register_rest_route(
			'awi/v1',
			'/pending-connect',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'handle_pending_connect' ),
			)
		);

		// Diagnostics endpoint to help debug auth/header issues from the Chrome extension.
		// Safe by default: only accessible from localhost (127.0.0.1/::1) or admins.
		register_rest_route(
			'awi/v1',
			'/debug',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'debug_permissions_check' ),
				'callback'            => array( __CLASS__, 'handle_debug' ),
			)
		);
	}

	public static function setup_cors(): void {
		// WordPress core may sanitize `chrome-extension://...` Origin to an empty string,
		// producing an invalid `Access-Control-Allow-Origin:` header. We patch it *only*
		// for chrome-extension origins, leaving core behavior intact for normal sites.
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'send_cors_headers' ), 9999 );
	}

	public static function send_cors_headers( $value ) {
		$origin = get_http_origin();
		if ( ! $origin ) {
			return $value;
		}
		$origin = (string) $origin;

		$allow_origin = '';
		if ( preg_match( '#^chrome-extension://[a-p]{32}$#', $origin ) ) {
			$allow_origin = $origin;
		} elseif ( 'null' === $origin ) {
			// Some browser contexts send Origin: null.
			$allow_origin = 'null';
		} else {
			// Do not interfere with normal web origins.
			return $value;
		}

		// Replace any previously set (possibly invalid) header.
		if ( function_exists( 'header_remove' ) ) {
			header_remove( 'Access-Control-Allow-Origin' );
		}
		if ( $allow_origin !== '' ) {
			header( 'Access-Control-Allow-Origin: ' . $allow_origin );
			header( 'Vary: Origin', false );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
		}

		return $value;
	}

		public static function permissions_check( WP_REST_Request $request ) {
			$auth = self::maybe_authenticate_request( $request );
			if ( is_wp_error( $auth ) ) {
				return $auth;
			}

			if ( ! is_user_logged_in() ) {
				return new WP_Error( 'awi_not_logged_in', 'Authentication required (Application Password).', array( 'status' => 401 ) );
			}

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new WP_Error( 'awi_forbidden', 'User lacks manage_woocommerce capability.', array( 'status' => 403 ) );
			}

			return true;
		}

	public static function debug_permissions_check( WP_REST_Request $request ): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		if ( in_array( $ip, array( '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}

		public static function handle_debug( WP_REST_Request $request ) {
			// Never return secrets. Only return presence/length + environment info.
		$origin = get_http_origin();
		$origin = $origin ? (string) $origin : '';

		$hdr = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$hdr = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$hdr = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}

		$scheme = '';
		if ( $hdr ) {
			$parts  = preg_split( '/\s+/', trim( $hdr ) );
			$scheme = isset( $parts[0] ) ? strtolower( (string) $parts[0] ) : '';
		}

		$php_auth_user = isset( $_SERVER['PHP_AUTH_USER'] ) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
		$php_auth_pw   = isset( $_SERVER['PHP_AUTH_PW'] ) ? (string) $_SERVER['PHP_AUTH_PW'] : '';

			$user_id = (int) get_current_user_id();

		$app_pw_available = null;
		if ( function_exists( 'wp_is_application_passwords_available' ) ) {
			$app_pw_available = (bool) wp_is_application_passwords_available();
		}
		$app_pw_in_use = null;
		if ( class_exists( 'WP_Application_Passwords' ) && method_exists( 'WP_Application_Passwords', 'is_in_use' ) ) {
			$app_pw_in_use = (bool) WP_Application_Passwords::is_in_use();
		}
			$network_id  = function_exists( 'get_main_network_id' ) ? (int) get_main_network_id() : 0;
			$in_use_opt  = function_exists( 'get_network_option' ) ? get_network_option( $network_id, 'using_application_passwords' ) : null;

			$creds = self::extract_basic_auth_credentials( $request );
			$auth_test = array(
				'attempted'  => false,
				'source'     => (string) $creds['source'],
				'user_set'   => $creds['username'] !== '',
				'pass_set'   => $creds['password'] !== '',
				'user_exists'=> false,
				'available_for_user' => null,
				'result'     => null,
			);

			$u = trim( (string) $creds['username'] );
			$p = (string) $creds['password'];
			if ( $u !== '' && $p !== '' && function_exists( 'wp_authenticate_application_password' ) ) {
				$auth_test['attempted'] = true;
				$wp_user = get_user_by( 'login', $u );
				if ( ! $wp_user && is_email( $u ) ) {
					$wp_user = get_user_by( 'email', $u );
				}
				$auth_test['user_exists'] = $wp_user instanceof WP_User;
				if ( $wp_user instanceof WP_User && function_exists( 'wp_is_application_passwords_available_for_user' ) ) {
					$auth_test['available_for_user'] = (bool) wp_is_application_passwords_available_for_user( $wp_user );
				}

				$r = wp_authenticate_application_password( null, $u, $p );
				if ( $r instanceof WP_User ) {
					$auth_test['result'] = array( 'ok' => true, 'user_id' => (int) $r->ID, 'user_login' => (string) $r->user_login );
				} elseif ( is_wp_error( $r ) ) {
					$auth_test['result'] = array( 'ok' => false, 'code' => $r->get_error_code(), 'message' => $r->get_error_message() );
				} else {
					$auth_test['result'] = array( 'ok' => false, 'code' => 'unknown', 'message' => 'No user and no error returned.' );
				}
			}

			return new WP_REST_Response(
				array(
					'ok'       => true,
					'time'     => gmdate( 'c' ),
				'home'     => home_url( '/' ),
				'rest'     => rest_url(),
				'origin'   => $origin,
				'remote'   => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
				'proto'    => isset( $_SERVER['SERVER_PROTOCOL'] ) ? (string) $_SERVER['SERVER_PROTOCOL'] : '',
				'auth'     => array(
					'http_authorization_present' => $hdr !== '',
					'http_authorization_len'     => $hdr !== '' ? strlen( $hdr ) : 0,
					'http_authorization_scheme'  => $scheme,
					'php_auth_user_present'      => $php_auth_user !== '',
					'php_auth_pw_present'        => $php_auth_pw !== '',
				),
					'wp'       => array(
						'current_user_id'                 => $user_id,
						'current_user_can_manage_options' => current_user_can( 'manage_options' ),
						'application_passwords_available' => $app_pw_available,
						'application_passwords_in_use'    => $app_pw_in_use,
						'using_application_passwords_opt' => $in_use_opt,
					),
					'auth_test' => $auth_test,
					'server'   => array(
						'has_mod_rewrite'   => function_exists( 'apache_get_modules' ) ? in_array( 'mod_rewrite', (array) apache_get_modules(), true ) : null,
						'has_mod_setenvif'  => function_exists( 'apache_get_modules' ) ? in_array( 'mod_setenvif', (array) apache_get_modules(), true ) : null,
					),
				),
				200
			);
		}

		public static function handle_ping( WP_REST_Request $request ) {
			nocache_headers();
			$u = wp_get_current_user();
			return new WP_REST_Response(
				array(
					'ok'                    => true,
					'user_id'                => (int) $u->ID,
					'user_login'             => (string) $u->user_login,
					'can_manage_woocommerce' => current_user_can( 'manage_woocommerce' ),
					'can_manage_options'     => current_user_can( 'manage_options' ),
				),
				200
			);
		}

		public static function connect_permissions_check( WP_REST_Request $request ): bool {
			return is_user_logged_in() && current_user_can( 'manage_woocommerce' );
		}

		public static function handle_connect( WP_REST_Request $request ): WP_REST_Response {
			nocache_headers();

			if ( ! class_exists( 'WP_Application_Passwords' ) ) {
				return new WP_REST_Response(
					array( 'ok' => false, 'error' => 'Application Passwords require WordPress 5.6 or newer.' ),
					500
				);
			}

			$user_id = get_current_user_id();
			$user    = wp_get_current_user();

			// Revoke any existing "ATW Extension" password so we always return a fresh one.
			$existing = WP_Application_Passwords::get_user_application_passwords( $user_id );
			if ( is_array( $existing ) ) {
				foreach ( $existing as $pw ) {
					if ( isset( $pw['name'] ) && $pw['name'] === 'ATW Extension' ) {
						WP_Application_Passwords::delete_application_password( $user_id, (string) $pw['uuid'] );
					}
				}
			}

			$result = WP_Application_Passwords::create_new_application_password(
				$user_id,
				array(
					'name'   => 'ATW Extension',
					'app_id' => 'atw-woocommerce-importer',
				)
			);

			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response(
					array( 'ok' => false, 'error' => $result->get_error_message() ),
					500
				);
			}

			[ $plain_password ] = $result;

			$token      = bin2hex( random_bytes( 24 ) );
			$origin_ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

			set_transient(
				'awi_pending_connect_' . $token,
				array(
					'site_url'     => rtrim( home_url( '/' ), '/' ),
					'username'     => (string) $user->user_login,
					'app_password' => (string) $plain_password,
					'origin_ip'    => $origin_ip,
				),
				300
			);

			return new WP_REST_Response(
				array(
					'ok'      => true,
					'token'   => $token,
					'site_url'=> rtrim( home_url( '/' ), '/' ),
				),
				200
			);
		}

		public static function handle_pending_connect( WP_REST_Request $request ): WP_REST_Response {
			nocache_headers();

			$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
			if ( $token === '' ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'Missing token.' ), 400 );
			}

			$data = get_transient( 'awi_pending_connect_' . $token );
			if ( ! is_array( $data ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'Token expired or invalid.' ), 404 );
			}

			// Verify the request comes from the same IP that triggered /connect.
			// Skipped when origin_ip is empty (old transients) or when behind a reverse proxy
			// where REMOTE_ADDR may differ from the browser IP.
			$stored_ip  = isset( $data['origin_ip'] ) ? (string) $data['origin_ip'] : '';
			$request_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
			if ( $stored_ip !== '' && $request_ip !== '' && $stored_ip !== $request_ip ) {
				// Consume token to prevent replay attempts, then reject.
				delete_transient( 'awi_pending_connect_' . $token );
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'Token not valid for this client.' ), 403 );
			}

			delete_transient( 'awi_pending_connect_' . $token );

			return new WP_REST_Response(
				array(
					'ok'           => true,
					'site_url'     => (string) $data['site_url'],
					'username'     => (string) $data['username'],
					'app_password' => (string) $data['app_password'],
				),
				200
			);
		}

		public static function handle_categories( WP_REST_Request $request ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			);

			if ( is_wp_error( $terms ) ) {
				return new WP_REST_Response(
					array(
						'ok'    => false,
						'error' => $terms->get_error_message(),
					),
					500
				);
			}

			$out = array();
			foreach ( (array) $terms as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$out[] = array(
					'id'     => (int) $term->term_id,
					'name'   => (string) $term->name,
					'slug'   => (string) $term->slug,
					'parent' => (int) $term->parent,
					'path'   => self::get_product_category_path( (int) $term->term_id ),
				);
			}

			return new WP_REST_Response(
				array(
					'ok'         => true,
					'categories' => $out,
				),
				200
			);
		}

		public static function handle_get_settings( WP_REST_Request $request ) {
			$user_id = (int) get_current_user_id();
			if ( $user_id <= 0 ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'Not authenticated.' ), 401 );
			}
			$raw = get_user_meta( $user_id, '_awi_extension_settings_v1', true );
			$out = self::normalize_extension_settings( is_array( $raw ) ? $raw : array() );
			return new WP_REST_Response(
				array(
					'ok'       => true,
					'settings' => $out,
				),
				200
			);
		}

		public static function handle_set_settings( WP_REST_Request $request ) {
			$user_id = (int) get_current_user_id();
			if ( $user_id <= 0 ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'Not authenticated.' ), 401 );
			}

			$p = $request->get_json_params();
			if ( ! is_array( $p ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'Invalid JSON payload.' ), 400 );
			}

			$in = isset( $p['settings'] ) && is_array( $p['settings'] ) ? $p['settings'] : $p;
			if ( ! is_array( $in ) ) {
				$in = array();
			}

			$normalized = self::normalize_extension_settings( $in );
			update_user_meta( $user_id, '_awi_extension_settings_v1', $normalized );

			return new WP_REST_Response(
				array(
					'ok'       => true,
					'settings' => $normalized,
				),
				200
			);
		}

	public static function handle_import( WP_REST_Request $request ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'WooCommerce is not active.',
				),
				400
			);
		}

		$p = $request->get_json_params();
		if ( ! is_array( $p ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'Invalid JSON payload.',
				),
				400
			);
		}

		$import_user_id = (int) get_current_user_id();
		$quota          = AWI_Rate_Limiter::check( $import_user_id );
		if ( ! $quota['allowed'] ) {
			return new WP_REST_Response(
				array(
					'ok'               => false,
					'error'            => 'rate_limited',
					'message'          => 'Import limit reached. Cooldown expires at ' . gmdate( 'Y-m-d H:i', $quota['cooldown_until'] ) . ' UTC.',
					'cooldown_until'   => $quota['cooldown_until'],
					'cooldown_seconds' => $quota['cooldown_seconds'],
				),
				429
			);
		}

		self::reset_usage_accumulator();

		$original_sku = isset( $p['sku'] ) ? trim( (string) $p['sku'] ) : '';
		$name         = isset( $p['name'] ) ? trim( (string) $p['name'] ) : '';
		$source_url   = isset( $p['source_url'] ) ? trim( (string) $p['source_url'] ) : '';

		if ( $name === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Missing name.' ), 400 );
		}

		$update_existing = ! empty( $p['update_existing'] );
		$download_images = array_key_exists( 'download_images', $p ) ? (bool) $p['download_images'] : true;

			$wants_variable = ! empty( $p['variations'] ) && is_array( $p['variations'] );
			$attr_payload   = ( ! empty( $p['attributes'] ) && is_array( $p['attributes'] ) ) ? $p['attributes'] : array();
			$var_payload    = $wants_variable ? $p['variations'] : array();
			$category_ids   = self::parse_category_ids(
				isset( $p['category_ids'] ) ? $p['category_ids'] : ( isset( $p['category_id'] ) ? $p['category_id'] : null )
			);
			$import_copy    = self::prepare_rewritten_import_copy( $p, $attr_payload, $var_payload );
			$name           = isset( $import_copy['name'] ) ? trim( (string) $import_copy['name'] ) : $name;

		$existing_id = $original_sku !== '' ? (int) wc_get_product_id_by_sku( $original_sku ) : 0;
		if ( $existing_id <= 0 && $original_sku !== '' ) {
			$existing_id = self::find_product_id_by_sku_meta( $original_sku );
		}
		if ( $existing_id <= 0 && $original_sku !== '' ) {
			$existing_id = self::find_product_id_by_original_sku_meta( $original_sku );
		}
		$is_update   = $existing_id > 0;
		if ( $is_update ) {
			$existing_product = wc_get_product( $existing_id );
			if ( ! $existing_product ) {
				// Stale lookup row points to non-existent product.
				if ( $original_sku !== '' ) {
					self::cleanup_stale_sku_lookup_entries( $original_sku );
					$existing_id = self::find_product_id_by_sku_meta( $original_sku );
					if ( $existing_id <= 0 ) {
						$existing_id = self::find_product_id_by_original_sku_meta( $original_sku );
					}
				}
				$is_update = $existing_id > 0;
			}
		}
		if ( $is_update && ! $update_existing ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'Product already exists (updates disabled).',
				),
				409
			);
		}

		$product = null;
		if ( $is_update ) {
			$product = wc_get_product( $existing_id );
		} else {
			$product = $wants_variable ? new WC_Product_Variable() : new WC_Product_Simple();
		}
		if ( ! $product ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Failed to create/load product.' ), 500 );
		}

		// If we are updating and the incoming payload wants variations, ensure product type is variable.
		if ( $is_update && $wants_variable && ! $product->is_type( 'variable' ) ) {
			wp_set_object_terms( $existing_id, 'variable', 'product_type' );
			$product = new WC_Product_Variable( $existing_id );
		}

		// Make sure new products actually show up in Products list.
		if ( ! $is_update ) {
			$product->set_status( 'publish' );
		}

		// Always use formatted SKU (F0G000K pattern). Keep existing for updates, generate fresh for new.
		$sku = '';
		if ( $is_update && $product instanceof WC_Product ) {
			$sku = trim( (string) $product->get_sku() );
		}
		if ( $sku === '' ) {
			$sku = self::generate_unique_sku();
		}

		$product->set_sku( $sku );
		$product->set_name( $name );
		self::maybe_set_optimized_slug( $product, $name, $sku, $source_url, $is_update );

		if ( $import_copy['description'] !== '' ) {
			$product->set_description( $import_copy['description'] );
		}
		if ( $import_copy['short_description'] !== '' ) {
			$product->set_short_description( $import_copy['short_description'] );
		}

		if ( isset( $p['regular_price'] ) && $p['regular_price'] !== '' && is_numeric( $p['regular_price'] ) ) {
			$product->set_regular_price( (string) $p['regular_price'] );
		}

			try {
				$product_id = (int) $product->save();
		} catch ( Throwable $e ) {
			// Duplicate SKU lookup-table errors are usually recoverable from stale Woo lookup rows.
			if ( self::is_duplicate_sku_lookup_error( $e->getMessage() ) ) {
				self::cleanup_stale_sku_lookup_entries( $sku );
				$fallback_id = self::find_product_id_by_sku_meta( $sku );
				if ( $fallback_id > 0 ) {
					$fallback_product = wc_get_product( $fallback_id );
					if ( $fallback_product ) {
						$product   = $fallback_product;
						$is_update = true;
					}
				}

				$product->set_name( $name );
				self::maybe_set_optimized_slug( $product, $name, $sku, $source_url, $is_update );
				if ( $import_copy['description'] !== '' ) {
					$product->set_description( $import_copy['description'] );
				}
				if ( $import_copy['short_description'] !== '' ) {
					$product->set_short_description( $import_copy['short_description'] );
				}
				if ( isset( $p['regular_price'] ) && $p['regular_price'] !== '' && is_numeric( $p['regular_price'] ) ) {
					$product->set_regular_price( (string) $p['regular_price'] );
				}

				try {
					$product_id = (int) $product->save();
				} catch ( Throwable $retry_e ) {
					return new WP_REST_Response(
						array(
							'ok'    => false,
							'error' => 'Import failed after SKU-lookup cleanup: ' . $retry_e->getMessage(),
						),
						500
					);
				}
			}

			if ( empty( $product_id ) ) {
				return new WP_REST_Response(
					array(
						'ok'    => false,
						'error' => 'Import failed while saving product: ' . $e->getMessage(),
					),
					500
				);
			}
		}
			if ( $product_id <= 0 ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'Failed to save product.' ), 500 );
			}

			if ( ! empty( $category_ids ) ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$product->set_category_ids( $category_ids );
					$product->save();
				}
			}

			$tag_names = self::build_product_tag_names( $p, $name, $attr_payload, $category_ids, $product_id );
			if ( $tag_names ) {
				wp_set_object_terms( $product_id, $tag_names, 'product_tag', false );
			}

		self::flush_usage_to_db( $product_id, $name );

		// Meta for traceability.
		update_post_meta( $product_id, '_awi_source', 'chrome_extension' );
		if ( $source_url !== '' ) {
			update_post_meta( $product_id, '_awi_source_url', esc_url_raw( $source_url ) );
		}
		if ( $original_sku !== '' ) {
			update_post_meta( $product_id, '_awi_original_sku', sanitize_text_field( $original_sku ) );
		}
		foreach ( array( 'visit_country', 'currency', 'price_raw', 'moq', 'min_order_unit', 'supplier_name' ) as $k ) {
			if ( isset( $p[ $k ] ) && $p[ $k ] !== '' ) {
				update_post_meta( $product_id, '_awi_' . $k, sanitize_text_field( (string) $p[ $k ] ) );
			}
		}
		if ( ! empty( $p['raw'] ) && is_array( $p['raw'] ) ) {
			// Keep raw JSON for debugging, capped.
			$raw = wp_json_encode( $p['raw'] );
			if ( is_string( $raw ) ) {
				update_post_meta( $product_id, '_awi_raw', substr( $raw, 0, 200000 ) );
			}
		}

		$video_urls   = self::normalize_video_urls( isset( $p['video_urls'] ) ? $p['video_urls'] : array() );
		$video_poster = '';
		if ( isset( $p['video_poster'] ) && is_string( $p['video_poster'] ) ) {
			$video_poster = self::normalize_url( $p['video_poster'] );
		}
			self::upsert_product_video_data( $product_id, $video_urls, $video_poster );
			self::remove_awi_video_embed_from_description( $product_id );

		if ( $wants_variable && $product instanceof WC_Product_Variable ) {
			self::apply_variable_attributes( $product_id, $attr_payload );
			self::upsert_variations( $product_id, $var_payload, $download_images );

			// Parent images still apply.
			if ( $download_images && ! empty( $p['images'] ) && is_array( $p['images'] ) ) {
				$images  = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $p['images'] ) ) ) ) );
				$images  = array_slice( $images, 0, 12 ); // guardrail
				$att_ids = self::sideload_images( $images, $product_id );
				if ( $att_ids ) {
					$product = wc_get_product( $product_id );
					if ( $product ) {
						if ( ! $product->get_image_id() && isset( $att_ids[0] ) ) {
							$product->set_image_id( (int) $att_ids[0] );
						}
						if ( count( $att_ids ) > 1 ) {
							$product->set_gallery_image_ids( array_map( 'intval', array_slice( $att_ids, 1 ) ) );
						}
						$product->save();
					}
				}
			}
		} else {
			// Simple product images.
			if ( $download_images && ! empty( $p['images'] ) && is_array( $p['images'] ) ) {
				$images  = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $p['images'] ) ) ) ) );
				$images  = array_slice( $images, 0, 12 ); // guardrail
				$att_ids = self::sideload_images( $images, $product_id );
				if ( $att_ids ) {
					$product = wc_get_product( $product_id );
					if ( $product ) {
						if ( ! $product->get_image_id() && isset( $att_ids[0] ) ) {
							$product->set_image_id( (int) $att_ids[0] );
						}
						if ( count( $att_ids ) > 1 ) {
							$product->set_gallery_image_ids( array_map( 'intval', array_slice( $att_ids, 1 ) ) );
						}
						$product->save();
					}
				}
			}
		}
		// Theme compatibility: some galleries (e.g. Templatemela) read video URL from attachment meta.
		self::sync_templatemela_gallery_video_meta( $product_id, $video_urls );

		// Record successful import against the user's rate limit quota.
		$quota_after = AWI_Rate_Limiter::record( $import_user_id );

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'product_id'  => $product_id,
				'created'     => ! $is_update,
				'updated'     => $is_update,
				'sku'         => $sku,
				'source_url'  => $source_url,
				'quota'       => array(
					'remaining'        => $quota_after['remaining'],
					'cooldown_until'   => $quota_after['cooldown_until'],
					'cooldown_seconds' => $quota_after['cooldown_seconds'],
					'window_reset_at'  => $quota_after['window_reset_at'],
				),
			),
			200
		);
	}

	private static function apply_variable_attributes( int $product_id, array $attributes_payload ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}

		$out = array();
		foreach ( $attributes_payload as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$name = isset( $a['name'] ) ? sanitize_text_field( (string) $a['name'] ) : '';
			if ( $name === '' ) {
				continue;
			}
			$options = isset( $a['options'] ) && is_array( $a['options'] ) ? $a['options'] : array();
			$options = array_values(
				array_unique(
					array_filter(
						array_map(
							static fn( $v ) => sanitize_text_field( (string) $v ),
							$options
						)
					)
				)
			);
			if ( ! $options ) {
				continue;
			}

			$attr = new WC_Product_Attribute();
			$attr->set_name( $name ); // custom product attribute (non-taxonomy)
			$attr->set_options( $options );
			$attr->set_visible( true );
			$attr->set_variation( true );
			$out[] = $attr;
		}

		if ( $out ) {
			$product->set_attributes( $out );
			$product->save();
		}
	}

	private static function upsert_variations( int $product_id, array $variations_payload, bool $download_images ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}

		// Map existing variations by SKU for updates.
		$existing_by_sku = array();
		foreach ( $product->get_children() as $child_id ) {
			$v = wc_get_product( $child_id );
			if ( $v && $v instanceof WC_Product_Variation ) {
				$vs = (string) $v->get_sku();
				if ( $vs !== '' ) {
					$existing_by_sku[ $vs ] = (int) $child_id;
				}
			}
		}

		foreach ( $variations_payload as $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			$sku = isset( $v['sku'] ) ? trim( (string) $v['sku'] ) : '';
			if ( $sku === '' ) {
				continue;
			}

			$variation = null;
			if ( isset( $existing_by_sku[ $sku ] ) ) {
				$variation = wc_get_product( (int) $existing_by_sku[ $sku ] );
			}
			if ( ! $variation || ! ( $variation instanceof WC_Product_Variation ) ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product_id );
				$variation->set_status( 'publish' );
			}

			$variation->set_sku( $sku );

			if ( isset( $v['regular_price'] ) && $v['regular_price'] !== '' && is_numeric( $v['regular_price'] ) ) {
				$variation->set_regular_price( (string) $v['regular_price'] );
			}

			// Variation attributes (custom attributes): keys are sanitized from the attribute name.
			$attrs_in  = isset( $v['attributes'] ) && is_array( $v['attributes'] ) ? $v['attributes'] : array();
			$attrs_out = array();
			foreach ( $attrs_in as $k => $val ) {
				$k   = sanitize_title( (string) $k );
				$val = sanitize_text_field( (string) $val );
				if ( $k !== '' && $val !== '' ) {
					$attrs_out[ $k ] = $val;
				}
			}
			if ( $attrs_out ) {
				$variation->set_attributes( $attrs_out );
			}

			// Stock.
			if ( isset( $v['stock_quantity'] ) && is_numeric( $v['stock_quantity'] ) ) {
				$q = (int) $v['stock_quantity'];
				// Alibaba often uses huge placeholder inventory; don't import absurd stock numbers.
				if ( $q > 0 && $q < 1000000 ) {
					$variation->set_manage_stock( true );
					$variation->set_stock_quantity( $q );
					$variation->set_stock_status( 'instock' );
				} else {
					$variation->set_manage_stock( false );
					$variation->set_stock_status( 'instock' );
				}
			} else {
				$variation->set_manage_stock( false );
				$variation->set_stock_status( 'instock' );
			}

			$variation_id = (int) $variation->save();

			// Variation image (optional).
			if ( $download_images && $variation_id > 0 && ! empty( $v['image'] ) ) {
				$att_ids = self::sideload_images( array( (string) $v['image'] ), $variation_id );
				if ( $att_ids && isset( $att_ids[0] ) ) {
					$vv = wc_get_product( $variation_id );
					if ( $vv && $vv instanceof WC_Product_Variation ) {
						$vv->set_image_id( (int) $att_ids[0] );
						$vv->save();
					}
				}
			}
		}

		// Recalculate variable product data.
		if ( class_exists( 'WC_Product_Variable' ) && method_exists( 'WC_Product_Variable', 'sync' ) ) {
			WC_Product_Variable::sync( $product_id );
		}
		wc_delete_product_transients( $product_id );
	}

	private static function sideload_images( array $urls, int $product_post_id ): array {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$out = array();
		foreach ( $urls as $url ) {
			$url = self::normalize_url( (string) $url );
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				continue;
			}
			$att_id = media_sideload_image( $url, $product_post_id, null, 'id' );
			if ( is_wp_error( $att_id ) ) {
				continue;
			}
			$out[] = (int) $att_id;
		}
		return $out;
	}

	private static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
		if ( substr( $url, 0, 2 ) === '//' ) {
			return 'https:' . $url;
		}
		return $url;
	}

	private static function normalize_extension_settings( array $in ): array {
		$out = array(
			'updateExisting'         => true,
			'downloadImages'         => true,
			'showCardButtons'        => true,
			'maxPageItems'           => 20,
			'defaultCategoryId'      => 0,
			'askCategoryBeforeImport'=> true,
			'importedProductUrls'    => array(),
		);

		if ( array_key_exists( 'updateExisting', $in ) ) {
			$out['updateExisting'] = (bool) $in['updateExisting'];
		}
		if ( array_key_exists( 'downloadImages', $in ) ) {
			$out['downloadImages'] = (bool) $in['downloadImages'];
		}
		if ( array_key_exists( 'showCardButtons', $in ) ) {
			$out['showCardButtons'] = (bool) $in['showCardButtons'];
		}
		if ( array_key_exists( 'askCategoryBeforeImport', $in ) ) {
			$out['askCategoryBeforeImport'] = (bool) $in['askCategoryBeforeImport'];
		}

		$max = isset( $in['maxPageItems'] ) ? (int) $in['maxPageItems'] : 20;
		if ( $max < 1 ) {
			$max = 1;
		}
		if ( $max > 200 ) {
			$max = 200;
		}
		$out['maxPageItems'] = $max;

		$cat = isset( $in['defaultCategoryId'] ) ? (int) $in['defaultCategoryId'] : 0;
		if ( $cat < 0 ) {
			$cat = 0;
		}
		$out['defaultCategoryId'] = $cat;

		$urls = isset( $in['importedProductUrls'] ) && is_array( $in['importedProductUrls'] ) ? $in['importedProductUrls'] : array();
		$clean_urls = array();
		foreach ( $urls as $u ) {
			$u = trim( (string) $u );
			if ( $u === '' ) {
				continue;
			}
			if ( strlen( $u ) > 255 ) {
				continue;
			}
			$clean_urls[] = sanitize_text_field( $u );
			if ( count( $clean_urls ) >= 500 ) {
				break;
			}
		}
		$out['importedProductUrls'] = array_values( array_unique( $clean_urls ) );

		return $out;
	}

	private static function parse_category_ids( $value ): array {
		if ( is_numeric( $value ) ) {
			$value = array( (int) $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $v ) {
			$id = (int) $v;
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		$out = array_values( array_unique( $out ) );
		if ( ! $out ) {
			return array();
		}

		$valid = array();
		foreach ( $out as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$valid[] = (int) $id;
			}
		}
		return $valid;
	}

	private static function maybe_set_optimized_slug( WC_Product $product, string $name, string $sku, string $source_url, bool $is_update ): void {
		$existing_slug = '';
		if ( $is_update && $product->get_id() > 0 ) {
			$existing_slug = (string) get_post_field( 'post_name', $product->get_id() );
		}
		if ( $is_update && $existing_slug !== '' ) {
			return;
		}

		$slug = self::build_optimized_slug( $name, $sku, $source_url );
		if ( $slug !== '' ) {
			$product->set_slug( $slug );
		}
	}

	private static function build_optimized_slug( string $name, string $sku, string $source_url ): string {
		$slug = sanitize_title( $name );
		if ( $slug === '' && $sku !== '' ) {
			$slug = sanitize_title( 'product-' . $sku );
		}
		if ( $slug === '' && $source_url !== '' ) {
			$path = (string) wp_parse_url( $source_url, PHP_URL_PATH );
			$slug = sanitize_title( basename( $path ) );
		}

		$slug = preg_replace( '/-+/', '-', (string) $slug );
		$slug = trim( (string) $slug, '-' );
		if ( strlen( $slug ) > 180 ) {
			$slug = rtrim( substr( $slug, 0, 180 ), '-' );
		}

		return $slug;
	}

	private static function build_product_tag_names( array $payload, string $name, array $attributes_payload, array $category_ids, int $product_id ): array {
		$tags = self::normalize_tag_names( isset( $payload['tags'] ) ? $payload['tags'] : array() );
		if ( $name !== '' ) {
			$tags[] = $name;
		}

		foreach ( $attributes_payload as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}

			if ( ! empty( $attribute['name'] ) ) {
				$tags[] = (string) $attribute['name'];
			}

			$options = isset( $attribute['options'] ) && is_array( $attribute['options'] ) ? $attribute['options'] : array();
			foreach ( $options as $option ) {
				$tags[] = (string) $option;
			}
		}

		foreach ( $category_ids as $category_id ) {
			$term = get_term( (int) $category_id, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$tags[] = (string) $term->name;
			}
		}

		$existing = wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $existing ) && is_array( $existing ) ) {
			$tags = array_merge( $tags, $existing );
		}

		return array_slice( self::normalize_tag_names( $tags ), 0, 12 );
	}

	private static function normalize_tag_names( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[,|]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $tag ) {
			$tag = trim( wp_strip_all_tags( (string) $tag ) );
			if ( $tag === '' || strlen( $tag ) < 2 ) {
				continue;
			}
			if ( strlen( $tag ) > 80 ) {
				$tag = substr( $tag, 0, 80 );
			}
			$out[] = sanitize_text_field( $tag );
		}

		return array_values( array_unique( $out ) );
	}

	private static function get_product_category_path( int $term_id ): string {
		if ( $term_id <= 0 ) {
			return '';
		}
		$crumbs = array();
		$seen   = array();
		$curr   = $term_id;

		while ( $curr > 0 && ! isset( $seen[ $curr ] ) ) {
			$seen[ $curr ] = true;
			$t             = get_term( $curr, 'product_cat' );
			if ( ! $t instanceof WP_Term ) {
				break;
			}
			array_unshift( $crumbs, (string) $t->name );
			$curr = (int) $t->parent;
		}

		return implode( ' > ', $crumbs );
	}

	private static function find_product_id_by_sku_meta( string $sku ): int {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return 0;
		}
		$sku = trim( $sku );
		if ( $sku === '' ) {
			return 0;
		}

		// Find latest non-trash product/variation carrying this SKU.
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE pm.meta_key = '_sku'
				  AND pm.meta_value = %s
				  AND p.post_type IN ('product','product_variation')
				  AND p.post_status NOT IN ('trash','auto-draft')
				ORDER BY p.ID DESC
				LIMIT 1",
				$sku
			)
		);

		return $post_id ? (int) $post_id : 0;
	}

	private static function find_product_id_by_original_sku_meta( string $sku ): int {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb || $sku === '' ) {
			return 0;
		}
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE pm.meta_key = '_awi_original_sku'
				  AND pm.meta_value = %s
				  AND p.post_type IN ('product','product_variation')
				  AND p.post_status NOT IN ('trash','auto-draft')
				ORDER BY p.ID DESC
				LIMIT 1",
				$sku
			)
		);
		return $post_id ? (int) $post_id : 0;
	}

	private static function generate_unique_sku(): string {
		$candidate = '';
		$attempts  = 0;
		do {
			$candidate = 'F' . rand( 0, 9 ) . 'G' . str_pad( (string) rand( 0, 999 ), 3, '0', STR_PAD_LEFT ) . 'K';
			$exists    = (int) wc_get_product_id_by_sku( $candidate ) > 0;
			$attempts++;
		} while ( $exists && $attempts < 30 );
		return $candidate;
	}

	private static function cleanup_stale_sku_lookup_entries( string $sku ): void {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		$sku = trim( $sku );
		if ( $sku === '' ) {
			return;
		}

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table ) );
		if ( $table_exists !== $lookup_table ) {
			return;
		}

		$valid_product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE pm.meta_key = '_sku'
				  AND pm.meta_value = %s
				  AND p.post_type IN ('product','product_variation')
				  AND p.post_status NOT IN ('trash','auto-draft')",
				$sku
			)
		);
		$valid_product_ids = array_map( 'intval', is_array( $valid_product_ids ) ? $valid_product_ids : array() );

		$lookup_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT product_id FROM {$lookup_table} WHERE sku = %s",
				$sku
			)
		);
		$lookup_ids = array_map( 'intval', is_array( $lookup_ids ) ? $lookup_ids : array() );

		foreach ( $lookup_ids as $pid ) {
			if ( ! in_array( $pid, $valid_product_ids, true ) ) {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$lookup_table} WHERE product_id = %d AND sku = %s",
						$pid,
						$sku
					)
				);
			}
		}
	}

	private static function is_duplicate_sku_lookup_error( string $message ): bool {
		$m = strtolower( trim( $message ) );
		if ( $m === '' ) {
			return false;
		}
		return strpos( $m, 'sku' ) !== false
			&& (
				strpos( $m, 'lookup table' ) !== false
				|| strpos( $m, 'already present' ) !== false
				|| strpos( $m, 'already exists' ) !== false
			);
	}

	private static function normalize_video_urls( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $u ) {
			$u = self::normalize_url( (string) $u );
			if ( $u === '' ) {
				continue;
			}
			if ( ! filter_var( $u, FILTER_VALIDATE_URL ) ) {
				continue;
			}
			$l = strtolower( $u );
			if (
				strpos( $l, '.mp4' ) === false &&
				strpos( $l, '.webm' ) === false &&
				strpos( $l, '.mov' ) === false &&
				strpos( $l, '.m3u8' ) === false
			) {
				continue;
			}
			$out[] = esc_url_raw( $u );
		}

		$out = array_values( array_unique( $out ) );
		return array_slice( $out, 0, 6 );
	}

	private static function upsert_product_video_data( int $product_id, array $video_urls, string $video_poster ): void {
		if ( ! $video_urls ) {
			delete_post_meta( $product_id, '_awi_video_urls' );
			delete_post_meta( $product_id, '_awi_video_url' );
			delete_post_meta( $product_id, '_product_video_url' );
			delete_post_meta( $product_id, '_awi_video_poster' );
			return;
		}

		$first_video = (string) $video_urls[0];
		update_post_meta( $product_id, '_awi_video_urls', wp_json_encode( $video_urls ) );
		update_post_meta( $product_id, '_awi_video_url', $first_video );
		// Compatibility key used by several Woo product video plugins.
		update_post_meta( $product_id, '_product_video_url', $first_video );
		if ( $video_poster !== '' ) {
			update_post_meta( $product_id, '_awi_video_poster', esc_url_raw( $video_poster ) );
		}
	}

	private static function sync_templatemela_gallery_video_meta( int $product_id, array $video_urls ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$image_ids = array();
		$main_id   = (int) $product->get_image_id();
		if ( $main_id > 0 ) {
			$image_ids[] = $main_id;
		}
		$gallery_ids = array_map( 'intval', (array) $product->get_gallery_image_ids() );
		if ( $gallery_ids ) {
			$image_ids = array_merge( $image_ids, $gallery_ids );
		}

		$image_ids = array_values( array_unique( array_filter( $image_ids ) ) );
		if ( ! $image_ids ) {
			return;
		}

		// Clear stale values so only one gallery slide acts as the video launcher.
		foreach ( $image_ids as $attachment_id ) {
			delete_post_meta( (int) $attachment_id, '_bt_woo_product_video' );
		}

		if ( empty( $video_urls ) || empty( $video_urls[0] ) ) {
			return;
		}

		update_post_meta( (int) $image_ids[0], '_bt_woo_product_video', esc_url_raw( (string) $video_urls[0] ) );
	}

	// ─── AI description rewriting ────────────────────────────────────────────────

	/**
	 * Strip AI conversational preamble and trailing notes from generated text.
	 * Safety net that runs on every import regardless of AI rewriting setting.
	 */
	/**
	 * Strip AI preamble/notes and convert markdown to HTML.
	 * Targets the exact patterns clients report seeing, plus general AI conversational text.
	 */
	private static function clean_ai_output( string $text ): string {
		$s = trim( $text );
		if ( $s === '' ) {
			return $s;
		}

		// Normalize curly punctuation so cleanup rules catch more AI/provider variations.
		$s = str_replace(
			array( "\xE2\x80\x99", "\xE2\x80\x98", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x93", "\xE2\x80\x94" ),
			array( "'", "'", '"', '"', '-', '-' ),
			$s
		);

		// ══════════════════════════════════════════════════════════════════════════
		// STEP 1 — Remove Alibaba source/branding text
		// ══════════════════════════════════════════════════════════════════════════

		$s = preg_replace( '/^Source:\s*https?:\/\/[^\s]*alibaba\.com[^\n]*\n?/im', '', $s );
		$s = preg_replace( '/[,.]?\s*Find Complete Details about[\s\S]*?(?:on\s+Alibaba\.com[^.]*\.?|\.\s*)/i', '', $s );
		$s = preg_replace( '/[,.]?\s*Find Complete Details about[\s\S]*$/i', '', $s );
		$s = preg_replace( '/[,.]?\s*Suppliers?\s*(?:or\s*Manufacturers?)?[\s\S]*?on\s+Alibaba\.com[^.]*\.?/i', '', $s );
		$s = preg_replace( '/\s*from\s+[\s\S]*?on\s+Alibaba\.com[^.]*\.?/i', '', $s );
		$s = preg_replace( '/\s*from\s+[A-Z][A-Za-z0-9&\'().,\-]*(?:\s+[A-Z][A-Za-z0-9&\'().,\-]*){1,10}\s+(?:company\s+limited|limited|co\.?\s*,?\s*ltd\.?|inc\.?|llc|corp\.?|corporation)\b[.,:]*/u', '', $s );
		$s = preg_replace( '/\s*on\s+Alibaba\.com[^.]*\.?/i', '', $s );
		$s = preg_replace( '/(?:^|[\s>])from\s+[A-Z][A-Za-z0-9&\'().,-]*(?:\s+[A-Z][A-Za-z0-9&\'().,-]*){1,10}\s+(?:company\s+limited|limited|co\.?\s*,?\s*ltd\.?|inc\.?|llc|corp\.?|corporation)\b[.,:]*/u', ' ', $s );
		$s = preg_replace( '/(?:^|[\s>])(?:supplier|manufacturer|seller|factory)\s*[:\-]?\s*[A-Z][A-Za-z0-9&\'().,-]*(?:\s+[A-Z][A-Za-z0-9&\'().,-]*){1,10}\s+(?:company\s+limited|limited|co\.?\s*,?\s*ltd\.?|inc\.?|llc|corp\.?|corporation)\b[.,:]*/u', ' ', $s );
		$s = preg_replace( '/(?:\s*,\s*)+(?:co\.?\s*)?(?:,\s*)?(?:ltd|limited)\.?$/i', '', $s );
		$s = preg_replace( '/(?:\s*,\s*)+(?:inc|corp|corporation|llc)\.?$/i', '', $s );
		$s = preg_replace( '/(?:\s*,\s*){2,}/', ', ', $s );
		$s = trim( (string) $s, " \t\n\r\0\x0B,.;:-" );

		// ══════════════════════════════════════════════════════════════════════════
		// STEP 2 — Strip AI conversational preamble at the TOP
		// Handles every known variation of AI intro text.
		// ══════════════════════════════════════════════════════════════════════════

		// Exact match: "Here's a rewritten product description, aiming for...:"
		// Catches the full opener sentence regardless of what follows the colon.
		$s = preg_replace(
			'/^here\'s\s+a\s+rewritten\s+product\s+description[^\n]*:\s*/im',
			'',
			$s
		);

		// General: "Here's a [anything]:" / "Here is a [anything]:"
		$s = preg_replace(
			'/^here\'s\s+(?:a\s+|an\s+|the\s+)?[^\n]{0,120}:\s*/im',
			'',
			$s
		);
		$s = preg_replace(
			'/^here\s+is\s+(?:a\s+|an\s+|the\s+)?[^\n]{0,120}:\s*/im',
			'',
			$s
		);

		// "Certainly! Here's..." / "Sure! Here is..." / "Of course, here's..."
		$s = preg_replace(
			'/^(?:certainly|sure|of course|absolutely|gladly|great|happy to help)[!,.]?\s+(?:here\'s|here is)[^\n]*:\s*/im',
			'',
			$s
		);

		// "I've rewritten..." / "I have created..." / "Below is..." / "As requested..."
		$s = preg_replace(
			'/^(?:i\'ve|i have)\s+(?:rewritten|created|written|prepared|crafted|generated)[^\n]*:\s*/im',
			'',
			$s
		);
		$s = preg_replace( '/^below\s+is\s+[^\n]*:\s*/im', '', $s );
		$s = preg_replace( '/^as\s+requested[^\n]*:\s*/im',  '', $s );
		$s = preg_replace( '/^following\s+is\s+[^\n]*:\s*/im', '', $s );
		$s = preg_replace( '/^(?:this\s+is|the\s+following\s+is)\s+[^\n]{0,160}:\s*/im', '', $s );
		$s = preg_replace( '/^(?:rewritten|updated|seo-friendly|professional|persuasive)\s+(?:product\s+)?description[^\n]*:\s*/im', '', $s );
		$s = preg_replace( '/^(?:aiming\s+for|written\s+for|crafted\s+for)[^\n]*$/im', '', $s );
		$s = preg_replace( '/^(?:please\s+find|please\s+see)[^\n]*:\s*/im', '', $s );
		$s = preg_replace( '/^(?:note\s*:|disclaimer\s*:)[^\n]*\n?/im', '', $s );
		$s = preg_replace( '/^(?:i\'ve|i have)\s+(?:followed|adhered|incorporated|used)[^\n]*\n?/im', '', $s );
		$s = preg_replace( '/^(?:the\s+)?(?:description|copy|text)\s+(?:below|above|follows)[^\n]*:\s*/im', '', $s );

		// Standalone label lines e.g. "Rewritten description:" / "Product Description:"
		$s = preg_replace( '/^(?:rewritten|updated|revised|new)\s+description\s*:\s*\n/im', '', $s );
		$s = preg_replace( '/^product\s+description\s*:\s*\n/im', '', $s );
		$s = preg_replace( '/^(?:final\s+)?(?:product\s+)?(?:copy|content)\s*:\s*\n/im', '', $s );

		// ══════════════════════════════════════════════════════════════════════════
		// STEP 3 — Strip everything from the Notes/SEO section onwards
		// Catches: "--- Notes on SEO Considerations..."
		// and any trailing commentary block.
		// ══════════════════════════════════════════════════════════════════════════

		// Exact match: "--- Notes on SEO Considerations" (client's exact pattern)
		$s = preg_replace( '/[\s]*---+\s*Notes\s+on\s+SEO[\s\S]*/i', '', $s );

		// General: any "---" or "***" line that contains "note" anywhere on that line
		$s = preg_replace( '/[\s]*---+[^\n]*note[s]?\b[\s\S]*/i', '', $s );
		$s = preg_replace( '/[\s]*\*\*\*+[^\n]*note[s]?\b[\s\S]*/i', '', $s );

		// "Notes on ..." as a standalone paragraph/line
		$s = preg_replace( '/\n+\s*notes?\s+on\s+\w[\s\S]*/i', '', $s );

		// "**Note:**" or "Note:" as paragraph opener
		$s = preg_replace( '/\n+\s*\*{0,2}notes?\*{0,2}\s*:[\s\S]*/i', '', $s );

		// "SEO Considerations:" as a standalone line
		$s = preg_replace( '/\n+\s*(?:\*{0,2})?SEO\s+considerations?\s*:[\s\S]*/i', '', $s );

		// Catch any trailing "- Keywords:" / "- Readability:" bullet section
		$s = preg_replace( '/\n+\s*-\s*Keywords?\s*:[\s\S]*/i', '', $s );
		$s = preg_replace( '/\n+\s*-\s*Readability\s*:[\s\S]*/i', '', $s );
		$s = preg_replace( '/\n+\s*-\s*(?:Benefit|Call\s+to\s+Action)\s*:[\s\S]*/i', '', $s );
		$s = preg_replace( '/\n+\s*(?:Keywords?|Readability|Benefit-Oriented|Call\s+to\s+Action)\s*:[\s\S]*/i', '', $s );

		// Additional trailing commentary patterns
		$s = preg_replace( '/\n+\s*(?:\*{0,2})?(?:important\s+)?(?:note|p\.?\s*s\.?)\s*:[\s\S]*/i', '', $s );
		$s = preg_replace( '/\n+\s*(?:---+|\*{3,})\s*(?:end|fin|done|complete)\b[\s\S]*/i', '', $s );
		$s = preg_replace( '/\n+\s*(?:this\s+description|the\s+description|this\s+copy)\s+(?:has\s+been|is|includes|follows)[\s\S]*/i', '', $s );
		$s = preg_replace( '/\n+\s*I\s+(?:have\s+)?(?:avoided|ensured|focused|incorporated|used|included|tried|maintained|made sure)[\s\S]*/i', '', $s );
		$s = preg_replace( '/\n+\s*(?:the\s+)?(?:title|description|copy)\s+(?:avoids|uses|incorporates|reflects|includes|focuses\s+on)[\s\S]*/i', '', $s );
		$s = preg_replace( '/\n+\s*(?:let\s+me\s+know|feel\s+free\s+to|please\s+(?:let|note|be))[\s\S]*/i', '', $s );

		// ══════════════════════════════════════════════════════════════════════════
		// STEP 4 — Convert markdown → HTML
		// WooCommerce descriptions render HTML, so convert rather than strip.
		// ══════════════════════════════════════════════════════════════════════════

		// Headings (H1 → H2 because product title is already H1 on the page)
		$s = preg_replace( '/^######\s+(.+)$/m', '<h6>$1</h6>', $s );
		$s = preg_replace( '/^#####\s+(.+)$/m',  '<h5>$1</h5>', $s );
		$s = preg_replace( '/^####\s+(.+)$/m',   '<h4>$1</h4>', $s );
		$s = preg_replace( '/^###\s+(.+)$/m',    '<h3>$1</h3>', $s );
		$s = preg_replace( '/^##\s+(.+)$/m',     '<h2>$1</h2>', $s );
		$s = preg_replace( '/^#\s+(.+)$/m',      '<h2>$1</h2>', $s );

		// Bold + italic combined first
		$s = preg_replace( '/\*\*\*([^\*\n]+)\*\*\*/', '<strong><em>$1</em></strong>', $s );
		// Bold
		$s = preg_replace( '/\*\*([^\*\n]+)\*\*/', '<strong>$1</strong>', $s );
		$s = preg_replace( '/__([^_\n]+)__/',      '<strong>$1</strong>', $s );
		// Italic
		$s = preg_replace( '/\*([^\*\n]+)\*/', '<em>$1</em>', $s );
		$s = preg_replace( '/_([^_\n]+)_/',    '<em>$1</em>', $s );

		// Bullet lists → <ul><li>
		$s = preg_replace_callback(
			'/(?:^[ \t]*[-\*]\s+.+\n?)+/m',
			static function ( $matches ) {
				$lines = preg_split( '/\n/', trim( $matches[0] ) );
				$items = '';
				foreach ( $lines as $line ) {
					$line = trim( preg_replace( '/^[ \t]*[-\*]\s+/', '', $line ) );
					if ( $line !== '' ) {
						$items .= '<li>' . $line . '</li>';
					}
				}
				return '<ul>' . $items . '</ul>';
			},
			$s
		);

		// Remove leftover standalone horizontal rules
		$s = preg_replace( '/^\s*[-\*_]{3,}\s*$/m', '', $s );

		// ══════════════════════════════════════════════════════════════════════════
		// STEP 5 — Wrap bare text blocks in <p> tags (only if no HTML yet)
		// ══════════════════════════════════════════════════════════════════════════

		if ( ! preg_match( '/<(?:h[1-6]|ul|ol|li|p|div|blockquote)\b/i', $s ) ) {
			$blocks  = preg_split( '/\n{2,}/', trim( $s ) );
			$wrapped = array();
			foreach ( $blocks as $block ) {
				$block = trim( $block );
				if ( $block !== '' ) {
					$wrapped[] = '<p>' . nl2br( $block ) . '</p>';
				}
			}
			$s = implode( "\n", $wrapped );
		}

		// ══════════════════════════════════════════════════════════════════════════
		// STEP 6 — Strip generic AI filler phrases that slip through prompting
		// These phrases are never product-specific and must never appear in output.
		// ══════════════════════════════════════════════════════════════════════════

		$generic_phrase_patterns = array(
			// Elevation/experience openers
			'/\b(?:elevate\s+your\s+(?:style|brand|look|experience|game|business))\b/i',
			'/\b(?:experience\s+the\s+(?:difference|quality|excellence|power|advantage))\b/i',
			'/\b(?:take\s+your\s+\w+\s+to\s+the\s+next\s+level)\b/i',
			'/\b(?:transform\s+your\s+(?:style|look|brand|business|wardrobe))\b/i',
			// Whether you / look no further
			'/\b(?:whether\s+you\'?re?\s+(?:looking|searching|seeking|running|building))[^.]*[.]/i',
			'/\b(?:look\s+no\s+further)[^.]*[.]/i',
			'/\b(?:your\s+search\s+(?:is\s+over|ends\s+here))[^.]*[.]/i',
			// Generic quality phrases
			'/\b(?:crafted\s+with\s+(?:care|precision|love|passion|dedication))\b/i',
			'/\b(?:made\s+with\s+(?:love|passion|care|attention\s+to\s+detail))\b/i',
			'/\b(?:passion\s+for\s+(?:quality|excellence|perfection))\b/i',
			'/\b(?:attention\s+to\s+(?:detail|quality))\b/i',
			// You deserve / designed for you
			'/\b(?:you\s+deserve\s+the\s+best)[^.]*[.]/i',
			'/\b(?:designed\s+with\s+you\s+in\s+mind)[^.]*[.]/i',
			'/\b(?:tailored\s+to\s+your\s+needs)[^.]*[.]/i',
			'/\b(?:perfect\s+for\s+(?:any\s+occasion|everyone|all\s+occasions|any\s+style))\b/i',
			// Urgency/CTA that should not be in description body
			'/\b(?:don\'t\s+miss\s+out)[^.]*[.]/i',
			'/\b(?:order\s+(?:today|now)\s+(?:and|to|for))[^.]*[.]/i',
		);

		foreach ( $generic_phrase_patterns as $pattern ) {
			$s = (string) preg_replace( $pattern, '', $s );
		}

		// ══════════════════════════════════════════════════════════════════════════
		// STEP 7 — Strip price/origin/brand data that must never appear in copy
		// ══════════════════════════════════════════════════════════════════════════

		// Strip <li> items that contain price tier patterns (e.g. "200-999 pieces: BDT 725.28")
		$s = preg_replace( '/<li>[^<]*\b\d[\d,\s]*(?:pieces?|pcs|units?)\b[^<]*:\s*[A-Z]{0,4}\s*[\d,.]+[^<]*<\/li>/i', '', $s );
		// Strip <li> items that are purely "NNN pieces: NNN" or "NNN+: NNN"
		$s = preg_replace( '/<li>\s*[\d,\s\-\+]+\s*(?:pieces?|pcs|units?)?\s*:\s*[A-Z]{0,4}\s*[\d,.]+\s*<\/li>/i', '', $s );
		// Strip <li> items containing "Place of Origin", "Brand Name", "Model Number", "Country of Origin"
		$s = preg_replace( '/<li>[^<]*(?:place\s+of\s+origin|country\s+of\s+origin|brand\s+name|model\s+(?:number|no)|hs\s+code)[^<]*<\/li>/i', '', $s );
		// Strip those same labels as plain text (e.g. if AI puts them as paragraph text)
		$s = preg_replace( '/\b(?:place\s+of\s+origin|country\s+of\s+origin|brand\s+name\s*:)[^<\n]*(?:\n|<)/i', '', $s );

		// Remove sentences that are entirely empty after stripping (e.g. "<p></p>", "<li></li>")
		$s = preg_replace( '/<(p|li|h[2-6])>\s*<\/\1>/i', '', $s );

		// Final cleanup
		$s = preg_replace( '/[ \t]{2,}/', ' ', $s );
		$s = preg_replace( '/(?:^|\s)[-]{2,}\s*/m', ' ', $s );
		$s = preg_replace( '/\s*\.\s*\./', '.', $s );
		$s = preg_replace( '/\s+,/', ',', $s );
		$s = preg_replace( '/\(\s*\)/', '', $s );
		$s = trim( (string) $s, " \t\n\r\0\x0B,.;:-" );

		return trim( $s );
	}

	private static function clean_ai_plain_text_output( string $text ): string {
		$s = self::clean_ai_output( $text );
		$s = html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES, 'UTF-8' );
		$s = preg_replace( '/\s+/u', ' ', $s );
		$s = trim( (string) $s, " \t\n\r\0\x0B\"'`-–—:;," );
		return $s;
	}

	private static function clean_ai_multiline_plain_text_output( string $text ): string {
		$s = self::clean_ai_output( $text );
		if ( $s === '' ) {
			return '';
		}

		$s = preg_replace( '/<br\s*\/?>/i', "\n", $s );
		$s = preg_replace( '/<li[^>]*>/i', '- ', $s );
		$s = preg_replace( '/<\/(?:p|div|h[1-6]|ul|ol|li|blockquote)>\s*/i', "\n", $s );
		$s = html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES, 'UTF-8' );
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
		$s = preg_replace( '/[ \t]+/u', ' ', $s );
		$s = preg_replace( "/\n{3,}/", "\n\n", $s );
		$s = trim( (string) $s, " \t\n\r\0\x0B\"'`-–—:;," );
		return $s;
	}

	private static function flatten_text_fragments( $value ): array {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				$out = array_merge( $out, self::flatten_text_fragments( $item ) );
			}
			return $out;
		}

		if ( is_scalar( $value ) ) {
			$text = trim( (string) $value );
			return $text !== '' ? array( $text ) : array();
		}

		return array();
	}

	private static function mixed_to_clean_text( $value ): string {
		$parts = self::flatten_text_fragments( $value );
		if ( empty( $parts ) ) {
			return '';
		}

		return self::clean_ai_plain_text_output( implode( ' ', $parts ) );
	}

	private static function mixed_to_clean_text_list( $value ): array {
		$parts = self::flatten_text_fragments( $value );
		if ( empty( $parts ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						array( __CLASS__, 'clean_ai_plain_text_output' ),
						$parts
					)
				)
			)
		);
	}

	private static function get_nested_array_value( $value, array $path ) {
		$current = $value;
		foreach ( $path as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}
			$current = $current[ $segment ];
		}

		return $current;
	}

	private static function normalize_rewrite_attributes( array $attributes ): array {
		$out = array();

		foreach ( $attributes as $attr_name => $attr_values ) {
			$label     = '';
			$raw_value = $attr_values;

			if ( is_array( $attr_values ) && ( isset( $attr_values['name'] ) || isset( $attr_values['label'] ) || isset( $attr_values['attrName'] ) ) ) {
				$label = self::clean_ai_plain_text_output(
					(string) ( $attr_values['name'] ?? $attr_values['label'] ?? $attr_values['attrName'] ?? '' )
				);
				if ( isset( $attr_values['options'] ) ) {
					$raw_value = $attr_values['options'];
				} elseif ( isset( $attr_values['values'] ) ) {
					$raw_value = $attr_values['values'];
				} elseif ( isset( $attr_values['value'] ) ) {
					$raw_value = $attr_values['value'];
				} elseif ( isset( $attr_values['attrValue'] ) ) {
					$raw_value = $attr_values['attrValue'];
				}
			} elseif ( is_string( $attr_name ) ) {
				$label = self::clean_ai_plain_text_output( $attr_name );
			}

			if ( $label === '' ) {
				continue;
			}

			$values = self::mixed_to_clean_text_list( $raw_value );
			if ( empty( $values ) ) {
				continue;
			}

			if ( ! isset( $out[ $label ] ) ) {
				$out[ $label ] = array();
			}

			$out[ $label ] = array_values( array_unique( array_merge( $out[ $label ], $values ) ) );
		}

		return $out;
	}

	private static function merge_rewrite_attributes( array ...$groups ): array {
		$out = array();

		foreach ( $groups as $group ) {
			foreach ( self::normalize_rewrite_attributes( $group ) as $label => $values ) {
				if ( ! isset( $out[ $label ] ) ) {
					$out[ $label ] = array();
				}
				$out[ $label ] = array_values( array_unique( array_merge( $out[ $label ], $values ) ) );
			}
		}

		return $out;
	}

	private static function collect_payload_property_rows( array $payload ): array {
		$detail_data = self::get_nested_array_value( $payload, array( 'raw', 'detailData' ) );
		if ( ! is_array( $detail_data ) ) {
			return array();
		}

		$groups = array(
			self::get_nested_array_value( $detail_data, array( 'globalData', 'product', 'productBasicProperties' ) ),
			self::get_nested_array_value( $detail_data, array( 'globalData', 'product', 'productOtherProperties' ) ),
			self::get_nested_array_value( $detail_data, array( 'globalData', 'product', 'productKeyIndustryProperties' ) ),
		);

		$out  = array();
		$seen = array();

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( $group as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$label = self::clean_ai_plain_text_output( (string) ( $item['attrName'] ?? $item['name'] ?? $item['label'] ?? '' ) );
				$value = self::clean_ai_plain_text_output( (string) ( $item['attrValue'] ?? $item['value'] ?? $item['text'] ?? '' ) );
				if ( $label === '' || $value === '' ) {
					continue;
				}
				if ( preg_match( '/(?:supplier|manufacturer|factory|seller|company)/i', $label ) ) {
					continue;
				}
				// Strip origin, branding, and model identifiers — not product features
				if ( preg_match( '/(?:place\s+of\s+origin|country\s+of\s+origin|origin|brand\s+name|brand|model\s+number|model\s+no|item\s+number|item\s+no|hs\s+code|export\s+type)/i', $label ) ) {
					continue;
				}
				$key = strtolower( $label . '::' . $value );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$out[]        = array(
					'label' => $label,
					'value' => $value,
				);
			}
		}

		return $out;
	}

	private static function property_rows_to_rewrite_attributes( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = self::clean_ai_plain_text_output( (string) ( $row['label'] ?? '' ) );
			$value = self::clean_ai_plain_text_output( (string) ( $row['value'] ?? '' ) );
			if ( $label === '' || $value === '' ) {
				continue;
			}
			if ( ! isset( $out[ $label ] ) ) {
				$out[ $label ] = array();
			}
			$out[ $label ][] = $value;
			$out[ $label ]   = array_values( array_unique( $out[ $label ] ) );
		}

		return $out;
	}

	private static function build_payload_detail_data_context( array $payload ): array {
		$detail_data = self::get_nested_array_value( $payload, array( 'raw', 'detailData' ) );
		if ( ! is_array( $detail_data ) ) {
			return array(
				'text'       => '',
				'attributes' => array(),
			);
		}

		$product_name = self::clean_ai_plain_text_output(
			(string) (
				$payload['name']
				?? self::get_nested_array_value( $detail_data, array( 'globalData', 'product', 'subject' ) )
				?? ''
			)
		);
		$rows        = self::collect_payload_property_rows( $payload );
		$attributes  = self::property_rows_to_rewrite_attributes( $rows );
		$lines       = array();

		if ( $product_name !== '' ) {
			$lines[] = 'Product name: ' . $product_name;
		}

		foreach ( array_slice( $rows, 0, 14 ) as $row ) {
			$lines[] = $row['label'] . ': ' . $row['value'];
		}

		$moq = self::clean_ai_plain_text_output( (string) ( $payload['moq'] ?? '' ) );
		if ( $moq !== '' ) {
			$unit    = self::clean_ai_plain_text_output( (string) ( $payload['min_order_unit'] ?? '' ) );
			$lines[] = 'Minimum order quantity: ' . $moq . ( $unit !== '' ? ' ' . $unit : '' );
		}

		// Price tiers are NOT sent to AI for description — they go in product meta / price field only.
		// Sending them causes AI to list prices in Key Features bullets.

		$lead_time_rows = self::get_nested_array_value( $detail_data, array( 'globalData', 'trade', 'leadTimeInfo', 'ladderPeriodList' ) );
		if ( ! is_array( $lead_time_rows ) ) {
			$lead_time_rows = self::get_nested_array_value( $detail_data, array( 'globalData', 'trade', 'warehouseLeadTimeInfo', 'DEFAULT' ) );
		}
		if ( is_array( $lead_time_rows ) ) {
			$lead_parts = array();
			foreach ( array_slice( $lead_time_rows, 0, 5 ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$min_qty = isset( $row['minQuantity'] ) ? (int) $row['minQuantity'] : ( isset( $row['localMinQuantity'] ) ? (int) $row['localMinQuantity'] : 1 );
				$max_qty = isset( $row['maxQuantity'] ) ? (int) $row['maxQuantity'] : ( isset( $row['localMaxQuantity'] ) ? (int) $row['localMaxQuantity'] : 0 );
				$days    = isset( $row['processPeriod'] ) ? (int) $row['processPeriod'] : 0;
				if ( $days <= 0 ) {
					continue;
				}
				$range        = $max_qty > 0 ? "{$min_qty}-{$max_qty}" : "{$min_qty}+";
				$lead_parts[] = $range . ' pieces: ' . $days . ' days';
			}
			if ( ! empty( $lead_parts ) ) {
				$lines[] = 'Lead time: ' . implode( ' | ', $lead_parts );
			}
		}

		$package_size = self::clean_ai_plain_text_output(
			(string) self::get_nested_array_value( $detail_data, array( 'globalData', 'trade', 'logisticInfo', 'unitSize' ) )
		);
		if ( $package_size !== '' ) {
			$lines[] = 'Package size: ' . $package_size;
		}

		$package_weight = self::clean_ai_plain_text_output(
			(string) self::get_nested_array_value( $detail_data, array( 'globalData', 'trade', 'logisticInfo', 'unitWeight' ) )
		);
		if ( $package_weight !== '' ) {
			$lines[] = 'Package weight: ' . $package_weight;
		}

		$sales_volume = self::clean_ai_plain_text_output(
			(string) self::get_nested_array_value( $detail_data, array( 'globalData', 'trade', 'salesVolume' ) )
		);
		if ( $sales_volume !== '' ) {
			$lines[] = 'Sales volume: ' . $sales_volume;
		}

		$review = self::get_nested_array_value( $detail_data, array( 'globalData', 'review', 'productReview' ) );
		if ( is_array( $review ) ) {
			$average = isset( $review['averageStar'] ) ? trim( (string) $review['averageStar'] ) : '';
			$total   = isset( $review['totalReviewCount'] ) ? (int) $review['totalReviewCount'] : 0;
			if ( $average !== '' || $total > 0 ) {
				$review_line = 'Buyer rating: ';
				if ( $average !== '' ) {
					$review_line .= $average . '/5';
				}
				if ( $total > 0 ) {
					$review_line .= ( $average !== '' ? ' from ' : '' ) . $total . ' reviews';
				}
				$lines[] = $review_line;
			}
		}

		$certs = self::get_nested_array_value( $detail_data, array( 'globalData', 'certification' ) );
		if ( is_array( $certs ) ) {
			$names = array();
			foreach ( $certs as $cert ) {
				if ( ! is_array( $cert ) ) {
					continue;
				}
				$name = self::clean_ai_plain_text_output( (string) ( $cert['certName'] ?? $cert['title'] ?? '' ) );
				if ( $name !== '' ) {
					$names[] = $name;
				}
			}
			$names = array_values( array_unique( $names ) );
			if ( ! empty( $names ) ) {
				$lines[] = 'Certifications: ' . implode( ', ', $names );
			}
		}

		return array(
			'text'       => implode( "\n", array_filter( $lines ) ),
			'attributes' => $attributes,
		);
	}

	private static function extract_payload_rewrite_source_copy( array $payload ): string {
		$candidates = array();
		$context    = self::build_payload_detail_data_context( $payload );

		if ( ! empty( $context['text'] ) ) {
			$candidates[] = $context['text'];
		}

		if ( isset( $payload['description_context'] ) ) {
			$candidates[] = $payload['description_context'];
		}

		if ( ! empty( $payload['raw'] ) && is_array( $payload['raw'] ) ) {
			if ( ! empty( $payload['raw']['extracted_context'] ) && is_array( $payload['raw']['extracted_context'] ) && ! empty( $payload['raw']['extracted_context']['text'] ) ) {
				$candidates[] = $payload['raw']['extracted_context']['text'];
			} elseif ( ! empty( $payload['raw']['extracted_context'] ) && is_string( $payload['raw']['extracted_context'] ) ) {
				$candidates[] = $payload['raw']['extracted_context'];
			}
		}

		if ( isset( $payload['description'] ) ) {
			$candidates[] = $payload['description'];
		}
		if ( isset( $payload['short_description'] ) ) {
			$candidates[] = $payload['short_description'];
		}

		foreach ( $candidates as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}
			$cleaned = self::clean_ai_multiline_plain_text_output( (string) $candidate );
			if ( $cleaned !== '' ) {
				return $cleaned;
			}
		}

		return '';
	}

	private static function normalize_model_name( string $model, string $default ): string {
		$model = trim( sanitize_text_field( $model ) );
		return $model !== '' ? $model : $default;
	}

	private static function extract_plain_sentences( string $text, int $limit = 8 ): array {
		$plain = self::clean_ai_plain_text_output( $text );
		if ( $plain === '' ) {
			return array();
		}

		$parts = preg_split( '/(?<=[.!?])\s+|\s*;\s*|\s+\|\s+/u', $plain );
		if ( ! is_array( $parts ) ) {
			$parts = array( $plain );
		}

		$out = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			$part = trim( $part, " \t\n\r\0\x0B,.;:-" );
			if ( $part === '' ) {
				continue;
			}
			$out[] = $part;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return array_values( array_unique( $out ) );
	}

	private static function description_needs_structured_fallback( string $description ): bool {
		$plain = self::clean_ai_plain_text_output( $description );
		if ( $plain === '' ) {
			return true;
		}

		$plain_length = function_exists( 'mb_strlen' ) ? mb_strlen( $plain ) : strlen( $plain );
		if ( $plain_length < 280 ) {
			return true;
		}

		if ( ! preg_match( '/<h[2-6]\b/i', $description ) || ! preg_match( '/<(?:ul|ol)\b/i', $description ) ) {
			return true;
		}

		if ( preg_match( '/(?:here(?:\'s| is)|notes?\s+on\s+seo|keywords?:|readability:|benefit-oriented|call\s+to\s+action)/i', $plain ) ) {
			return true;
		}

		if ( preg_match( '/\bfrom\s+[A-Z][A-Za-z0-9&\'().,-]*(?:\s+[A-Z][A-Za-z0-9&\'().,-]*){1,10}\s+(?:company\s+limited|limited|co\.?\s*,?\s*ltd\.?|inc\.?|llc|corp\.?|corporation)\b/u', $plain ) ) {
			return true;
		}

		return false;
	}

	private static function short_description_needs_refresh( string $short_description ): bool {
		$plain = self::clean_ai_plain_text_output( $short_description );
		if ( $plain === '' ) {
			return true;
		}

		$plain_length = function_exists( 'mb_strlen' ) ? mb_strlen( $plain ) : strlen( $plain );
		if ( $plain_length < 40 || $plain_length > 320 ) {
			return true;
		}

		if ( preg_match( '/(?:alibaba|find\s+complete\s+details|supplier|manufacturer|notes?\s+on\s+seo|keywords?:|readability:|benefit-oriented)/i', $plain ) ) {
			return true;
		}

		if ( preg_match( '/\bfrom\s+[A-Z][A-Za-z0-9&\'().,-]*(?:\s+[A-Z][A-Za-z0-9&\'().,-]*){1,10}\s+(?:company\s+limited|limited|co\.?\s*,?\s*ltd\.?|inc\.?|llc|corp\.?|corporation)\b/u', $plain ) ) {
			return true;
		}

		return false;
	}

	private static function build_description_intro( string $product_name, array $sentences ): string {
		$headline = self::clean_ai_plain_text_output( $product_name );
		$lead     = array_slice( $sentences, 0, 2 );
		if ( empty( $lead ) ) {
			if ( $headline === '' ) {
				return 'Explore this wholesale-ready product built for dependable everyday use and practical business sourcing.';
			}

			return sprintf(
				'%s is built for buyers who need reliable performance, practical usability, and a cleaner presentation for resale or trade enquiries.',
				$headline
			);
		}

		$intro = implode( ' ', $lead );
		if ( $headline !== '' && stripos( $intro, $headline ) === false ) {
			$intro = $headline . '. ' . $intro;
		}

		return trim( $intro );
	}

	private static function build_description_feature_items( array $sentences, array $attributes, array $variations ): array {
		$items = array();
		foreach ( array_slice( $sentences, 0, 5 ) as $sentence ) {
			$sentence = trim( (string) $sentence );
			if ( $sentence === '' ) {
				continue;
			}
			$items[] = $sentence;
		}

		foreach ( $attributes as $attr_name => $attr_values ) {
			$label = self::clean_ai_plain_text_output( (string) $attr_name );
			if ( $label === '' ) {
				continue;
			}
			$values = self::mixed_to_clean_text_list( $attr_values );
			if ( empty( $values ) ) {
				continue;
			}
			$items[] = sprintf( '%s: %s.', $label, implode( ', ', $values ) );
			if ( count( $items ) >= 5 ) {
				break;
			}
		}

		if ( count( $items ) < 4 && ! empty( $variations ) ) {
			$variation_labels = array();
			foreach ( $variations as $variation ) {
				if ( ! is_array( $variation ) ) {
					continue;
				}
				$parts = array();
				foreach ( $variation as $key => $value ) {
					$value = self::mixed_to_clean_text( $value );
					if ( $value === '' ) {
						continue;
					}
					$parts[] = self::clean_ai_plain_text_output( (string) $key ) . ': ' . $value;
				}
				if ( ! empty( $parts ) ) {
					$variation_labels[] = implode( ', ', $parts );
				}
				if ( count( $variation_labels ) >= 3 ) {
					break;
				}
			}
			if ( ! empty( $variation_labels ) ) {
				$items[] = 'Available options: ' . implode( ' | ', $variation_labels ) . '.';
			}
		}

		return array_slice( array_values( array_unique( array_filter( $items ) ) ), 0, 5 );
	}

	private static function build_description_spec_items( string $product_name, array $attributes, array $variations ): array {
		$items = array();
		$title = self::clean_ai_plain_text_output( $product_name );
		if ( $title !== '' ) {
			$items[] = 'Product: ' . $title;
		}

		foreach ( $attributes as $attr_name => $attr_values ) {
			$label = self::clean_ai_plain_text_output( (string) $attr_name );
			if ( $label === '' ) {
				continue;
			}
			$values = self::mixed_to_clean_text_list( $attr_values );
			if ( empty( $values ) ) {
				continue;
			}
			$items[] = $label . ': ' . implode( ', ', $values );
			if ( count( $items ) >= 6 ) {
				break;
			}
		}

		if ( count( $items ) < 4 && ! empty( $variations ) ) {
			$variation_summaries = array();
			foreach ( $variations as $variation ) {
				if ( ! is_array( $variation ) ) {
					continue;
				}
				$parts = array();
				foreach ( $variation as $key => $value ) {
					$value = self::mixed_to_clean_text( $value );
					if ( $value === '' ) {
						continue;
					}
					$parts[] = self::clean_ai_plain_text_output( (string) $key ) . ': ' . $value;
				}
				if ( ! empty( $parts ) ) {
					$variation_summaries[] = implode( ', ', $parts );
				}
				if ( count( $variation_summaries ) >= 3 ) {
					break;
				}
			}
			if ( ! empty( $variation_summaries ) ) {
				$items[] = 'Variation options: ' . implode( ' | ', $variation_summaries );
			}
		}

		if ( count( $items ) < 3 ) {
			$items[] = 'Use Case: Wholesale sourcing, resale, and business enquiries';
		}

		return array_slice( array_values( array_unique( array_filter( $items ) ) ), 0, 6 );
	}

	private static function build_structured_description_fallback( string $product_name, string $source_text, array $attributes = array(), array $variations = array() ): string {
		$title     = self::clean_ai_plain_text_output( $product_name );
		$sentences = self::extract_plain_sentences( $source_text, 8 );
		$headline  = $title !== '' ? $title : ( ! empty( $sentences[0] ) ? $sentences[0] : 'Wholesale Product Overview' );
		$intro     = self::build_description_intro( $title, $sentences );
		$features  = self::build_description_feature_items( array_slice( $sentences, 1 ), $attributes, $variations );
		$specs     = self::build_description_spec_items( $title, $attributes, $variations );

		if ( empty( $features ) ) {
			$features = array(
				'Designed for practical wholesale sourcing and repeat orders.',
				'Built to support dependable daily use and straightforward product merchandising.',
				'Suitable for buyers looking for cleaner presentation and clear resale positioning.',
			);
		}

		$html  = '<h2>' . esc_html( $headline ) . '</h2>';
		$html .= '<p>' . esc_html( $intro ) . '</p>';
		$html .= '<h3>Key Features</h3><ul>';
		foreach ( $features as $feature ) {
			$html .= '<li>' . esc_html( $feature ) . '</li>';
		}
		$html .= '</ul>';
		$html .= '<h3>Product Specifications</h3><ul>';
		foreach ( $specs as $spec ) {
			$html .= '<li>' . esc_html( $spec ) . '</li>';
		}
		$html .= '</ul>';
		$html .= '<h3>Why Choose This Product</h3>';
		$html .= '<p>' . esc_html( 'This listing is positioned for wholesale buyers who need a cleaner product presentation, practical value, and straightforward ordering support for ongoing supply or resale needs.' ) . '</p>';

		return $html;
	}

	private static function append_description_cta_section( string $description, string $cta_url ): string {
		$description = trim( $description );
		$cta_url     = trim( $cta_url );
		if ( $description === '' || $cta_url === '' ) {
			return $description;
		}

		$cta_block =
			'<h3>Need More Information?</h3>' .
			'<p>Contact us for more information, bulk pricing, and order support. ' .
			'<a href="' . esc_url( $cta_url ) . '" target="_blank" rel="noopener"><strong>Contact us today</strong></a>.</p>';

		if ( stripos( $description, 'Need More Information?' ) !== false || stripos( $description, 'Contact us today' ) !== false ) {
			return $description;
		}

		return $description . $cta_block;
	}

	private static function decode_ai_json_object( string $text ): array {
		$candidate = trim( $text );
		if ( $candidate === '' ) {
			return array();
		}

		$candidate = preg_replace( '/^```(?:json)?\s*/i', '', $candidate );
		$candidate = preg_replace( '/\s*```$/', '', $candidate );

		$data = json_decode( $candidate, true );
		if ( is_array( $data ) ) {
			return $data;
		}

		$start = strpos( $candidate, '{' );
		$end   = strrpos( $candidate, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return array();
		}

		$data = json_decode( substr( $candidate, $start, $end - $start + 1 ), true );
		return is_array( $data ) ? $data : array();
	}

	private static function derive_short_description_from_html( string $description ): string {
		$plain = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $description ) ) );
		if ( $plain === '' ) {
			return '';
		}

		$limit = 260;
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) && function_exists( 'mb_strrpos' ) ) {
			if ( mb_strlen( $plain ) > $limit ) {
				$truncated = mb_substr( $plain, 0, $limit );
				$space_pos = mb_strrpos( $truncated, ' ' );
				if ( false !== $space_pos ) {
					$truncated = mb_substr( $truncated, 0, $space_pos );
				}
				$plain = rtrim( $truncated, " \t\n\r\0\x0B.,;:" ) . '...';
			}
		} elseif ( strlen( $plain ) > $limit ) {
			$truncated = substr( $plain, 0, $limit );
			$space_pos = strrpos( $truncated, ' ' );
			if ( false !== $space_pos ) {
				$truncated = substr( $truncated, 0, $space_pos );
			}
			$plain = rtrim( $truncated, " \t\n\r\0\x0B.,;:" ) . '...';
		}

		return '<p>' . esc_html( $plain ) . '</p>';
	}

	private static function get_ai_settings(): array {
		$settings = get_option( 'awi_ai_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$openai_key = isset( $settings['openai_api_key'] ) ? trim( (string) $settings['openai_api_key'] ) : '';
		if ( $openai_key === '' && isset( $settings['api_key'] ) ) {
			$openai_key = trim( (string) $settings['api_key'] );
		}

		$gemini_key = isset( $settings['gemini_api_key'] ) ? trim( (string) $settings['gemini_api_key'] ) : '';
		$order      = isset( $settings['provider_order'] ) ? (string) $settings['provider_order'] : 'openai_first';
		if ( ! in_array( $order, array( 'openai_first', 'gemini_first' ), true ) ) {
			$order = 'openai_first';
		}

		return array(
			'enabled'         => ! empty( $settings['enabled'] ),
			'openai_api_key'  => $openai_key,
			'gemini_api_key'  => $gemini_key,
			'openai_model'    => self::normalize_model_name( isset( $settings['openai_model'] ) ? (string) $settings['openai_model'] : '', 'gpt-4o' ),
			'gemini_model'    => self::normalize_model_name( isset( $settings['gemini_model'] ) ? (string) $settings['gemini_model'] : '', 'gemini-2.5-flash' ),
			'provider_order'  => $order,
			'cta_url'         => isset( $settings['cta_url'] ) ? trim( (string) $settings['cta_url'] ) : '',
			'keywords'        => isset( $settings['keywords'] ) ? trim( (string) $settings['keywords'] ) : '',
		);
	}

	private static function get_ai_provider_sequence( array $settings ): array {
		$order = ( isset( $settings['provider_order'] ) && 'gemini_first' === $settings['provider_order'] )
			? array( 'gemini', 'openai' )
			: array( 'openai', 'gemini' );

		$providers = array();
		foreach ( $order as $provider ) {
			$key = self::get_ai_provider_key( $settings, $provider );
			if ( $key !== '' ) {
				$providers[] = $provider;
			}
		}

		return array_values( array_unique( $providers ) );
	}

	private static function get_ai_provider_key( array $settings, string $provider ): string {
		if ( 'gemini' === $provider ) {
			return isset( $settings['gemini_api_key'] ) ? trim( (string) $settings['gemini_api_key'] ) : '';
		}

		return isset( $settings['openai_api_key'] ) ? trim( (string) $settings['openai_api_key'] ) : '';
	}

	private static function request_ai_completion( array $settings, string $system_prompt, string $user_content, array $args = array() ): array {
		if ( empty( $settings['enabled'] ) ) {
			return array(
				'ok'       => false,
				'provider' => '',
				'content'  => '',
			);
		}

		$providers = self::get_ai_provider_sequence( $settings );
		if ( empty( $providers ) ) {
			return array(
				'ok'       => false,
				'provider' => '',
				'content'  => '',
			);
		}

		foreach ( $providers as $provider ) {
			$result = self::request_ai_completion_from_provider( $provider, $settings, $system_prompt, $user_content, $args );
			if ( ! empty( $result['ok'] ) && ! empty( $result['content'] ) ) {
				$call_type = isset( $args['_call_type'] ) ? (string) $args['_call_type'] : 'unknown';
				self::push_usage(
					isset( $result['model'] )         ? (string) $result['model']         : $provider,
					isset( $result['provider'] )      ? (string) $result['provider']      : $provider,
					$call_type,
					isset( $result['input_tokens'] )  ? (int) $result['input_tokens']     : 0,
					isset( $result['output_tokens'] ) ? (int) $result['output_tokens']    : 0
				);
				return $result;
			}
		}

		return array(
			'ok'       => false,
			'provider' => '',
			'content'  => '',
		);
	}

	private static function request_ai_completion_from_provider( string $provider, array $settings, string $system_prompt, string $user_content, array $args = array() ): array {
		if ( 'gemini' === $provider ) {
			return self::request_gemini_completion( $settings, $system_prompt, $user_content, $args );
		}

		return self::request_openai_completion( $settings, $system_prompt, $user_content, $args );
	}

	public static function test_ai_provider_connection( array $settings, string $provider ): array {
		$provider = strtolower( trim( $provider ) );
		if ( ! in_array( $provider, array( 'openai', 'gemini' ), true ) ) {
			return array(
				'ok'       => false,
				'provider' => $provider,
				'model'    => '',
				'message'  => 'Unknown AI provider.',
			);
		}

		$settings['enabled'] = true;
		if ( 'openai' === $provider && empty( $settings['openai_api_key'] ) && ! empty( $settings['api_key'] ) ) {
			$settings['openai_api_key'] = (string) $settings['api_key'];
		}

		$model = 'openai' === $provider
			? self::normalize_model_name( isset( $settings['openai_model'] ) ? (string) $settings['openai_model'] : '', 'gpt-4o' )
			: self::normalize_model_name( isset( $settings['gemini_model'] ) ? (string) $settings['gemini_model'] : '', 'gemini-2.5-flash' );

		if ( self::get_ai_provider_key( $settings, $provider ) === '' ) {
			return array(
				'ok'       => false,
				'provider' => $provider,
				'model'    => $model,
				'message'  => ucfirst( $provider ) . ' API key is missing.',
			);
		}

		$result = self::request_ai_completion_from_provider(
			$provider,
			$settings,
			'You are validating an API connection for a WooCommerce importer. Reply with exactly OK.',
			'Connection test',
			array(
				'temperature' => 0,
				'max_tokens'  => 12,
			)
		);

		if ( ! empty( $result['ok'] ) ) {
			return array(
				'ok'       => true,
				'provider' => $provider,
				'model'    => $model,
				'message'  => ucfirst( $provider ) . ' connection succeeded using model ' . $model . '.',
			);
		}

		$error_message = isset( $result['error'] ) ? trim( (string) $result['error'] ) : '';
		if ( $error_message === '' ) {
			$error_message = ucfirst( $provider ) . ' did not return a valid response.';
		}

		return array(
			'ok'       => false,
			'provider' => $provider,
			'model'    => $model,
			'message'  => $error_message,
		);
	}

	private static function get_remote_error_message( $response, string $fallback ): string {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		$message = '';
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				$message = $data['error']['message'];
			} elseif ( isset( $data['error']['details'][0]['message'] ) && is_string( $data['error']['details'][0]['message'] ) ) {
				$message = $data['error']['details'][0]['message'];
			} elseif ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				$message = $data['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$message = $data['error'];
			}
		}

		$message = trim( $message );
		if ( $message !== '' ) {
			return $status > 0 ? $message . ' (HTTP ' . $status . ')' : $message;
		}

		if ( $status > 0 ) {
			return $fallback . ' (HTTP ' . $status . ')';
		}

		return $fallback;
	}

	private static function request_openai_completion( array $settings, string $system_prompt, string $user_content, array $args = array() ): array {
		$api_key = self::get_ai_provider_key( $settings, 'openai' );
		if ( $api_key === '' ) {
			return array(
				'ok'       => false,
				'provider' => 'openai',
				'content'  => '',
				'error'    => 'OpenAI API key is missing.',
			);
		}

		$body = array(
			'model'       => self::normalize_model_name( isset( $settings['openai_model'] ) ? (string) $settings['openai_model'] : '', 'gpt-4o' ),
			'messages'    => array(
				array( 'role' => 'system', 'content' => $system_prompt ),
				array( 'role' => 'user', 'content' => $user_content ),
			),
			'temperature' => isset( $args['temperature'] ) ? (float) $args['temperature'] : 0.3,
			'max_tokens'  => isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : 1000,
		);

		if ( ! empty( $args['json_mode'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body' => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array(
				'ok'       => false,
				'provider' => 'openai',
				'content'  => '',
				'error'    => self::get_remote_error_message( $response, 'OpenAI request failed.' ),
			);
		}

		$data         = json_decode( wp_remote_retrieve_body( $response ), true );
		$out          = isset( $data['choices'][0]['message']['content'] ) ? (string) $data['choices'][0]['message']['content'] : '';
		$input_tok    = isset( $data['usage']['prompt_tokens'] )     ? (int) $data['usage']['prompt_tokens']     : 0;
		$output_tok   = isset( $data['usage']['completion_tokens'] ) ? (int) $data['usage']['completion_tokens'] : 0;
		$model_used   = isset( $data['model'] ) ? (string) $data['model'] : $body['model'];

		return array(
			'ok'            => $out !== '',
			'provider'      => 'openai',
			'content'       => $out,
			'error'         => $out !== '' ? '' : 'OpenAI returned an empty response.',
			'input_tokens'  => $input_tok,
			'output_tokens' => $output_tok,
			'model'         => $model_used,
		);
	}

	private static function request_gemini_completion( array $settings, string $system_prompt, string $user_content, array $args = array() ): array {
		$api_key = self::get_ai_provider_key( $settings, 'gemini' );
		if ( $api_key === '' ) {
			return array(
				'ok'       => false,
				'provider' => 'gemini',
				'content'  => '',
				'error'    => 'Gemini API key is missing.',
			);
		}

		$generation_config = array(
			'temperature'     => isset( $args['temperature'] ) ? (float) $args['temperature'] : 0.3,
			'maxOutputTokens' => isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : 1000,
		);

		if ( ! empty( $args['json_mode'] ) ) {
			$generation_config['responseMimeType'] = 'application/json';
		}

		$body = array(
			'systemInstruction' => array(
				'parts' => array(
					array(
						'text' => $system_prompt,
					),
				),
			),
			'contents' => array(
				array(
					'parts' => array(
						array(
							'text' => $user_content,
						),
					),
				),
			),
			'generationConfig' => $generation_config,
		);

		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( self::normalize_model_name( isset( $settings['gemini_model'] ) ? (string) $settings['gemini_model'] : '', 'gemini-2.5-flash' ) ) . ':generateContent',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'x-goog-api-key'=> $api_key,
				),
				'body' => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array(
				'ok'       => false,
				'provider' => 'gemini',
				'content'  => '',
				'error'    => self::get_remote_error_message( $response, 'Gemini request failed.' ),
			);
		}

		$data       = json_decode( wp_remote_retrieve_body( $response ), true );
		$text       = self::extract_gemini_text_from_response( $data );
		$input_tok  = isset( $data['usageMetadata']['promptTokenCount'] )     ? (int) $data['usageMetadata']['promptTokenCount']     : 0;
		$output_tok = isset( $data['usageMetadata']['candidatesTokenCount'] ) ? (int) $data['usageMetadata']['candidatesTokenCount'] : 0;
		$model_used = self::normalize_model_name( isset( $settings['gemini_model'] ) ? (string) $settings['gemini_model'] : '', 'gemini-2.5-flash' );

		return array(
			'ok'            => $text !== '',
			'provider'      => 'gemini',
			'content'       => $text,
			'error'         => $text !== '' ? '' : 'Gemini returned an empty response.',
			'input_tokens'  => $input_tok,
			'output_tokens' => $output_tok,
			'model'         => $model_used,
		);
	}

	private static function extract_gemini_text_from_response( $data ): string {
		if ( ! is_array( $data ) || empty( $data['candidates'][0]['content']['parts'] ) || ! is_array( $data['candidates'][0]['content']['parts'] ) ) {
			return '';
		}

		$parts = array();
		foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
			if ( isset( $part['text'] ) && is_string( $part['text'] ) && $part['text'] !== '' ) {
				$parts[] = $part['text'];
			}
		}

		return trim( implode( "\n", $parts ) );
	}

	private static function prepare_rewritten_import_copy( array $payload, array $attributes = array(), array $variations = array() ): array {
		$name               = isset( $payload['name'] ) ? self::mixed_to_clean_text( $payload['name'] ) : '';
		$detail_context     = self::build_payload_detail_data_context( $payload );
		$normalized_attrs   = self::merge_rewrite_attributes( $attributes, isset( $detail_context['attributes'] ) && is_array( $detail_context['attributes'] ) ? $detail_context['attributes'] : array() );
		$description_source = self::extract_payload_rewrite_source_copy( $payload );
		$description        = $description_source;
		$short              = isset( $payload['short_description'] ) ? self::clean_ai_output( self::mixed_to_clean_text( $payload['short_description'] ) ) : '';

		if ( $description !== '' ) {
			$description = self::rewrite_description_with_ai( $description_source, $name, $normalized_attrs, $variations );
		}

		$title_short = self::rewrite_title_and_short_with_ai(
			$name,
			$short,
			$description_source !== '' ? $description_source : ( $description !== '' ? $description : $short ),
			$normalized_attrs,
			$variations
		);

		$rewritten_name = isset( $title_short['name'] ) ? trim( (string) $title_short['name'] ) : '';
		if ( $rewritten_name === '' ) {
			$rewritten_name = $name;
		}

		$rewritten_short = isset( $title_short['short_description'] ) ? self::clean_ai_output( (string) $title_short['short_description'] ) : '';
		if ( $rewritten_short === '' || self::short_description_needs_refresh( $rewritten_short ) ) {
			$rewritten_short = $short;
		}
		if ( $rewritten_short === '' || self::short_description_needs_refresh( $rewritten_short ) ) {
			$rewritten_short = '';
		}
		if ( $rewritten_short === '' && $description_source !== '' ) {
			$rewritten_short = self::derive_short_description_from_html( $description_source );
		}

		return array(
			'name'              => $rewritten_name,
			'description'       => $description,
			'short_description' => $rewritten_short,
		);
	}

	private static function rewrite_title_and_short_with_ai( string $product_name, string $short_description, string $source_copy, array $attributes = array(), array $variations = array() ): array {
		$settings = self::get_ai_settings();
		$base    = array(
			'name'              => self::clean_ai_plain_text_output( $product_name ),
			'short_description' => $short_description,
		);

		if ( empty( $settings['enabled'] ) || empty( self::get_ai_provider_sequence( $settings ) ) ) {
			return $base;
		}

		$keywords = isset( $settings['keywords'] ) ? trim( (string) $settings['keywords'] ) : '';
		$context  = '';

		if ( ! empty( $attributes ) ) {
			$attr_parts = array();
			foreach ( $attributes as $attr_name => $attr_values ) {
				$values = self::mixed_to_clean_text_list( $attr_values );
				if ( ! empty( $values ) ) {
					$attr_parts[] = $attr_name . ': ' . implode( ', ', $values );
				}
			}
			if ( ! empty( $attr_parts ) ) {
				$context .= "Product attributes:\n" . implode( "\n", $attr_parts ) . "\n\n";
			}
		}

		if ( ! empty( $variations ) ) {
			$variation_lines = array();
			foreach ( $variations as $variation ) {
				if ( ! is_array( $variation ) ) {
					continue;
				}
				$parts = array();
				foreach ( $variation as $k => $val ) {
					if ( is_scalar( $val ) && $val !== '' ) {
						$parts[] = $k . '=' . $val;
					}
				}
				if ( ! empty( $parts ) ) {
					$variation_lines[] = implode( ', ', $parts );
				}
			}
			if ( ! empty( $variation_lines ) ) {
				$context .= "Available variations:\n" . implode( "\n", $variation_lines ) . "\n\n";
			}
		}

		$keyword_instruction = '';
		if ( $keywords !== '' ) {
			$keyword_instruction = 'Use these keywords naturally where they fit: ' . $keywords . "\n";
		}

		$system_prompt =
			'B2B wholesale product writer. Return JSON: {"title":"...","short_description":"..."}. First char must be {.' . "\n" .
			'"title": title case, facts only, no hype, no emojis.' . "\n" .
			'"short_description": one <p> tag, 2-3 sentences: what it is, key spec, who orders wholesale.' . "\n" .
			$keyword_instruction .
			'No invented specs. No supplier/country/platform names. No text outside JSON.';

		$user_content  = '';
		$user_content .= $product_name !== '' ? "Title:\n{$product_name}\n\n" : '';
		$user_content .= $context;
		// Cap source copy at 1500 chars to limit input tokens.
		$stripped_copy = $source_copy !== '' ? wp_strip_all_tags( $source_copy ) : '';
		if ( strlen( $stripped_copy ) > 1500 ) {
			$stripped_copy = substr( $stripped_copy, 0, 1500 );
		}
		$user_content .= $stripped_copy !== '' ? "Product details:\n{$stripped_copy}" : '';

		$result = self::request_ai_completion(
			$settings,
			$system_prompt,
			$user_content,
			array(
				'json_mode'   => true,
				'max_tokens'  => 220,
				'temperature' => 0.3,
				'_call_type'  => 'title_short',
			)
		);
		$out = isset( $result['content'] ) ? (string) $result['content'] : '';
		if ( $out === '' ) {
			return $base;
		}

		$decoded = self::decode_ai_json_object( $out );
		if ( ! $decoded ) {
			return $base;
		}

		$title = isset( $decoded['title'] ) ? self::clean_ai_plain_text_output( (string) $decoded['title'] ) : '';
		$short = isset( $decoded['short_description'] ) ? self::clean_ai_output( (string) $decoded['short_description'] ) : '';

		return array(
			'name'              => $title !== '' ? $title : $base['name'],
			'short_description' => $short !== '' ? $short : $base['short_description'],
		);
	}

	/**
	 * Rewrite a product description with the configured AI provider order.
	 * Returns the original description unchanged if AI is disabled, not configured, or fails.
	 */
	private static function rewrite_description_with_ai( string $description, string $product_name, array $attributes = array(), array $variations = array() ): string {
		$settings = self::get_ai_settings();

		if ( strlen( $description ) < 20 ) {
			return self::append_description_cta_section(
				self::build_structured_description_fallback( $product_name, $description, $attributes, $variations ),
				isset( $settings['cta_url'] ) ? (string) $settings['cta_url'] : ''
			);
		}

		if ( empty( $settings['enabled'] ) || empty( self::get_ai_provider_sequence( $settings ) ) ) {
			return self::append_description_cta_section(
				self::build_structured_description_fallback( $product_name, $description, $attributes, $variations ),
				isset( $settings['cta_url'] ) ? (string) $settings['cta_url'] : ''
			);
		}

		$cta_url  = isset( $settings['cta_url'] ) ? trim( (string) $settings['cta_url'] ) : '';
		$keywords = isset( $settings['keywords'] ) ? trim( (string) $settings['keywords'] ) : '';

		// Build keyword instruction if the admin has configured keywords.
		$keyword_instruction = '';
		if ( $keywords !== '' ) {
			$keyword_instruction =
				'REQUIRED KEYWORDS — you MUST weave these naturally into the copy (do not force them awkwardly; fit them where they read naturally):' . "\n" .
				$keywords . "\n\n";
		}

		$system_prompt =
			'B2B wholesale product writer. Facts only — never invent specs.' . "\n\n" .
			'OUTPUT: HTML only. Start with <h2>. No preamble, no trailing text, no markdown.' . "\n\n" .
			'STRUCTURE:' . "\n" .
			'<h2>[5-8 word title, main keyword first]</h2>' . "\n" .
			'<p>[2-3 sentences: what it IS, who buys it wholesale, key spec from data]</p>' . "\n" .
			'<h3>Key Features</h3><ul>[5-7 <li> items — physical features only, from provided data]</ul>' . "\n" .
			'<h3>Product Specifications</h3><ul>[<li>Label: value — only specs in the data]</ul>' . "\n" .
			'<h3>Why Order Wholesale</h3><p>[2-3 sentences: MOQ, customisation, lead time if provided]</p>' . "\n\n" .
			$keyword_instruction .
			'NEVER write: "high quality", "premium", "elevate", "state of the art", "innovative", "perfect for any", "look no further", "experience the difference", prices/BDT/USD in Key Features, supplier/country/platform names, CTA (added automatically).';

		// Build attribute/variation context for the AI.
		$variation_lines = '';
		if ( ! empty( $attributes ) ) {
			$attr_parts = array();
			foreach ( $attributes as $attr_name => $attr_values ) {
				$values = self::mixed_to_clean_text_list( $attr_values );
				if ( ! empty( $values ) ) {
					$attr_parts[] = $attr_name . ': ' . implode( ', ', $values );
				}
			}
			if ( ! empty( $attr_parts ) ) {
				$variation_lines .= "Product attributes:\n" . implode( "\n", $attr_parts ) . "\n\n";
			}
		}
		if ( ! empty( $variations ) ) {
			$var_parts = array();
			foreach ( $variations as $v ) {
				if ( is_array( $v ) ) {
					$line = array();
					foreach ( $v as $k => $val ) {
						if ( is_string( $val ) && $val !== '' ) {
							$line[] = $k . '=' . $val;
						}
					}
					if ( ! empty( $line ) ) {
						$var_parts[] = implode( ', ', $line );
					}
				}
			}
			if ( ! empty( $var_parts ) ) {
				$variation_lines .= "Available variations:\n" . implode( "\n", $var_parts ) . "\n\n";
			}
		}

		$user_content = '';
		if ( $product_name !== '' ) {
			$user_content .= "Product name: {$product_name}\n\n";
		}
		if ( $variation_lines !== '' ) {
			$user_content .= $variation_lines;
		}
		$user_content .= "Product details:\n{$description}";

		// Truncate long source text to reduce input tokens.
		if ( strlen( $user_content ) > 4000 ) {
			$user_content = substr( $user_content, 0, 4000 );
		}

		$result = self::request_ai_completion(
			$settings,
			$system_prompt,
			$user_content,
			array(
				'max_tokens'  => 1400,
				'temperature' => 0.4,
				'_call_type'  => 'description',
			)
		);
		$out = isset( $result['content'] ) ? (string) $result['content'] : '';

		if ( $out === '' ) {
			return self::append_description_cta_section(
				self::build_structured_description_fallback( $product_name, $description, $attributes, $variations ),
				$cta_url
			);
		}

		$cleaned = self::clean_ai_output( $out );

		if ( self::description_needs_structured_fallback( $cleaned ) ) {
			$cleaned = self::build_structured_description_fallback( $product_name, $description, $attributes, $variations );
		}

		return self::append_description_cta_section( $cleaned, $cta_url );
	}

	// ─────────────────────────────────────────────────────────────────────────────

	private static function remove_awi_video_embed_from_description( int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$desc = (string) $product->get_description();
		if ( strpos( $desc, 'data-awi-video="1"' ) === false ) {
			return;
		}

		$desc = preg_replace(
			'#<p>\s*<strong>\s*Product Video\s*</strong>\s*</p>\s*#is',
			'',
			$desc
		);
		$desc = preg_replace(
			'#<p>\s*<video[^>]*data-awi-video="1"[^>]*>.*?</video>\s*</p>#is',
			'',
			$desc
		);
		$desc = preg_replace(
			'#<video[^>]*data-awi-video="1"[^>]*>.*?</video>#is',
			'',
			$desc
		);
		$desc = trim( (string) $desc );

		$product->set_description( $desc );
		$product->save();
	}
}
