<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AWI_Admin {
	private static $hook_suffix = '';
	private static $legacy_hook_suffix = '';
	private static $url_import_hook_suffix = '';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_app_passwords_notice' ) );
		add_action( 'admin_post_awi_enable_app_passwords', array( __CLASS__, 'handle_enable_app_passwords' ) );
	}

	public static function maybe_show_app_passwords_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! function_exists( 'get_main_network_id' ) || ! function_exists( 'get_network_option' ) ) {
			return;
		}
		$in_use = (bool) get_network_option( get_main_network_id(), 'using_application_passwords' );
		if ( $in_use ) {
			return;
		}
		$action_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=awi_enable_app_passwords' ),
			'awi_enable_app_passwords'
		);
		?>
		<div class="notice notice-warning">
			<p>
				<strong>Alibaba to WooCommerce:</strong>
				<?php esc_html_e( 'Application Passwords are not yet enabled on this site. The Chrome extension will not be able to authenticate until they are enabled.', 'awi' ); ?>
				<a href="<?php echo esc_url( $action_url ); ?>" class="button button-secondary" style="margin-left:8px;">
					<?php esc_html_e( 'Enable Application Passwords', 'awi' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	public static function handle_enable_app_passwords(): void {
		check_admin_referer( 'awi_enable_app_passwords' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'awi' ) );
		}
		if ( function_exists( 'get_main_network_id' ) && function_exists( 'update_network_option' ) ) {
			update_network_option( get_main_network_id(), 'using_application_passwords', 1 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=atw&awi_app_pw_enabled=1' ) );
		exit;
	}

	public static function admin_menu(): void {
		$cap = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';

		self::$hook_suffix = (string) add_menu_page(
			'ATW',
			'ATW',
			$cap,
			'atw',
			array( __CLASS__, 'render_page' ),
			'dashicons-store',
			56
		);

		self::$url_import_hook_suffix = (string) add_submenu_page(
			'atw',
			'Url Import',
			'URL IMPORT',
			$cap,
			'atw-url-import',
			array( __CLASS__, 'render_url_import_page' )
		);

		self::$legacy_hook_suffix = (string) add_submenu_page(
			null,
			'Alibaba Import',
			'Alibaba Import',
			$cap,
			'awi-alibaba-import',
			array( __CLASS__, 'render_legacy_redirect' )
		);

		add_submenu_page(
			'atw',
			'ATW Usage',
			'USAGE',
			$cap,
			'atw-usage',
			array( __CLASS__, 'render_usage_page' )
		);
	}

	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( self::$hook_suffix, self::$legacy_hook_suffix, self::$url_import_hook_suffix ), true ) ) {
			return;
		}

		wp_register_style( 'awi_admin', false );
		wp_enqueue_style( 'awi_admin' );
		wp_add_inline_style( 'awi_admin', self::get_common_admin_css() );

		if ( $hook_suffix === self::$url_import_hook_suffix ) {
			wp_enqueue_script(
				'awi_url_import_admin',
				plugin_dir_url( AWI_PLUGIN_FILE ) . 'assets/url-import-admin.js',
				array(),
				AWI_VERSION,
				true
			);

			$user = wp_get_current_user();
			wp_localize_script(
				'awi_url_import_admin',
				'awiUrlImportData',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'nonce'        => AWI_Url_Import::get_ajax_nonce(),
					'restNonce'    => wp_create_nonce( 'wp_rest' ),
					'connectUrl'   => rest_url( 'awi/v1/connect' ),
					'categories'   => AWI_Url_Import::get_categories(),
					'latestRun'    => AWI_Url_Import::get_latest_run(),
					'recentRuns'   => AWI_Url_Import::get_recent_runs( 8, false ),
					'siteBaseUrl'  => home_url( '/' ),
					'currentUser'  => $user instanceof WP_User ? (string) $user->user_login : '',
					'settingsUrl'  => admin_url( 'admin.php?page=atw' ),
					'quota'        => AWI_Rate_Limiter::get_status( $user instanceof WP_User ? (int) $user->ID : 0 ),
					'quotaLimit'   => AWI_Rate_Limiter::LIMIT,
				)
			);
		}
	}

	public static function render_legacy_redirect(): void {
		self::assert_access();

		$url = admin_url( 'admin.php?page=atw' );
		?>
		<script>location.href=<?php echo wp_json_encode( $url ); ?>;</script>
		<p>Redirecting…</p>
		<?php
	}

	public static function render_page(): void {
		self::assert_access();

		$state        = self::handle_settings_postback();
		$site_url     = home_url( '/' );
		$current_user = wp_get_current_user();

		if ( isset( $_GET['awi_app_pw_enabled'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Application Passwords enabled. The Chrome extension can now authenticate.', 'awi' ) . '</p></div>';
		}

		// Application Password management.
		$apppass_notice      = '';
		$apppass_new_plain   = '';
		$apppass_error       = '';
		$has_app_passwords   = class_exists( 'WP_Application_Passwords' );

		if ( $has_app_passwords ) {
			if ( isset( $_POST['awi_create_apppass'] ) && check_admin_referer( 'awi_apppass_action', 'awi_apppass_nonce' ) ) {
				$pw_name = isset( $_POST['awi_apppass_name'] ) ? sanitize_text_field( wp_unslash( $_POST['awi_apppass_name'] ) ) : '';
				if ( $pw_name === '' ) {
					$apppass_error = 'Please enter a name for the Application Password.';
				} else {
					$result = WP_Application_Passwords::create_new_application_password( $current_user->ID, array( 'name' => $pw_name ) );
					if ( is_wp_error( $result ) ) {
						$apppass_error = $result->get_error_message();
					} else {
						$apppass_new_plain = $result[0];
						$apppass_notice    = 'Application Password created for "' . esc_html( $pw_name ) . '".';
					}
				}
			}
			if ( isset( $_POST['awi_revoke_apppass'] ) && check_admin_referer( 'awi_apppass_action', 'awi_apppass_nonce' ) ) {
				$uuid = sanitize_text_field( wp_unslash( $_POST['awi_revoke_uuid'] ?? '' ) );
				if ( $uuid !== '' ) {
					$del = WP_Application_Passwords::delete_application_password( $current_user->ID, $uuid );
					$apppass_notice = is_wp_error( $del ) ? $del->get_error_message() : 'Application Password revoked.';
				}
			}
			if ( isset( $_POST['awi_revoke_all_apppass'] ) && check_admin_referer( 'awi_apppass_action', 'awi_apppass_nonce' ) ) {
				WP_Application_Passwords::delete_all_application_passwords( $current_user->ID );
				$apppass_notice = 'All Application Passwords revoked.';
			}
		}

		$all_app_passwords = array();
		if ( $has_app_passwords ) {
			$all_app_passwords = (array) WP_Application_Passwords::get_user_application_passwords( $current_user->ID );
		}
		?>
		<div class="awi-wrap awi-shell">
		<meta name="awi-url-import-bridge" content="1">
			<div class="awi-hero awi-hero--settings">
				<div class="awi-hero-copy">
					<h1>Alibaba to WooCommerce</h1>
					<p>Manage the AI rewrite layer, generate secure WordPress credentials for the extension, and launch bulk imports from a cleaner control panel.</p>
				</div>
				<div class="awi-hero-side">
					<a class="awi-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=atw-url-import' ) ); ?>">Open URL Import</a>
				</div>
			</div>

			<div class="awi-overview-grid">
				<div class="awi-card awi-card--soft">
					<div class="awi-card-head">
						<div>
							<h2>Recommended Workflow</h2>
							<p>Keep the extension and the plugin in sync with the same credentials and category setup.</p>
						</div>
					</div>
					<div class="awi-checklist">
						<div class="awi-checklist-item"><span class="awi-checkmark">1</span><span>Create or rotate a WordPress Application Password for the ATW extension.</span></div>
						<div class="awi-checklist-item"><span class="awi-checkmark">2</span><span>Set AI keywords and CTA defaults so imported descriptions are rewritten consistently.</span></div>
						<div class="awi-checklist-item"><span class="awi-checkmark">3</span><span>Use Url Import to run product-detail URLs through the same extension pipeline with logs and retries.</span></div>
					</div>
				</div>

				<div class="awi-card awi-card--soft">
					<div class="awi-card-head">
						<div>
							<h2>Quick Access</h2>
							<p>Shortcuts and current session info.</p>
						</div>
					</div>
					<div class="awi-info-grid">
						<div class="awi-info-item">
							<span class="awi-info-label">URL Import Screen</span>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=atw-url-import' ) ); ?>">Open batch importer</a>
						</div>
						<div class="awi-info-item">
							<span class="awi-info-label">Current User</span>
							<span><?php echo esc_html( $current_user->user_login ); ?></span>
						</div>
					</div>
				</div>
			</div>

			<div class="awi-panel-grid">
				<div class="awi-card awi-card--section">
					<div class="awi-card-head">
						<div>
							<h2>AI Rewrite Settings</h2>
							<p>Manage provider, models, and rewrite rules for imported copy.</p>
						</div>
					</div>

					<?php if ( $state['ai_notice'] !== '' ) : ?>
						<div class="awi-alert awi-alert--success"><?php echo esc_html( $state['ai_notice'] ); ?></div>
					<?php endif; ?>
					<?php if ( $state['ai_error'] !== '' ) : ?>
						<div class="awi-alert awi-alert--danger"><?php echo esc_html( $state['ai_error'] ); ?></div>
					<?php endif; ?>

					<form method="post" class="awi-form-stack">
						<?php wp_nonce_field( 'awi_save_ai_settings_action', 'awi_save_ai_settings_nonce' ); ?>

						<div class="awi-ai-summary">
							<div class="awi-ai-summary-item">
								<span class="awi-ai-summary-label">Rewrite</span>
								<strong class="awi-ai-summary-value"><?php echo $state['ai_enabled'] ? 'Enabled' : 'Disabled'; ?></strong>
							</div>
							<div class="awi-ai-summary-item">
								<span class="awi-ai-summary-label">Provider</span>
								<strong class="awi-ai-summary-value"><?php echo 'gemini_first' === $state['ai_provider_order'] ? 'Gemini → OpenAI' : 'OpenAI → Gemini'; ?></strong>
							</div>
							<div class="awi-ai-summary-item">
								<span class="awi-ai-summary-label">OpenAI</span>
								<strong class="awi-ai-summary-value"><?php echo $state['ai_openai_key_saved'] ? 'Ready' : 'Not Set'; ?></strong>
							</div>
							<div class="awi-ai-summary-item">
								<span class="awi-ai-summary-label">Gemini</span>
								<strong class="awi-ai-summary-value"><?php echo $state['ai_gemini_key_saved'] ? 'Ready' : 'Not Set'; ?></strong>
							</div>
						</div>

						<details class="awi-accordion">
							<summary>
								<div class="awi-accordion-copy">
									<span class="awi-accordion-title">General</span>
									<span class="awi-accordion-meta">Enable rewriting and choose provider order.</span>
								</div>
							</summary>
							<div class="awi-accordion-body">
								<div class="awi-kv">
									<div class="awi-k">Enable AI Rewrite</div>
									<div class="awi-v">
										<label class="awi-toggle">
											<input type="checkbox" name="awi_ai_enabled" value="1" <?php checked( $state['ai_enabled'] ); ?>>
											<span>Rewrite imported title, short description, and long description</span>
										</label>
									</div>
								</div>

								<div class="awi-kv">
									<div class="awi-k">Provider Order</div>
									<div class="awi-v">
										<select name="awi_ai_provider_order">
											<option value="openai_first" <?php selected( $state['ai_provider_order'], 'openai_first' ); ?>>OpenAI first, Gemini fallback</option>
											<option value="gemini_first" <?php selected( $state['ai_provider_order'], 'gemini_first' ); ?>>Gemini first, OpenAI fallback</option>
										</select>
										<div class="awi-field-help">If both keys are available, URL Import tries the first provider and falls back automatically if it fails.</div>
									</div>
								</div>
							</div>
						</details>

						<details class="awi-accordion">
							<summary>
								<div class="awi-accordion-copy">
									<span class="awi-accordion-title">OpenAI</span>
									<span class="awi-accordion-meta"><?php echo $state['ai_openai_key_saved'] ? 'Key saved' : 'Add key and model'; ?> · <?php echo esc_html( $state['ai_openai_model'] ); ?></span>
								</div>
							</summary>
							<div class="awi-accordion-body">
								<div class="awi-kv">
									<div class="awi-k">API Key</div>
									<div class="awi-v awi-inline-control">
										<input
											type="password"
											name="awi_ai_openai_api_key"
											placeholder="<?php echo $state['ai_openai_key_saved'] ? 'OpenAI key saved - leave blank to keep existing' : 'sk-proj-...'; ?>"
											autocomplete="new-password"
										/>
										<?php if ( $state['ai_openai_key_saved'] ) : ?>
											<span class="awi-inline-badge awi-inline-badge--success">&#10003; Key saved</span>
										<?php endif; ?>
									</div>
								</div>

								<div class="awi-kv">
									<div class="awi-k">Model</div>
									<div class="awi-v">
										<?php
										$openai_models = array(
											'gpt-4o-mini'    => 'gpt-4o-mini (cheapest, recommended)',
											'gpt-4.1-nano'   => 'gpt-4.1-nano (cheapest 4.1)',
											'gpt-4.1-mini'   => 'gpt-4.1-mini',
											'gpt-4o'         => 'gpt-4o',
											'gpt-4.1'        => 'gpt-4.1',
											'gpt-3.5-turbo'  => 'gpt-3.5-turbo',
											'custom'         => '— Custom model ID —',
										);
										$cur_openai = esc_attr( $state['ai_openai_model'] );
										$is_custom  = ! array_key_exists( $state['ai_openai_model'], $openai_models ) || $state['ai_openai_model'] === 'custom';
										?>
										<select name="awi_ai_openai_model_select" id="awi_openai_model_select" onchange="awiModelSelect('openai',this.value)">
											<?php foreach ( $openai_models as $val => $label ) : ?>
												<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $is_custom ? 'custom' : $state['ai_openai_model'], $val ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<input
											type="text"
											name="awi_ai_openai_model"
											id="awi_openai_model_custom"
											value="<?php echo $cur_openai; ?>"
											placeholder="gpt-4o-mini"
											style="<?php echo $is_custom ? '' : 'display:none;'; ?>margin-top:6px;"
										/>
										<div class="awi-field-help">Select a model or choose "Custom" to type any model ID.</div>
									</div>
								</div>
							</div>
						</details>

						<details class="awi-accordion">
							<summary>
								<div class="awi-accordion-copy">
									<span class="awi-accordion-title">Gemini</span>
									<span class="awi-accordion-meta"><?php echo $state['ai_gemini_key_saved'] ? 'Key saved' : 'Add key and model'; ?> · <?php echo esc_html( $state['ai_gemini_model'] ); ?></span>
								</div>
							</summary>
							<div class="awi-accordion-body">
								<div class="awi-kv">
									<div class="awi-k">API Key</div>
									<div class="awi-v awi-inline-control">
										<input
											type="password"
											name="awi_ai_gemini_api_key"
											placeholder="<?php echo $state['ai_gemini_key_saved'] ? 'Gemini key saved - leave blank to keep existing' : 'AIza...'; ?>"
											autocomplete="new-password"
										/>
										<?php if ( $state['ai_gemini_key_saved'] ) : ?>
											<span class="awi-inline-badge awi-inline-badge--success">&#10003; Key saved</span>
										<?php endif; ?>
									</div>
								</div>

								<div class="awi-kv">
									<div class="awi-k">Model</div>
									<div class="awi-v">
										<?php
										$gemini_models = array(
											'gemini-2.5-flash' => 'gemini-2.5-flash (recommended)',
											'gemini-2.0-flash' => 'gemini-2.0-flash',
											'gemini-1.5-flash' => 'gemini-1.5-flash',
											'gemini-1.5-flash-8b' => 'gemini-1.5-flash-8b (cheapest)',
											'gemini-1.5-pro'   => 'gemini-1.5-pro',
											'custom'           => '— Custom model ID —',
										);
										$cur_gemini    = esc_attr( $state['ai_gemini_model'] );
										$is_gcustom    = ! array_key_exists( $state['ai_gemini_model'], $gemini_models ) || $state['ai_gemini_model'] === 'custom';
										?>
										<select name="awi_ai_gemini_model_select" id="awi_gemini_model_select" onchange="awiModelSelect('gemini',this.value)">
											<?php foreach ( $gemini_models as $val => $label ) : ?>
												<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $is_gcustom ? 'custom' : $state['ai_gemini_model'], $val ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<input
											type="text"
											name="awi_ai_gemini_model"
											id="awi_gemini_model_custom"
											value="<?php echo $cur_gemini; ?>"
											placeholder="gemini-2.5-flash"
											style="<?php echo $is_gcustom ? '' : 'display:none;'; ?>margin-top:6px;"
										/>
										<div class="awi-field-help">Select a model or choose "Custom" to type any model ID.</div>
									</div>
								</div>
							</div>
						</details>

						<details class="awi-accordion">
							<summary>
								<div class="awi-accordion-copy">
									<span class="awi-accordion-title">Content Rules</span>
									<span class="awi-accordion-meta">Keywords and CTA defaults for rewritten content.</span>
								</div>
							</summary>
							<div class="awi-accordion-body">
								<div class="awi-kv">
									<div class="awi-k">Keywords</div>
									<div class="awi-v">
										<input
											type="text"
											name="awi_keywords"
											value="<?php echo esc_attr( $state['ai_keywords'] ); ?>"
											placeholder="e.g. wholesale, bulk orders, factory direct, MOQ"
										/>
										<div class="awi-field-help">Comma-separated terms blended into rewritten titles and descriptions.</div>
									</div>
								</div>

								<div class="awi-kv">
									<div class="awi-k">Call to Action URL</div>
									<div class="awi-v">
										<input
											type="url"
											name="awi_cta_url"
											value="<?php echo esc_attr( $state['ai_cta_url'] ); ?>"
											placeholder="https://yoursite.com/shop"
										/>
									</div>
								</div>
							</div>
						</details>

						<div class="awi-actions">
							<div class="awi-btn-row">
								<button type="submit" class="awi-btn" name="awi_save_ai_settings" value="1">Save Settings</button>
								<button type="submit" class="awi-ghost-btn" name="awi_test_openai_api" value="1">Test OpenAI</button>
								<button type="submit" class="awi-ghost-btn" name="awi_test_gemini_api" value="1">Test Gemini</button>
							</div>
							<div class="awi-field-help">The test buttons use the same key and model fields shown above. If you typed a new key but did not save yet, the test still uses that current value for this request.</div>
						</div>
					</form>
				</div>

				<div class="awi-card awi-card--section">
					<div class="awi-card-head">
						<div>
							<h2>Application Passwords</h2>
							<p>Create Application Passwords for the ATW Chrome extension. Copy the Base URL, Username and password into the extension settings.</p>
						</div>
						<a href="https://github.com/nasratulnayem/alibaba-woocommerce-importer/releases/latest" target="_blank" rel="noopener noreferrer" class="awi-btn" style="flex-shrink:0;white-space:nowrap;min-height:36px;padding:8px 16px;font-size:13px;"><svg style="width:14px;height:14px;vertical-align:middle;margin-right:6px;margin-top:-2px;" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="2" x2="8" y2="11"/><polyline points="4,8 8,12 12,8"/><line x1="2" y1="14" x2="14" y2="14"/></svg>Download Extension</a>
					</div>

					<div class="awi-info-grid" style="margin-bottom:10px;">
						<div class="awi-info-item">
							<span class="awi-info-label">WordPress Base URL</span>
							<code style="user-select:all;"><?php echo esc_html( rtrim( $site_url, '/' ) ); ?></code>
						</div>
						<div class="awi-info-item">
							<span class="awi-info-label">Username</span>
							<code style="user-select:all;"><?php echo esc_html( $current_user->user_login ); ?></code>
						</div>
					</div>

					<?php if ( $apppass_notice !== '' ) : ?>
						<div class="awi-alert awi-alert--success" style="margin-bottom:10px;"><?php echo esc_html( $apppass_notice ); ?></div>
					<?php endif; ?>
					<?php if ( $apppass_error !== '' ) : ?>
						<div class="awi-alert awi-alert--danger" style="margin-bottom:10px;"><?php echo esc_html( $apppass_error ); ?></div>
					<?php endif; ?>
					<?php if ( $apppass_new_plain !== '' ) : ?>
						<div class="awi-alert awi-alert--success" style="margin-bottom:10px;">
							<strong>Copy this password now — it will not be shown again:</strong><br><br>
							<code style="user-select:all;font-size:15px;letter-spacing:.08em;"><?php echo esc_html( trim( chunk_split( $apppass_new_plain, 4, ' ' ) ) ); ?></code>
						</div>
					<?php endif; ?>

					<form method="post" style="margin-bottom:0;">
						<?php wp_nonce_field( 'awi_apppass_action', 'awi_apppass_nonce' ); ?>
						<div class="awi-inline-control" style="margin-top:6px;">
							<input
								type="text"
								name="awi_apppass_name"
								class="regular-text"
								placeholder="Name"
								value=""
								style="flex:1;min-width:180px;"
							/>
							<button type="submit" name="awi_create_apppass" value="1" class="awi-btn">Add New Application Password</button>
						</div>
					</form>

					<?php if ( ! empty( $all_app_passwords ) ) : ?>
						<div class="awi-table-wrap" style="margin-top:12px;">
							<table class="widefat striped awi-table" style="width:100%;">
								<thead>
									<tr>
										<th>Name</th>
										<th>Created</th>
										<th>Last Used</th>
										<th>Last IP</th>
										<th></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $all_app_passwords as $ap ) : ?>
										<?php
										$ap_name    = isset( $ap['name'] )      ? (string) $ap['name']      : '—';
										$ap_uuid    = isset( $ap['uuid'] )      ? (string) $ap['uuid']      : '';
										$ap_created = isset( $ap['created'] )   ? wp_date( 'Y-m-d H:i', (int) $ap['created'] ) : '—';
										$ap_last    = isset( $ap['last_used'] ) && $ap['last_used'] ? wp_date( 'Y-m-d H:i', (int) $ap['last_used'] ) : 'Never';
										$ap_ip      = isset( $ap['last_ip'] )   ? (string) $ap['last_ip']  : '—';
										?>
										<tr>
											<td><strong><?php echo esc_html( $ap_name ); ?></strong></td>
											<td><?php echo esc_html( $ap_created ); ?></td>
											<td><?php echo esc_html( $ap_last ); ?></td>
											<td><?php echo esc_html( $ap_ip ); ?></td>
											<td>
												<?php if ( $ap_uuid !== '' ) : ?>
												<form method="post" style="margin:0;">
													<?php wp_nonce_field( 'awi_apppass_action', 'awi_apppass_nonce' ); ?>
													<input type="hidden" name="awi_revoke_uuid" value="<?php echo esc_attr( $ap_uuid ); ?>" />
													<button type="submit" name="awi_revoke_apppass" value="1" class="awi-ghost-btn" style="color:var(--awi-danger,#dc2626);" onclick="return confirm('Revoke this Application Password?')">Revoke</button>
												</form>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<form method="post" style="margin-top:10px;">
							<?php wp_nonce_field( 'awi_apppass_action', 'awi_apppass_nonce' ); ?>
							<button type="submit" name="awi_revoke_all_apppass" value="1" class="awi-ghost-btn" style="color:var(--awi-danger,#dc2626);" onclick="return confirm('Revoke ALL Application Passwords for this user?')">Revoke All</button>
						</form>
					<?php else : ?>
						<p class="awi-empty-state" style="margin-top:10px;">No Application Passwords yet. Add one above.</p>
					<?php endif; ?>

					<div class="awi-note-box" style="margin-top:14px;">
						<strong>How to connect the extension:</strong><br>
						1. Type a name (e.g. <em>ATW Extension</em>) and click <strong>Add New Application Password</strong>.<br>
						2. Copy the password shown immediately — it won't appear again.<br>
						3. Open the ATW extension → Settings and paste: Base URL, Username, and the Application Password.
					</div>
				</div>
			</div>

			<script>
			function awiModelSelect(provider, value) {
				var customInput = document.getElementById('awi_' + provider + '_model_custom');
				if (!customInput) return;
				if (value === 'custom') {
					customInput.style.display = '';
					customInput.focus();
				} else {
					customInput.style.display = 'none';
					customInput.value = value;
				}
			}
			</script>
		</div>
		<?php
	}

	public static function render_url_import_page(): void {
		self::assert_access();

		$latest_run = AWI_Url_Import::get_latest_run();
		?>
		<div class="awi-wrap awi-shell">
			<meta name="awi-url-import-bridge" content="1">

			<div class="awi-hero awi-hero--import">
				<div class="awi-hero-copy">
					<h1>Batch Import from Alibaba</h1>
					<p>Queue product-detail URLs, assign a WooCommerce category once, and let the extension run the same import flow with better visibility and retry support.</p>
				</div>
				<div class="awi-hero-side">
					<div class="awi-hero-actions">
						<button type="button" class="awi-ghost-btn" id="awi-url-import-extension-refresh">Refresh Extension Status</button>
						<a class="awi-ghost-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=atw' ) ); ?>">Settings</a>
					</div>
				</div>
			</div>

			<?php
			$quota_init  = AWI_Rate_Limiter::get_status( (int) wp_get_current_user()->ID );
			$q_is_pro    = ! empty( $quota_init['is_pro'] );
			$q_remaining = $q_is_pro ? -1 : (int) $quota_init['remaining'];
			$q_limit     = AWI_Rate_Limiter::LIMIT;
			$q_blocked   = ! $quota_init['allowed'];
			$q_pct       = ( ! $q_is_pro && $q_limit > 0 ) ? round( ( ( $q_limit - max( 0, $q_remaining ) ) / $q_limit ) * 100 ) : 0;

			// Build upgrade URL only when Freemius SDK is loaded.
			$upgrade_url = '';
			if ( function_exists( 'atw_fs' ) && atw_fs() !== null ) {
				$upgrade_url = atw_fs()->get_upgrade_url();
			}
			?>
			<div class="awi-card awi-card--section" id="awi-quota-card" style="margin-bottom:16px;">
				<div class="awi-card-head">
					<div>
						<h2>Import Quota
							<?php if ( $q_is_pro ) : ?>
								<span style="display:inline-flex;align-items:center;margin-left:8px;padding:3px 10px;border-radius:999px;background:linear-gradient(135deg,#0f6fff,#34c3ff);color:#fff;font-size:11px;font-weight:900;letter-spacing:.06em;vertical-align:middle;">PRO</span>
							<?php endif; ?>
						</h2>
						<p>
							<?php if ( $q_is_pro ) : ?>
								<?php esc_html_e( 'Pro plan — unlimited imports, no cooldown.', 'awi' ); ?>
							<?php else : ?>
								<?php printf( esc_html__( 'Free plan: %d imports per hour. Limit reached triggers an 8-hour cooldown.', 'awi' ), (int) $q_limit ); ?>
							<?php endif; ?>
						</p>
					</div>
					<?php if ( $q_is_pro ) : ?>
						<span class="awi-status-pill awi-status-pill--success">Pro</span>
					<?php else : ?>
						<span class="awi-status-pill <?php echo $q_blocked ? 'awi-status-pill--danger' : ( $q_remaining <= 5 ? 'awi-status-pill--warning' : 'awi-status-pill--success' ); ?>" id="awi-quota-pill">
							<?php echo $q_blocked ? esc_html__( 'Blocked', 'awi' ) : esc_html( $q_remaining . ' left' ); ?>
						</span>
					<?php endif; ?>
				</div>

				<?php if ( ! $q_is_pro ) : ?>
				<div style="margin-bottom:10px;">
					<div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:13px;color:var(--awi-muted);">
						<span id="awi-quota-label">
							<?php
							if ( $q_blocked ) {
								echo esc_html( AWI_Rate_Limiter::format_status( $quota_init ) );
							} else {
								echo esc_html( $q_remaining . ' of ' . $q_limit . ' imports remaining this hour' );
							}
							?>
						</span>
						<span id="awi-quota-timer" style="font-weight:700;<?php echo ! $q_blocked ? 'display:none;' : ''; ?>"></span>
					</div>
					<div class="awi-progress" aria-hidden="true">
						<div class="awi-progress-bar" id="awi-quota-bar" style="width:<?php echo (int) $q_pct; ?>%;background:<?php echo $q_blocked ? 'var(--awi-danger,#dc2626)' : ( $q_remaining <= 5 ? 'var(--awi-warning,#ca8a04)' : '' ); ?>;"></div>
					</div>
				</div>

				<?php if ( $q_blocked ) : ?>
				<div class="awi-alert awi-alert--danger" id="awi-quota-blocked-msg" style="margin-bottom:10px;">
					<?php echo esc_html( AWI_Rate_Limiter::format_status( $quota_init ) ); ?>
				</div>
				<?php endif; ?>

				<?php if ( $upgrade_url !== '' && ( $q_blocked || $q_remaining <= 5 ) ) : ?>
				<div class="awi-note-box" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
					<span style="font-size:13px;">
						<?php esc_html_e( 'Upgrade to Pro for unlimited imports and no cooldown.', 'awi' ); ?>
					</span>
					<a href="<?php echo esc_url( $upgrade_url ); ?>" class="awi-btn" style="flex-shrink:0;min-height:34px;padding:7px 14px;font-size:12px;" target="_blank" rel="noopener">
						<?php esc_html_e( 'Upgrade to Pro', 'awi' ); ?>
					</a>
				</div>
				<?php endif; ?>

				<?php endif; // end !$q_is_pro ?>
			</div>

			<div class="awi-grid awi-grid--import">
				<div class="awi-card awi-card--section awi-card--highlight">
					<div class="awi-card-head">
						<div>
							<h2>Import Queue</h2>
							<p>Paste one product-detail URL per line. Duplicate URLs are removed before the run starts.</p>
						</div>
					</div>
					<div class="awi-field">
						<label for="awi-url-import-urls">Product URLs</label>
						<textarea id="awi-url-import-urls" rows="12" placeholder="https://www.alibaba.com/product-detail/demo-product-name_160000000001.html&#10;https://chinaheadwearfactory.com/product/custom-snapback-hat/"></textarea>
						<div class="awi-field-help">One product URL per line. Supports Alibaba and any product page with schema markup.</div>
					</div>

					<div class="awi-form-inline">
						<div class="awi-field">
							<label for="awi-url-import-category">WooCommerce category</label>
							<select id="awi-url-import-category"></select>
						</div>
						<div class="awi-field awi-field-actions">
							<label>&nbsp;</label>
							<div class="awi-btn-row">
								<button type="button" class="awi-btn" id="awi-url-import-start">Import</button>
								<button type="button" class="awi-ghost-btn" id="awi-url-import-retry" disabled>Retry Failed</button>
							</div>
						</div>
					</div>
				</div>

				<div class="awi-card awi-card--section">
					<div class="awi-card-head">
						<div>
							<h2>Extension Status</h2>
							<p>The batch importer depends on the ATW extension bridge running on this admin tab.</p>
						</div>
						<span class="awi-status-pill awi-status-pill--neutral" id="awi-url-import-extension-pill">Checking</span>
					</div>

					<div class="awi-note-box awi-note-box--status awi-note-box--hidden" id="awi-url-import-extension-box" data-tone="neutral">
						<span id="awi-url-import-extension-status" aria-live="polite">Checking ATW extension...</span>
					</div>


					<div class="awi-info-grid awi-info-grid--compact">
						<div class="awi-info-item">
							<span class="awi-info-label">Latest failed log</span>
							<a id="awi-url-import-log-link" href="<?php echo ! empty( $latest_run['log_url'] ) ? esc_url( $latest_run['log_url'] ) : '#'; ?>" target="_blank" rel="noopener">failed-log.txt</a>
						</div>
						<div class="awi-info-item">
							<span class="awi-info-label">Current WordPress user</span>
							<span><?php echo esc_html( wp_get_current_user()->user_login ); ?></span>
						</div>
					</div>

					<div class="awi-card-head awi-card-head--compact awi-card-head--top-gap">
						<div>
							<h2>Current Run</h2>
							<p>Live counters update as the extension finishes each URL.</p>
						</div>
					</div>
					<div class="awi-run-overview">
						<div class="awi-run-status-card">
							<div class="awi-run-status-meta">
								<div class="awi-stat-label">Status</div>
								<div class="awi-run-status-value" id="awi-run-status">Idle</div>
							</div>
							<p id="awi-run-message" class="awi-run-status-text">No run started yet.</p>
						</div>

						<div class="awi-run-stats-grid">
							<div class="awi-stat awi-stat--compact">
								<div class="awi-stat-label">Total</div>
								<div class="awi-stat-value" id="awi-run-total">0</div>
							</div>
							<div class="awi-stat awi-stat--compact">
								<div class="awi-stat-label">Processed</div>
								<div class="awi-stat-value" id="awi-run-processed">0</div>
							</div>
							<div class="awi-stat awi-stat--compact">
								<div class="awi-stat-label">Success</div>
								<div class="awi-stat-value" id="awi-run-success">0</div>
							</div>
							<div class="awi-stat awi-stat--compact">
								<div class="awi-stat-label">Failed</div>
								<div class="awi-stat-value" id="awi-run-failed">0</div>
							</div>
						</div>
					</div>

					<div class="awi-progress" aria-hidden="true">
						<div class="awi-progress-bar" id="awi-run-progress-bar"></div>
					</div>
				</div>
			</div>

			<div class="awi-card awi-card--section awi-card--recent">
				<div class="awi-card-head">
					<div>
						<h2>Recent Runs</h2>
						<p>Quick visibility into recent queues and failure counts.</p>
					</div>
					<button type="button" class="awi-ghost-btn" id="awi-url-import-clear-runs">Clear All</button>
				</div>
				<div class="awi-table-wrap awi-table-wrap--recent">
					<table class="widefat striped awi-table awi-run-table">
						<thead>
							<tr>
								<th>Run</th>
								<th>Category</th>
								<th>Status</th>
								<th>Total</th>
								<th>Success</th>
								<th>Failed</th>
								<th>Log</th>
							</tr>
						</thead>
						<tbody id="awi-url-import-runs-body">
							<tr><td colspan="7" class="awi-empty-state">No import runs yet.</td></tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<script>
		(function () {
			var cfg   = window.awiUrlImportData || {};
			var quota = cfg.quota || {};
			var limit = cfg.quotaLimit || 20;

			var pill       = document.getElementById('awi-quota-pill');
			var bar        = document.getElementById('awi-quota-bar');
			var label      = document.getElementById('awi-quota-label');
			var timer      = document.getElementById('awi-quota-timer');
			var blockedMsg = document.getElementById('awi-quota-blocked-msg');
			var startBtn   = document.getElementById('awi-url-import-start');
			var retryBtn   = document.getElementById('awi-url-import-retry');

			function pad(n) { return n < 10 ? '0' + n : String(n); }

			function formatCountdown(seconds) {
				seconds = Math.max(0, Math.floor(seconds));
				var h = Math.floor(seconds / 3600);
				var m = Math.floor((seconds % 3600) / 60);
				var s = seconds % 60;
				return pad(h) + ':' + pad(m) + ':' + pad(s);
			}

			function applyQuota(q) {
				if (!q) return;
				quota = q;
				var blocked = !q.allowed;
				var isPro   = !!q.is_pro;
				var card    = document.getElementById('awi-quota-card');

				// Pro plan — hide quota card, unlock buttons, done.
				if (isPro) {
					if (card) card.style.display = 'none';
					if (startBtn) startBtn.disabled = false;
					if (retryBtn) retryBtn.disabled = false;
					return;
				}
				if (card) card.style.display = '';

				var remaining = q.remaining || 0;
				var pct       = Math.round(((limit - remaining) / limit) * 100);

				// Progress bar.
				if (bar) {
					bar.style.width = pct + '%';
					bar.style.background = blocked ? 'var(--awi-danger,#dc2626)' : (remaining <= 5 ? 'var(--awi-warning,#ca8a04)' : '');
				}

				// Status pill.
				if (pill) {
					pill.className = 'awi-status-pill ' + (blocked ? 'awi-status-pill--danger' : (remaining <= 5 ? 'awi-status-pill--warning' : 'awi-status-pill--success'));
					pill.textContent = blocked ? 'Blocked' : remaining + ' left';
				}

				// Label.
				if (label && !blocked) {
					label.textContent = remaining + ' of ' + limit + ' imports remaining this hour';
				}

				// Blocked message banner.
				if (blockedMsg) {
					blockedMsg.style.display = blocked ? '' : 'none';
				}

				// Timer visibility.
				if (timer) {
					timer.style.display = blocked ? '' : 'none';
				}

				// Import buttons.
				if (startBtn) startBtn.disabled = blocked;
				if (retryBtn && blocked) retryBtn.disabled = true;
			}

			// Live countdown tick (runs every second when cooldown active).
			var countdownInterval = null;
			function startCountdown(cooldownUntil) {
				if (countdownInterval) clearInterval(countdownInterval);
				countdownInterval = setInterval(function () {
					var remaining = cooldownUntil - Math.floor(Date.now() / 1000);
					if (!timer) return;
					if (remaining <= 0) {
						clearInterval(countdownInterval);
						timer.textContent = '';
						pollQuota(); // re-check server state once cooldown expires
						return;
					}
					timer.textContent = formatCountdown(remaining);
				}, 1000);
			}

			// Poll server for current quota state.
			function pollQuota() {
				if (!cfg.ajaxUrl || !cfg.nonce) return;
				var body = new URLSearchParams();
				body.set('action', 'awi_get_quota');
				body.set('nonce', cfg.nonce);
				fetch(cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body.toString()
				})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data && data.success && data.data && data.data.quota) {
						applyQuota(data.data.quota);
						if (!data.data.quota.allowed && data.data.quota.cooldown_until) {
							startCountdown(data.data.quota.cooldown_until);
						}
					}
				})
				.catch(function () {});
			}

			// Initial render from server-side data.
			applyQuota(quota);
			if (quota && !quota.allowed && quota.cooldown_until) {
				startCountdown(quota.cooldown_until);
			}

			// Poll every 60 s to keep UI in sync across multiple tabs/extension calls.
			setInterval(pollQuota, 60000);
		})();
		</script>
		<?php
	}

	private static function assert_access(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'awi' ) );
		}
	}

	private static function can_manage(): bool {
		$cap = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
		return current_user_can( $cap );
	}

	private static function build_ai_settings_from_post( array $current ): array {
		$new_openai_key = isset( $_POST['awi_ai_openai_api_key'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['awi_ai_openai_api_key'] ) ) : '';
		if ( $new_openai_key !== '' ) {
			$current['openai_api_key'] = $new_openai_key;
			$current['api_key']        = $new_openai_key;
		}

		$new_gemini_key = isset( $_POST['awi_ai_gemini_api_key'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['awi_ai_gemini_api_key'] ) ) : '';
		if ( $new_gemini_key !== '' ) {
			$current['gemini_api_key'] = $new_gemini_key;
		}

		// Text field holds the real model ID (JS updates it when preset selected, user types when custom).
		$openai_model = isset( $_POST['awi_ai_openai_model'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['awi_ai_openai_model'] ) ) : '';
		if ( $openai_model === '' || $openai_model === 'custom' ) {
			$openai_model = isset( $_POST['awi_ai_openai_model_select'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['awi_ai_openai_model_select'] ) ) : '';
		}
		if ( $openai_model === '' || $openai_model === 'custom' ) {
			$openai_model = 'gpt-4o-mini';
		}
		$gemini_model = isset( $_POST['awi_ai_gemini_model'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['awi_ai_gemini_model'] ) ) : '';
		if ( $gemini_model === '' || $gemini_model === 'custom' ) {
			$gemini_model = isset( $_POST['awi_ai_gemini_model_select'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['awi_ai_gemini_model_select'] ) ) : '';
		}
		if ( $gemini_model === '' || $gemini_model === 'custom' ) {
			$gemini_model = 'gemini-2.5-flash';
		}
		$current['openai_model'] = $openai_model;
		$current['gemini_model'] = $gemini_model;

		$provider_order = isset( $_POST['awi_ai_provider_order'] ) ? sanitize_key( (string) wp_unslash( $_POST['awi_ai_provider_order'] ) ) : 'openai_first';
		if ( ! in_array( $provider_order, array( 'openai_first', 'gemini_first' ), true ) ) {
			$provider_order = 'openai_first';
		}

		$current['enabled']        = ! empty( $_POST['awi_ai_enabled'] );
		$current['cta_url']        = isset( $_POST['awi_cta_url'] ) ? esc_url_raw( (string) wp_unslash( $_POST['awi_cta_url'] ) ) : '';
		$current['keywords']       = isset( $_POST['awi_keywords'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['awi_keywords'] ) ) : '';
		$current['provider_order'] = $provider_order;

		return $current;
	}

	private static function handle_settings_postback(): array {
		$ai_notice = '';
		$ai_error  = '';
		$ai_settings = get_option( 'awi_ai_settings', array() );
		if ( ! is_array( $ai_settings ) ) {
			$ai_settings = array();
		}

		if ( isset( $_POST['awi_save_ai_settings'] ) || isset( $_POST['awi_test_openai_api'] ) || isset( $_POST['awi_test_gemini_api'] ) ) {
			check_admin_referer( 'awi_save_ai_settings_action', 'awi_save_ai_settings_nonce' );

			$ai_settings = self::build_ai_settings_from_post( $ai_settings );

			if ( isset( $_POST['awi_save_ai_settings'] ) ) {
				update_option( 'awi_ai_settings', $ai_settings );
				$ai_notice = 'AI settings saved.';
			}

			if ( isset( $_POST['awi_test_openai_api'] ) || isset( $_POST['awi_test_gemini_api'] ) ) {
				$provider    = isset( $_POST['awi_test_gemini_api'] ) ? 'gemini' : 'openai';
				$test_result = AWI_Rest::test_ai_provider_connection( $ai_settings, $provider );
				if ( ! empty( $test_result['ok'] ) ) {
					$ai_notice = isset( $test_result['message'] ) ? (string) $test_result['message'] : ucfirst( $provider ) . ' connection succeeded.';
				} else {
					$ai_error = isset( $test_result['message'] ) ? (string) $test_result['message'] : ucfirst( $provider ) . ' connection failed.';
				}
			}
		}

		$ai_enabled            = ! isset( $ai_settings['enabled'] ) ? true : ! empty( $ai_settings['enabled'] );
		$ai_openai_key_saved   = ! empty( $ai_settings['openai_api_key'] ) || ! empty( $ai_settings['api_key'] );
		$ai_gemini_key_saved   = ! empty( $ai_settings['gemini_api_key'] );
		$ai_cta_url            = isset( $ai_settings['cta_url'] ) ? (string) $ai_settings['cta_url'] : '';
		$ai_keywords           = isset( $ai_settings['keywords'] ) ? (string) $ai_settings['keywords'] : '';
		$ai_provider_order     = isset( $ai_settings['provider_order'] ) && in_array( $ai_settings['provider_order'], array( 'openai_first', 'gemini_first' ), true ) ? (string) $ai_settings['provider_order'] : 'openai_first';
		$ai_openai_model       = isset( $ai_settings['openai_model'] ) && is_string( $ai_settings['openai_model'] ) && $ai_settings['openai_model'] !== '' ? (string) $ai_settings['openai_model'] : 'gpt-4o';
		$ai_gemini_model       = isset( $ai_settings['gemini_model'] ) && is_string( $ai_settings['gemini_model'] ) && $ai_settings['gemini_model'] !== '' ? (string) $ai_settings['gemini_model'] : 'gemini-2.5-flash';

		$app_password_created = null;
		$app_password_error   = '';
		$app_password_notice  = '';

		if ( isset( $_POST['atw_revoke_app_password'] ) ) {
			check_admin_referer( 'atw_revoke_app_password_action', 'atw_revoke_app_password_nonce' );

			$uuid = isset( $_POST['atw_app_password_uuid'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['atw_app_password_uuid'] ) ) : '';
			if ( $uuid === '' ) {
				$app_password_error = 'Missing application password id.';
			} elseif ( ! class_exists( 'WP_Application_Passwords' ) ) {
				$app_password_error = 'Application Passwords are not available on this WordPress installation.';
			} else {
				$res = WP_Application_Passwords::delete_application_password( get_current_user_id(), $uuid );
				if ( is_wp_error( $res ) ) {
					$app_password_error = $res->get_error_message();
				} else {
					$app_password_notice = 'Application Password revoked.';
				}
			}
		}

		if ( isset( $_POST['atw_create_app_password'] ) ) {
			check_admin_referer( 'atw_create_app_password_action', 'atw_create_app_password_nonce' );

			$name = isset( $_POST['atw_app_password_name'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['atw_app_password_name'] ) ) : '';
			if ( $name === '' ) {
				$app_password_error = 'Please enter an application name.';
			} elseif ( ! class_exists( 'WP_Application_Passwords' ) ) {
				$app_password_error = 'Application Passwords are not available on this WordPress installation.';
			} elseif ( ! function_exists( 'wp_is_application_passwords_available_for_user' ) || ! wp_is_application_passwords_available_for_user( get_current_user_id() ) ) {
				$app_password_error = 'Application Passwords are disabled for this site/user.';
			} else {
				$res = WP_Application_Passwords::create_new_application_password(
					get_current_user_id(),
					array( 'name' => $name )
				);

				if ( is_wp_error( $res ) ) {
					$app_password_error = $res->get_error_message();
				} elseif ( is_array( $res ) && ! empty( $res[0] ) ) {
					$app_password_created = (string) $res[0];
				} else {
					$app_password_error = 'Failed to create application password.';
				}
			}
		}

		$existing_passwords = array();
		if ( class_exists( 'WP_Application_Passwords' ) ) {
			$existing_passwords = WP_Application_Passwords::get_user_application_passwords( get_current_user_id() );
			if ( ! is_array( $existing_passwords ) ) {
				$existing_passwords = array();
			}
		}

		return array(
			'ai_notice'           => $ai_notice,
			'ai_error'            => $ai_error,
			'ai_enabled'          => $ai_enabled,
			'ai_openai_key_saved' => $ai_openai_key_saved,
			'ai_gemini_key_saved' => $ai_gemini_key_saved,
			'ai_provider_order'   => $ai_provider_order,
			'ai_openai_model'     => $ai_openai_model,
			'ai_gemini_model'     => $ai_gemini_model,
			'ai_cta_url'          => $ai_cta_url,
			'ai_keywords'         => $ai_keywords,
			'app_password_created'=> $app_password_created,
			'app_password_error'  => $app_password_error,
			'app_password_notice' => $app_password_notice,
			'existing_passwords'  => $existing_passwords,
		);
	}

	private static function get_common_admin_css(): string {
		return implode(
			"\n",
			array(
				'.awi-shell { --awi-bg: #f3f5fb; --awi-card: #ffffff; --awi-text: #14213d; --awi-muted: #5f6b84; --awi-border: #dbe2ef; --awi-strong-border: #c6d0e1; --awi-accent: #0f6fff; --awi-accent-dark: #0b4db4; --awi-soft: #eef5ff; --awi-soft-alt: #f8faff; --awi-success: #117a46; --awi-success-bg: #edf9f1; --awi-danger: #b42318; --awi-danger-bg: #fff2f0; --awi-warning: #9a6700; --awi-warning-bg: #fff8db; max-width: 1240px; margin: 24px auto 32px; color: var(--awi-text); }',
				'.awi-shell *, .awi-shell *::before, .awi-shell *::after { box-sizing: border-box; }',
				'.awi-shell a { color: var(--awi-accent-dark); }',
				'.awi-wrap.awi-shell { padding-right: 18px; }',
				'.awi-hero { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(280px, .7fr); gap: 18px; align-items: stretch; margin-bottom: 18px; padding: 24px; border-radius: 24px; background: linear-gradient(135deg, #14213d 0%, #214b8f 55%, #376fe8 100%); color: #fff; box-shadow: 0 24px 60px rgba(15, 35, 75, .18); }',
				'.awi-hero-copy h1 { margin: 0; font-size: 31px; line-height: 1.1; color: #fff; }',
				'.awi-hero-copy p { margin: 12px 0 0; max-width: 720px; color: rgba(255,255,255,.86); font-size: 14px; }',
				'.awi-eyebrow { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 10px; padding: 6px 10px; border-radius: 999px; background: rgba(255,255,255,.14); color: #dce8ff; font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }',
				'.awi-hero-side { display: grid; gap: 14px; align-content: start; }',
				'.awi-hero--settings .awi-hero-side { display: flex; align-items: center; justify-content: flex-end; }',
				'.awi-hero-actions { display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }',
				'.awi-hero-meta { display: grid; gap: 10px; padding: 14px; border-radius: 18px; background: rgba(8, 21, 48, .32); border: 1px solid rgba(255,255,255,.14); backdrop-filter: blur(6px); }',
				'.awi-meta-item { display: grid; gap: 2px; }',
				'.awi-meta-label { color: rgba(255,255,255,.66); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }',
				'.awi-meta-value { color: #fff; font-weight: 700; line-height: 1.4; word-break: break-word; }',
				'.awi-overview-grid, .awi-panel-grid, .awi-grid { display: grid; gap: 16px; }',
				'.awi-overview-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 16px; }',
				'.awi-panel-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 16px; }',
				'.awi-grid--import { grid-template-columns: minmax(0, 1.15fr) minmax(340px, .85fr); margin-bottom: 16px; }',
				'.awi-grid--tables { grid-template-columns: repeat(2, minmax(0, 1fr)); }',
				'.awi-card { background: var(--awi-card); border: 1px solid var(--awi-border); border-radius: 22px; padding: 20px; box-shadow: 0 14px 34px rgba(15, 23, 42, .05); }',
				'.awi-card--soft { background: linear-gradient(180deg, #ffffff 0%, var(--awi-soft-alt) 100%); }',
				'.awi-card--highlight { background: linear-gradient(180deg, #ffffff 0%, #f6f9ff 100%); border-color: #d3e2ff; }',
				'.awi-card--cta { margin-top: 0; }',
				'.awi-card--recent { margin-top: 16px; }',
				'.awi-card-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; margin-bottom: 16px; }',
				'.awi-card-head--compact { margin-bottom: 12px; }',
				'.awi-card-head--top-gap { margin-top: 20px; }',
				'.awi-card-head h2, .awi-card-head h3 { margin: 0; color: var(--awi-text); }',
				'.awi-card-head h2 { font-size: 18px; }',
				'.awi-card-head h3 { font-size: 15px; }',
				'.awi-card-head p { margin: 6px 0 0; color: var(--awi-muted); font-size: 13px; }',
				'.awi-checklist { display: grid; gap: 12px; }',
				'.awi-checklist-item { display: grid; grid-template-columns: 30px 1fr; gap: 12px; align-items: start; color: var(--awi-text); }',
				'.awi-checkmark { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; background: var(--awi-soft); color: var(--awi-accent-dark); font-weight: 900; }',
				'.awi-info-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }',
				'.awi-info-grid--compact { grid-template-columns: 1fr; }',
				'.awi-info-item { padding: 14px; border-radius: 16px; border: 1px solid var(--awi-border); background: #fff; min-width: 0; }',
				'.awi-info-item code { display: inline-block; max-width: 100%; overflow-wrap: anywhere; user-select: all; }',
				'.awi-info-label { display: block; margin-bottom: 6px; color: var(--awi-muted); font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }',
				'.awi-alert { margin-bottom: 14px; padding: 12px 14px; border-radius: 14px; border: 1px solid transparent; font-weight: 700; }',
				'.awi-alert--success { color: var(--awi-success); background: var(--awi-success-bg); border-color: #cfead8; }',
				'.awi-alert--danger { color: var(--awi-danger); background: var(--awi-danger-bg); border-color: #f7d1cc; }',
				'.awi-form-stack { display: grid; gap: 16px; }',
				'.awi-ai-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0; border: 1px solid var(--awi-border); border-radius: 18px; background: #fbfcff; overflow: hidden; }',
				'.awi-ai-summary-item { min-width: 0; padding: 14px 16px; border-right: 1px solid var(--awi-border); }',
				'.awi-ai-summary-item:last-child { border-right: 0; }',
				'.awi-ai-summary-label { display: block; margin-bottom: 6px; color: var(--awi-muted); font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; }',
				'.awi-ai-summary-value { display: block; color: var(--awi-text); font-size: 14px; line-height: 1.35; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }',
				'.awi-accordion { border: 1px solid var(--awi-border); border-radius: 18px; background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%); overflow: hidden; }',
				'.awi-accordion summary { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px 18px; cursor: pointer; list-style: none; }',
				'.awi-accordion summary::-webkit-details-marker { display: none; }',
				'.awi-accordion summary::after { content: "+"; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: var(--awi-soft); color: var(--awi-accent-dark); font-size: 18px; font-weight: 700; flex: 0 0 auto; }',
				'.awi-accordion[open] summary::after { content: "−"; }',
				'.awi-accordion-copy { display: grid; gap: 4px; min-width: 0; }',
				'.awi-accordion-title { color: var(--awi-text); font-size: 15px; font-weight: 900; }',
				'.awi-accordion-meta { color: var(--awi-muted); font-size: 12px; line-height: 1.45; }',
				'.awi-accordion-body { padding: 0 18px 18px; border-top: 1px solid var(--awi-border); background: #fff; }',
				'.awi-accordion-body .awi-kv:first-child { padding-top: 16px; }',
				'.awi-kv { display: grid; grid-template-columns: 210px minmax(0, 1fr); gap: 12px 18px; align-items: start; }',
				'.awi-k { color: var(--awi-text); font-weight: 800; padding-top: 10px; }',
				'.awi-v { min-width: 0; }',
				'.awi-v code { user-select: all; }',
				'.awi-inline-control { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }',
				'.awi-inline-badge { display: inline-flex; align-items: center; gap: 6px; padding: 7px 10px; border-radius: 999px; font-size: 12px; font-weight: 800; }',
				'.awi-inline-badge--success { color: var(--awi-success); background: var(--awi-success-bg); }',
				'.awi-form { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: end; }',
				'.awi-form--password .awi-form-action { display: flex; align-items: flex-end; height: 100%; }',
				'.awi-inline-form { margin: 0; }',
				'.awi-form label, .awi-field label { display: block; margin-bottom: 7px; color: var(--awi-text); font-weight: 800; }',
				'.awi-form input[type="text"], .awi-form input[type="url"], .awi-form input[type="password"], .awi-field input[type="text"], .awi-field input[type="url"], .awi-field textarea, .awi-field select, .awi-v input[type="text"], .awi-v input[type="url"], .awi-v input[type="password"] { width: 100%; min-height: 46px; padding: 12px 14px; border: 1px solid var(--awi-strong-border); border-radius: 14px; background: #fff; color: var(--awi-text); box-shadow: inset 0 1px 2px rgba(15, 23, 42, .02); }',
				'.awi-form input:focus, .awi-field input:focus, .awi-field textarea:focus, .awi-field select:focus, .awi-v input:focus { border-color: var(--awi-accent); outline: none; box-shadow: 0 0 0 3px rgba(15, 111, 255, .12); }',
				'.awi-field textarea { min-height: 290px; resize: vertical; font-family: Consolas, Monaco, monospace; line-height: 1.45; }',
				'.awi-field-help { margin-top: 7px; color: var(--awi-muted); font-size: 12px; }',
				'.awi-toggle { display: inline-flex; align-items: center; gap: 10px; font-weight: 700; }',
				'.awi-toggle input { margin: 0; }',
				'.awi-actions { display: grid; gap: 10px; }',
				'.awi-btn, .awi-copy, .awi-ghost-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 42px; padding: 10px 16px; border-radius: 999px; font-weight: 900; cursor: pointer; text-decoration: none; transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease, background .15s ease; }',
				'.awi-btn, .awi-btn:visited, .awi-btn:hover, .awi-btn:focus { color: #fff !important; }',
				'.awi-btn { border: 1px solid transparent; background: linear-gradient(135deg, var(--awi-accent) 0%, #5b8cff 100%); box-shadow: 0 14px 28px rgba(15, 111, 255, .22); }',
				'.awi-btn:hover { transform: translateY(-1px); box-shadow: 0 18px 32px rgba(15, 111, 255, .28); }',
				'.awi-btn[disabled] { opacity: .55; cursor: not-allowed; box-shadow: none; transform: none; }',
				'.awi-copy, .awi-ghost-btn { border: 1px solid var(--awi-strong-border); background: #fff; color: var(--awi-text); }',
				'.awi-copy:hover, .awi-ghost-btn:hover { border-color: var(--awi-accent); color: var(--awi-accent-dark); transform: translateY(-1px); }',
				'.awi-help-wrap { position: relative; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; }',
				'.awi-help-trigger { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; border: 1px solid #c8d5eb; background: #f8fbff; color: var(--awi-accent-dark); font-size: 14px; font-weight: 900; cursor: help; user-select: none; }',
				'.awi-help-trigger:focus { outline: none; box-shadow: 0 0 0 3px rgba(15, 111, 255, .14); }',
				'.awi-help-tooltip { position: absolute; top: calc(100% + 10px); right: 0; width: min(320px, 72vw); padding: 12px 14px; border-radius: 14px; background: #14213d; color: #fff; font-size: 12px; line-height: 1.5; box-shadow: 0 16px 32px rgba(15, 23, 42, .18); opacity: 0; visibility: hidden; transform: translateY(-6px); transition: opacity .16s ease, transform .16s ease, visibility .16s ease; z-index: 20; }',
				'.awi-help-tooltip::before { content: ""; position: absolute; top: -6px; right: 12px; width: 12px; height: 12px; background: #14213d; transform: rotate(45deg); }',
				'.awi-help-wrap:hover .awi-help-tooltip, .awi-help-wrap:focus-within .awi-help-tooltip { opacity: 1; visibility: visible; transform: translateY(0); }',
				'.awi-pass { margin-top: 14px; padding: 14px; border: 1px solid #d8e3f5; border-radius: 18px; background: linear-gradient(180deg, #f9fbff 0%, #eef5ff 100%); }',
				'.awi-pass-title { margin-bottom: 10px; font-weight: 900; color: var(--awi-text); }',
				'.awi-pass-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }',
				'.awi-pass code { display: inline-block; padding: 10px 12px; border-radius: 12px; background: #fff; border: 1px solid var(--awi-border); font-size: 13px; overflow-wrap: anywhere; }',
				'.awi-subsection { margin-top: 18px; }',
				'.awi-form-inline { display: grid; grid-template-columns: minmax(0, 1fr) 240px; gap: 14px; align-items: end; margin-top: 12px; }',
				'.awi-field-actions { min-width: 0; }',
				'.awi-btn-row { display: flex; gap: 10px; flex-wrap: wrap; }',
				'.awi-note-box { margin-top: 0; padding: 14px; border-radius: 16px; background: #f8fbff; border: 1px solid #d9e4f3; color: #274060; }',
				'.awi-note-box--status { display: grid; gap: 6px; }',
				'.awi-note-box--hidden { display: none; }',
				'.awi-note-box--status[data-tone="success"] { background: var(--awi-success-bg); border-color: #cfead8; color: var(--awi-success); }',
				'.awi-note-box--status[data-tone="warning"] { background: var(--awi-warning-bg); border-color: #f2de9f; color: var(--awi-warning); }',
				'.awi-note-box--status[data-tone="danger"] { background: var(--awi-danger-bg); border-color: #f7d1cc; color: var(--awi-danger); }',
				'.awi-status-pill { display: inline-flex; align-items: center; justify-content: center; min-height: 32px; padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 900; letter-spacing: .04em; text-transform: uppercase; }',
				'.awi-status-pill--neutral { color: #36517a; background: #eaf2ff; }',
				'.awi-status-pill--success { color: var(--awi-success); background: var(--awi-success-bg); }',
				'.awi-status-pill--warning { color: var(--awi-warning); background: var(--awi-warning-bg); }',
				'.awi-status-pill--danger { color: var(--awi-danger); background: var(--awi-danger-bg); }',
				'.awi-run-overview { display: grid; gap: 12px; }',
				'.awi-run-status-card { display: grid; gap: 10px; padding: 16px; border-radius: 18px; border: 1px solid #d8e3f5; background: linear-gradient(180deg, #f8fbff 0%, #eef5ff 100%); }',
				'.awi-run-status-meta { display: grid; gap: 6px; }',
				'.awi-run-status-value { font-size: 30px; line-height: 1.05; font-weight: 900; color: var(--awi-text); word-break: break-word; }',
				'.awi-run-status-text { margin: 0; color: var(--awi-muted); line-height: 1.45; }',
				'.awi-run-stats-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }',
				'.awi-stat { background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%); border: 1px solid var(--awi-border); border-radius: 18px; padding: 14px; }',
				'.awi-stat--compact .awi-stat-value { font-size: 24px; }',
				'.awi-stat-label { color: var(--awi-muted); font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; }',
				'.awi-stat-value { margin-top: 8px; font-size: 28px; line-height: 1; font-weight: 900; color: var(--awi-text); }',
				'.awi-progress { margin-top: 16px; width: 100%; height: 12px; background: #dfe9f8; border-radius: 999px; overflow: hidden; }',
				'.awi-progress-bar { width: 0; height: 100%; background: linear-gradient(90deg, var(--awi-accent) 0%, #34c3ff 100%); transition: width .25s ease; }',
				'.awi-muted { color: var(--awi-muted); }',
				'.awi-empty-state { color: var(--awi-muted); text-align: center; padding: 18px 10px; }',
				'.awi-table-wrap { width: 100%; overflow: hidden; }',
				'.awi-table-wrap--recent { overflow: visible; }',
				'.awi-table { width: 100%; min-width: 0; border-radius: 16px; overflow: hidden; border: 1px solid var(--awi-border); }',
				'.awi-table thead th { background: #f7faff; color: var(--awi-muted); font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }',
				'.awi-table td, .awi-table th { padding-top: 12px; padding-bottom: 12px; }',
				'.awi-run-table { table-layout: fixed; }',
				'.awi-run-table td, .awi-run-table th { overflow-wrap: anywhere; word-break: break-word; }',
				'.awi-failed-table td, .awi-run-table td { vertical-align: top; }',
				'@media (max-width: 1080px) { .awi-overview-grid, .awi-panel-grid, .awi-grid--tables, .awi-grid--import, .awi-hero, .awi-ai-summary { grid-template-columns: 1fr; } .awi-ai-summary-item { border-right: 0; border-bottom: 1px solid var(--awi-border); } .awi-ai-summary-item:last-child { border-bottom: 0; } .awi-hero--settings .awi-hero-side { justify-content: flex-start; } .awi-hero-actions { justify-content: flex-start; } }',
				'@media (max-width: 782px) { .awi-wrap.awi-shell { padding-right: 10px; } .awi-card { padding: 16px; } .awi-hero { padding: 18px; border-radius: 20px; } .awi-hero-copy h1 { font-size: 26px; } .awi-kv, .awi-form, .awi-form-inline, .awi-info-grid, .awi-run-stats-grid { grid-template-columns: 1fr; } .awi-k { padding-top: 0; } .awi-btn, .awi-copy, .awi-ghost-btn { width: 100%; } .awi-btn-row { display: grid; grid-template-columns: 1fr; } .awi-pass-row { align-items: stretch; } }',
			)
		);
	}

	// ── USAGE page ───────────────────────────────────────────────────────────

	public static function render_usage_page(): void {
		self::assert_access();

		global $wpdb;
		$table = $wpdb->prefix . 'awi_usage_log';

		if ( isset( $_POST['awi_clear_usage'] ) && check_admin_referer( 'awi_clear_usage_action', 'awi_clear_usage_nonce' ) ) {
			// $table is derived solely from $wpdb->prefix (not user input) so interpolation is safe.
			// TRUNCATE cannot be parameterised via $wpdb->prepare().
			$wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$model_totals = $wpdb->get_results(
			"SELECT model, provider,
				SUM(input_tokens)  AS total_input,
				SUM(output_tokens) AS total_output,
				SUM(cost_usd)      AS total_cost,
				COUNT(*)           AS calls
			FROM {$table}
			GROUP BY model, provider
			ORDER BY total_cost DESC",
			ARRAY_A
		) ?: array();

		$grand = $wpdb->get_row(
			"SELECT
				SUM(input_tokens)         AS input,
				SUM(output_tokens)        AS output,
				SUM(cost_usd)             AS cost,
				COUNT(DISTINCT product_id) AS products
			FROM {$table}",
			ARRAY_A
		) ?: array();

		$rows = $wpdb->get_results(
			"SELECT product_id, product_title, model, provider,
				SUM(input_tokens)  AS input_tok,
				SUM(output_tokens) AS output_tok,
				SUM(cost_usd)      AS cost,
				MAX(created_at)    AS last_run
			FROM {$table}
			GROUP BY product_id, model, provider
			ORDER BY last_run DESC
			LIMIT 200",
			ARRAY_A
		) ?: array();

		$fmt_cost = static function ( $usd ): string {
			$usd = (float) $usd;
			if ( $usd <= 0 )    return '$0.0000';
			if ( $usd >= 0.01 ) return '$' . number_format( $usd, 4 );
			return number_format( $usd * 100, 4 ) . '¢';
		};

		$total_products = (int) ( $grand['products'] ?? 0 );
		$total_input    = (int) ( $grand['input']    ?? 0 );
		$total_output   = (int) ( $grand['output']   ?? 0 );
		$total_cost     = $fmt_cost( $grand['cost'] ?? 0 );
		?>
		<div class="awi-wrap awi-shell">

			<div class="awi-hero awi-hero--import">
				<div class="awi-hero-copy">
					<h1>AI Usage</h1>
					<p>Exact token counts and costs per product import. Updates each time you run a URL import or manual import.</p>
				</div>
				<div class="awi-hero-side">
					<div class="awi-hero-actions">
						<a class="awi-ghost-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=atw' ) ); ?>">Settings</a>
					</div>
				</div>
			</div>

			<div class="awi-run-stats-grid" style="margin-top:12px;">
				<div class="awi-stat awi-stat--compact">
					<div class="awi-stat-label">Products Logged</div>
					<div class="awi-stat-value"><?php echo esc_html( number_format( $total_products ) ); ?></div>
				</div>
				<div class="awi-stat awi-stat--compact">
					<div class="awi-stat-label">Input Tokens</div>
					<div class="awi-stat-value"><?php echo esc_html( number_format( $total_input ) ); ?></div>
				</div>
				<div class="awi-stat awi-stat--compact">
					<div class="awi-stat-label">Output Tokens</div>
					<div class="awi-stat-value"><?php echo esc_html( number_format( $total_output ) ); ?></div>
				</div>
				<div class="awi-stat awi-stat--compact">
					<div class="awi-stat-label">Total Cost</div>
					<div class="awi-stat-value" style="color:#16a34a;"><?php echo esc_html( $total_cost ); ?></div>
				</div>
			</div>

			<div class="awi-grid awi-grid--tables" style="margin-top:12px;">

				<div class="awi-card awi-card--section">
					<div class="awi-card-head">
						<div>
							<h2>Per Model</h2>
							<p>Cumulative token spend and cost per AI model used.</p>
						</div>
					</div>
					<?php if ( empty( $model_totals ) ) : ?>
						<p class="awi-empty-state">No usage yet — import a product first.</p>
					<?php else : ?>
					<div class="awi-table-wrap">
						<table class="widefat striped awi-table">
							<thead>
								<tr>
									<th>Model</th>
									<th>Provider</th>
									<th style="text-align:right;">Input</th>
									<th style="text-align:right;">Output</th>
									<th style="text-align:right;">Calls</th>
									<th style="text-align:right;">Cost</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $model_totals as $mt ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $mt['model'] ); ?></strong></td>
									<td><span class="awi-status-pill awi-status-pill--neutral"><?php echo esc_html( $mt['provider'] ); ?></span></td>
									<td style="text-align:right;"><?php echo esc_html( number_format( (int) $mt['total_input'] ) ); ?></td>
									<td style="text-align:right;"><?php echo esc_html( number_format( (int) $mt['total_output'] ) ); ?></td>
									<td style="text-align:right;"><?php echo (int) $mt['calls']; ?></td>
									<td style="text-align:right;font-weight:800;color:#16a34a;"><?php echo esc_html( $fmt_cost( $mt['total_cost'] ) ); ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php endif; ?>
				</div>

				<div class="awi-card awi-card--section awi-card--recent">
					<div class="awi-card-head">
						<div>
							<h2>Per Product Log</h2>
							<p>Most recent 200 product imports with token and cost breakdown.</p>
						</div>
						<form method="post" style="margin:0;flex-shrink:0;">
							<?php wp_nonce_field( 'awi_clear_usage_action', 'awi_clear_usage_nonce' ); ?>
							<button type="submit" name="awi_clear_usage" value="1" class="awi-ghost-btn" onclick="return confirm('Clear all usage data? This cannot be undone.');">Clear Log</button>
						</form>
					</div>
					<?php if ( empty( $rows ) ) : ?>
						<p class="awi-empty-state">No product usage logged yet.</p>
					<?php else : ?>
					<div class="awi-table-wrap awi-table-wrap--recent">
						<table class="widefat striped awi-table awi-run-table">
							<thead>
								<tr>
									<th>Product</th>
									<th>Model</th>
									<th style="text-align:right;">In tok</th>
									<th style="text-align:right;">Out tok</th>
									<th style="text-align:right;">Cost</th>
									<th>Date</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td>
										<?php if ( (int) $row['product_id'] > 0 ) : ?>
											<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $row['product_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['product_title'] ?: 'Product #' . $row['product_id'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $row['product_title'] ?: '—' ); ?>
										<?php endif; ?>
									</td>
									<td><span class="awi-muted"><?php echo esc_html( $row['model'] ); ?></span></td>
									<td style="text-align:right;"><?php echo esc_html( number_format( (int) $row['input_tok'] ) ); ?></td>
									<td style="text-align:right;"><?php echo esc_html( number_format( (int) $row['output_tok'] ) ); ?></td>
									<td style="text-align:right;font-weight:800;color:#16a34a;"><?php echo esc_html( $fmt_cost( $row['cost'] ) ); ?></td>
									<td><span class="awi-muted"><?php echo esc_html( $row['last_run'] ); ?></span></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php endif; ?>
				</div>

			</div>
		</div>
		<?php
	}
}
