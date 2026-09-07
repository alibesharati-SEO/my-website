<?php
/**
 * Plugin Name: قیمت‌های صرافی یاران (Sarafi Yaran Rates)
 * Plugin URI:  https://www.sarafiyaran.com/
 * Description: دریافت و به‌روزرسانی 24 ساعته قیمت‌های طلا، سکه و دلار از سایت صرافی یاران با تنظیم مستقل درصد خرید و فروش برای ۵ آیتم اصلی و نمایش جدول اختصاصی فروشگاه رستمان.
 * Version:     1.1.0
 * Author:      Ali Besharati
 * Text Domain: sarafi-yaran-rates
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'SYR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SYR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SYR_VERSION', '1.1.0' );

class Sarafi_Yaran_Rates_Plugin {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * List of the 5 required primary items
	 */
	public static function get_target_items() {
		return array(
			'gold_18' => array(
				'key'      => 'gold_18',
				'title'    => 'طلای ۱۸ عیار گرمی',
				'category' => 'gold',
				'keywords' => array( 'طلا ۱۸', 'طلا 18', 'طلای ۱۸', 'طلای 18', '18 عیار', '۱۸ عیار' ),
			),
			'coin_emami' => array(
				'key'      => 'coin_emami',
				'title'    => 'سکه تمام بهار آزادی',
				'category' => 'coin',
				'keywords' => array( 'سکه تمام', 'سکه امامی', 'امامی', 'بهار آزادی' ),
			),
			'coin_half' => array(
				'key'      => 'coin_half',
				'title'    => 'نیم سکه بهار آزادی',
				'category' => 'coin',
				'keywords' => array( 'نیم سکه' ),
			),
			'coin_quarter' => array(
				'key'      => 'coin_quarter',
				'title'    => 'ربع سکه بهار آزادی',
				'category' => 'coin',
				'keywords' => array( 'ربع سکه' ),
			),
			'usd' => array(
				'key'      => 'usd',
				'title'    => 'دلار آمریکا',
				'category' => 'currency',
				'keywords' => array( 'دلار', 'USD' ),
			),
		);
	}

	private function __construct() {
		// Hook WP-Cron schedule & actions
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
		add_action( 'sarafi_yaran_cron_update_rates', array( $this, 'cron_update_rates' ) );

		// Register activation / deactivation hooks
		register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate_plugin' ) );

		// Admin menu & actions
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );

		// Frontend styles & shortcodes
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );
		add_shortcode( 'sarafi_yaran_rates', array( $this, 'render_rates_shortcode' ) );
		add_shortcode( 'sarafi_yaran_rate', array( $this, 'render_single_rate_shortcode' ) );
	}

	public function add_cron_interval( $schedules ) {
		$schedules['every_thirty_minutes'] = array(
			'interval' => 1800, // 30 minutes in seconds
			'display'  => __( 'هر ۳۰ دقیقه یکبار', 'sarafi-yaran-rates' ),
		);
		return $schedules;
	}

	public function activate_plugin() {
		if ( ! wp_next_scheduled( 'sarafi_yaran_cron_update_rates' ) ) {
			wp_schedule_event( time(), 'every_thirty_minutes', 'sarafi_yaran_cron_update_rates' );
		}
		// Initial fetch
		$this->fetch_and_store_rates();
	}

	public function deactivate_plugin() {
		$timestamp = wp_next_scheduled( 'sarafi_yaran_cron_update_rates' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'sarafi_yaran_cron_update_rates' );
		}
	}

	public function cron_update_rates() {
		$this->fetch_and_store_rates();
	}

	public function enqueue_frontend_styles() {
		wp_enqueue_style( 'syr-frontend-css', SYR_PLUGIN_URL . 'assets/frontend.css', array(), SYR_VERSION );
	}

	/**
	 * Convert English digits to Persian digits
	 */
	public static function to_persian_num( $str ) {
		$num     = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		return str_replace( $num, $persian, (string) $str );
	}

	/**
	 * Convert Persian/Arabic digits to English digits and clean price string
	 */
	public static function parse_number( $str ) {
		if ( null === $str || '' === trim( $str ) ) {
			return 0;
		}
		$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$arabic  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$num     = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );

		$str = str_replace( $persian, $num, $str );
		$str = str_replace( $arabic, $num, $str );

		$clean = preg_replace( '/[^0-9\.-]/', '', $str );
		return floatval( $clean );
	}

	/**
	 * Get default options
	 */
	public function get_default_options() {
		$target_items = self::get_target_items();
		$item_adjustments = array();

		foreach ( $target_items as $key => $item ) {
			$item_adjustments[ $key ] = array(
				'buy_type'  => 'percent',
				'buy_val'   => 0,
				'sell_type' => 'percent',
				'sell_val'  => 0,
			);
		}

		return array(
			'title'            => 'نرخ امروز فروشگاه رستمان',
			'item_adjustments' => $item_adjustments,
		);
	}

	public function get_options() {
		$defaults = $this->get_default_options();
		$options  = get_option( 'sarafi_yaran_settings', array() );

		if ( isset( $options['item_adjustments'] ) && is_array( $options['item_adjustments'] ) ) {
			$options['item_adjustments'] = wp_parse_args( $options['item_adjustments'], $defaults['item_adjustments'] );
		}

		return wp_parse_args( $options, $defaults );
	}

	public function calculate_adjusted_price( $base_price, $type, $val ) {
		$base_price = floatval( $base_price );
		$val        = floatval( $val );

		if ( $base_price <= 0 ) {
			return 0;
		}

		if ( 'percent' === $type ) {
			$adjusted = $base_price + ( $base_price * ( $val / 100 ) );
		} else {
			$adjusted = $base_price + $val;
		}

		return max( 0, round( $adjusted ) );
	}

	/**
	 * Get adjusted rates for the 5 target items
	 */
	public function get_adjusted_rates() {
		$raw_rates    = get_option( 'sarafi_yaran_raw_rates', array() );
		$options      = $this->get_options();
		$target_items = self::get_target_items();

		$adjusted_rates   = array();
		$item_adjustments = isset( $options['item_adjustments'] ) ? $options['item_adjustments'] : array();

		foreach ( $target_items as $key => $target ) {
			$item_raw = isset( $raw_rates[ $key ] ) ? $raw_rates[ $key ] : null;

			$raw_buy  = $item_raw ? $item_raw['buy'] : 0;
			$raw_sell = $item_raw ? $item_raw['sell'] : 0;
			$updated  = $item_raw ? $item_raw['updated_at'] : '';

			$adj_cfg   = isset( $item_adjustments[ $key ] ) ? $item_adjustments[ $key ] : array( 'buy_type' => 'percent', 'buy_val' => 0, 'sell_type' => 'percent', 'sell_val' => 0 );
			$buy_type  = isset( $adj_cfg['buy_type'] ) ? $adj_cfg['buy_type'] : 'percent';
			$buy_val   = isset( $adj_cfg['buy_val'] ) ? floatval( $adj_cfg['buy_val'] ) : 0;
			$sell_type = isset( $adj_cfg['sell_type'] ) ? $adj_cfg['sell_type'] : 'percent';
			$sell_val  = isset( $adj_cfg['sell_val'] ) ? floatval( $adj_cfg['sell_val'] ) : 0;

			$adj_buy  = $this->calculate_adjusted_price( $raw_buy, $buy_type, $buy_val );
			$adj_sell = $this->calculate_adjusted_price( $raw_sell, $sell_type, $sell_val );

			$adjusted_rates[ $key ] = array(
				'key'            => $key,
				'title'          => $target['title'],
				'category'       => $target['category'],
				'raw_buy'        => $raw_buy,
				'raw_sell'       => $raw_sell,
				'buy'            => $adj_buy,
				'sell'           => $adj_sell,
				'formatted_buy'  => self::to_persian_num( number_format( $adj_buy ) ),
				'formatted_sell' => self::to_persian_num( number_format( $adj_sell ) ),
				'updated_at'     => $updated,
			);
		}

		return $adjusted_rates;
	}

	/**
	 * Scrape rates from https://www.sarafiyaran.com/
	 */
	public function fetch_raw_rates() {
		$url = 'https://www.sarafiyaran.com/';

		$response = wp_remote_get( $url, array(
			'timeout'    => 25,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			'headers'    => array(
				'Accept-Language' => 'fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
				'rates'   => array(),
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return array(
				'success' => false,
				'error'   => 'پاسخ دریافتی از سایت صرافی یاران خالی است.',
				'rates'   => array(),
			);
		}

		$rates = $this->parse_html_rates( $body );

		return array(
			'success' => ! empty( $rates ),
			'error'   => empty( $rates ) ? 'اطلاعات ۵ آیتم مورد نظر در صرافی یاران یافت نشد.' : '',
			'rates'   => $rates,
		);
	}

	/**
	 * Parse HTML to find the 5 target items
	 */
	public function parse_html_rates( $html ) {
		$rates = array();
		$target_items = self::get_target_items();

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		@$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );
		$rows  = $xpath->query( '//table//tr' );

		if ( $rows && $rows->length > 0 ) {
			foreach ( $rows as $row ) {
				$cols = $row->getElementsByTagName( 'td' );
				if ( $cols->length >= 3 ) {
					$row_text = trim( $cols->item( 0 )->textContent );
					$buy      = self::parse_number( $cols->item( 1 )->textContent );
					$sell     = self::parse_number( $cols->item( 2 )->textContent );

					foreach ( $target_items as $item_key => $target ) {
						if ( isset( $rates[ $item_key ] ) ) {
							continue;
						}
						foreach ( $target['keywords'] as $kw ) {
							if ( mb_strpos( $row_text, $kw ) !== false ) {
								$rates[ $item_key ] = array(
									'key'        => $item_key,
									'title'      => $target['title'],
									'category'   => $target['category'],
									'buy'        => $buy,
									'sell'       => $sell,
									'updated_at' => current_time( 'mysql' ),
								);
								break;
							}
						}
					}
				}
			}
		}

		// Fallback regex matching if table parsing misses items
		foreach ( $target_items as $item_key => $target ) {
			if ( ! isset( $rates[ $item_key ] ) ) {
				foreach ( $target['keywords'] as $kw ) {
					$pattern = '/' . preg_quote( $kw, '/' ) . '.*?([۰-۹0-9,]+).*?([۰-۹0-9,]+)/sui';
					if ( preg_match( $pattern, $html, $matches ) ) {
						$buy  = self::parse_number( $matches[1] );
						$sell = self::parse_number( $matches[2] );
						if ( $buy > 0 || $sell > 0 ) {
							$rates[ $item_key ] = array(
								'key'        => $item_key,
								'title'      => $target['title'],
								'category'   => $target['category'],
								'buy'        => $buy,
								'sell'       => $sell,
								'updated_at' => current_time( 'mysql' ),
							);
							break;
						}
					}
				}
			}
		}

		return $rates;
	}

	public function fetch_and_store_rates() {
		$result = $this->fetch_raw_rates();
		$time   = current_time( 'timestamp' );

		if ( $result['success'] && ! empty( $result['rates'] ) ) {
			update_option( 'sarafi_yaran_raw_rates', $result['rates'] );
			update_option( 'sarafi_yaran_last_success_time', $time );
			update_option( 'sarafi_yaran_last_status', array(
				'success' => true,
				'message' => 'آخرین بروزرسانی با موفقیت انجام شد.',
				'time'    => $time,
			) );
		} else {
			update_option( 'sarafi_yaran_last_status', array(
				'success' => false,
				'message' => isset( $result['error'] ) ? $result['error'] : 'خطای نا مشخص در دریافت قیمت‌ها',
				'time'    => $time,
			) );
		}
	}

	public function register_admin_menu() {
		add_menu_page(
			__( 'نرخ فروشگاه رستمان', 'sarafi-yaran-rates' ),
			__( 'نرخ رستمان', 'sarafi-yaran-rates' ),
			'manage_options',
			'sarafi-yaran-rates',
			array( $this, 'render_admin_page' ),
			'dashicons-chart-line',
			30
		);
	}

	public function enqueue_admin_styles( $hook ) {
		if ( 'toplevel_page_sarafi-yaran-rates' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'syr-admin-css', SYR_PLUGIN_URL . 'assets/admin.css', array(), SYR_VERSION );
	}

	public function handle_admin_actions() {
		if ( ! isset( $_POST['syr_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'save_settings' === $_POST['syr_action'] ) {
			check_admin_referer( 'syr_save_settings_nonce' );

			$options = $this->get_options();
			$target_items = self::get_target_items();

			if ( isset( $_POST['title'] ) ) {
				$options['title'] = sanitize_text_field( $_POST['title'] );
			}

			$item_adjustments = array();
			if ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) {
				foreach ( $target_items as $key => $target ) {
					$item_data = isset( $_POST['items'][ $key ] ) ? $_POST['items'][ $key ] : array();
					$item_adjustments[ $key ] = array(
						'buy_type'  => isset( $item_data['buy_type'] ) && 'fixed' === $item_data['buy_type'] ? 'fixed' : 'percent',
						'buy_val'   => isset( $item_data['buy_val'] ) ? floatval( $item_data['buy_val'] ) : 0,
						'sell_type' => isset( $item_data['sell_type'] ) && 'fixed' === $item_data['sell_type'] ? 'fixed' : 'percent',
						'sell_val'  => isset( $item_data['sell_val'] ) ? floatval( $item_data['sell_val'] ) : 0,
					);
				}
			}
			$options['item_adjustments'] = $item_adjustments;

			update_option( 'sarafi_yaran_settings', $options );

			wp_safe_redirect( add_query_arg( array(
				'page'    => 'sarafi-yaran-rates',
				'updated' => '1',
			), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'refresh_rates' === $_POST['syr_action'] ) {
			check_admin_referer( 'syr_refresh_rates_nonce' );

			$this->fetch_and_store_rates();

			wp_safe_redirect( add_query_arg( array(
				'page'      => 'sarafi-yaran-rates',
				'refreshed' => '1',
			), admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options      = $this->get_options();
		$target_items = self::get_target_items();
		$final_rates  = $this->get_adjusted_rates();
		$status       = get_option( 'sarafi_yaran_last_status', array() );
		$last_time    = get_option( 'sarafi_yaran_last_success_time', false );

		?>
		<div class="wrap syr-admin-wrap" dir="rtl">
			<h1 class="wp-heading-inline">تنظیمات قیمت‌های فروشگاه رستمان</h1>
			<p class="description">در این بخش می‌توانید درصدها و مقادیر تغییر قیمت خرید و فروش ۵ آیتم اصلی را به صورت کاملاً مستقل تعیین کنید.</p>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>تنظیمات با موفقیت ذخیره شد.</p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['refreshed'] ) ) : ?>
				<div class="notice notice-info is-dismissible"><p>بروزرسانی دستی قیمت‌ها انجام گردید.</p></div>
			<?php endif; ?>

			<!-- Status Card -->
			<div class="syr-card syr-status-card">
				<h2>وضعیت ارتباط و به روزرسانی ۲۴ ساعته</h2>
				<div class="syr-status-grid">
					<div>
						<strong>آخرین وضعیت دریافت:</strong>
						<?php if ( ! empty( $status['success'] ) ) : ?>
							<span class="syr-badge syr-badge-success">موفق</span>
						<?php else : ?>
							<span class="syr-badge syr-badge-danger">خطا: <?php echo esc_html( isset( $status['message'] ) ? $status['message'] : 'نامشخص' ); ?></span>
						<?php endif; ?>
					</div>
					<div>
						<strong>زمان آخرین بروزرسانی:</strong>
						<?php echo $last_time ? esc_html( date_i18n( 'Y/m/d - H:i:s', $last_time ) ) : 'هنوز انجام نشده'; ?>
					</div>
					<div>
						<strong>دوره به روزرسانی:</strong>
						<span>هر ۳۰ دقیقه یکبار (خودکار)</span>
					</div>
				</div>

				<form method="post" style="margin-top: 15px;">
					<?php wp_nonce_field( 'syr_refresh_rates_nonce' ); ?>
					<input type="hidden" name="syr_action" value="refresh_rates">
					<button type="submit" class="button button-primary">دریافت و به روزرسانی فوری قیمت‌ها</button>
				</form>
			</div>

			<!-- Main Settings Form -->
			<form method="post" action="">
				<?php wp_nonce_field( 'syr_save_settings_nonce' ); ?>
				<input type="hidden" name="syr_action" value="save_settings">

				<div class="syr-card">
					<h2>عنوان جدول در سایت</h2>
					<input type="text" name="title" value="<?php echo esc_attr( $options['title'] ); ?>" class="regular-text" style="width:100%; max-width:400px;">
				</div>

				<!-- Per-Item Adjustments Table -->
				<div class="syr-card">
					<h2>تنظیمات اختصاصی قیمت خرید و فروش ۵ آیتم اصلی</h2>
					<p class="description">برای هر بخش می‌توانید نوع تغییر (درصدی یا مبلغ ثابت) و مقدار افزایش (+5) یا کاهش (-3) قیمت خرید و فروش را مستقلاً وارد نمایید.</p>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>عنوان آیتم</th>
								<th>قیمت اصلی صرافی (خرید / فروش)</th>
								<th>تنظیم قیمت خرید (مستقل)</th>
								<th>تنظیم قیمت فروش (مستقل)</th>
								<th>قیمت نهایی نمایش داده شده</th>
							</tr>
						</thead>
						<tbody>
							<?php
							$item_opts = isset( $options['item_adjustments'] ) ? $options['item_adjustments'] : array();
							foreach ( $target_items as $key => $target ) :
								$item    = isset( $final_rates[ $key ] ) ? $final_rates[ $key ] : null;
								$cur_opt = isset( $item_opts[ $key ] ) ? $item_opts[ $key ] : array();
								$b_type  = isset( $cur_opt['buy_type'] ) ? $cur_opt['buy_type'] : 'percent';
								$b_val   = isset( $cur_opt['buy_val'] ) ? $cur_opt['buy_val'] : 0;
								$s_type  = isset( $cur_opt['sell_type'] ) ? $cur_opt['sell_type'] : 'percent';
								$s_val   = isset( $cur_opt['sell_val'] ) ? $cur_opt['sell_val'] : 0;
								?>
								<tr>
									<td><strong><?php echo esc_html( $target['title'] ); ?></strong></td>
									<td>
										خرید: <?php echo esc_html( self::to_persian_num( number_format( $item ? $item['raw_buy'] : 0 ) ) ); ?> تومان<br>
										فروش: <?php echo esc_html( self::to_persian_num( number_format( $item ? $item['raw_sell'] : 0 ) ) ); ?> تومان
									</td>
									<td>
										<select name="items[<?php echo esc_attr( $key ); ?>][buy_type]">
											<option value="percent" <?php selected( $b_type, 'percent' ); ?>>درصد (%)</option>
											<option value="fixed" <?php selected( $b_type, 'fixed' ); ?>>مبلغ ثابت (تومان)</option>
										</select>
										<input type="number" step="any" name="items[<?php echo esc_attr( $key ); ?>][buy_val]" value="<?php echo esc_attr( $b_val ); ?>" style="width: 90px;" placeholder="0">
									</td>
									<td>
										<select name="items[<?php echo esc_attr( $key ); ?>][sell_type]">
											<option value="percent" <?php selected( $s_type, 'percent' ); ?>>درصد (%)</option>
											<option value="fixed" <?php selected( $s_type, 'fixed' ); ?>>مبلغ ثابت (تومان)</option>
										</select>
										<input type="number" step="any" name="items[<?php echo esc_attr( $key ); ?>][sell_val]" value="<?php echo esc_attr( $s_val ); ?>" style="width: 90px;" placeholder="0">
									</td>
									<td>
										<strong style="color: #27ae60;">خرید: <?php echo esc_html( $item ? $item['formatted_buy'] : '۰' ); ?> تومان</strong><br>
										<strong style="color: #c0392b;">فروش: <?php echo esc_html( $item ? $item['formatted_sell'] : '۰' ); ?> تومان</strong>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<!-- Shortcode Guide -->
				<div class="syr-card">
					<h2>راهنمای شورت‌کد نمایش جدول در برگه یا نوشته</h2>
					<p>برای قرار دادن جدول در سایت کافیست کدهای زیر را قرار دهید:</p>
					<ul>
						<li><code>[sarafi_yaran_rates]</code> - نمایش جدول شیک نرخ فروشگاه رستمان</li>
						<li><code>[sarafi_yaran_rate item="gold_18" type="sell"]</code> - تک قیمت فروش طلای ۱۸ عیار</li>
						<li><code>[sarafi_yaran_rate item="usd" type="buy"]</code> - تک قیمت خرید دلار</li>
					</ul>
				</div>

				<?php submit_button( 'ذخیره تغییرات' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render full/filtered rates table shortcode
	 */
	public function render_rates_shortcode( $atts ) {
		$options = $this->get_options();
		$rates   = $this->get_adjusted_rates();
		$last_time = get_option( 'sarafi_yaran_last_success_time', false );

		$title_text = ! empty( $options['title'] ) ? $options['title'] : 'نرخ امروز فروشگاه رستمان';

		ob_start();
		?>
		<div class="syr-rates-wrapper">
			<div class="syr-table-container">
				<div class="syr-table-header">
					<h3 class="syr-table-title"><?php echo esc_html( $title_text ); ?></h3>
				</div>
				<table class="syr-rates-table">
					<thead>
						<tr>
							<th>عنوان</th>
							<th>قیمت خرید (تومان)</th>
							<th>قیمت فروش (تومان)</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rates as $item ) : ?>
							<tr>
								<td data-label="عنوان"><strong><?php echo esc_html( $item['title'] ); ?></strong></td>
								<td data-label="قیمت خرید" class="syr-price-buy"><?php echo esc_html( $item['formatted_buy'] ); ?></td>
								<td data-label="قیمت فروش" class="syr-price-sell"><?php echo esc_html( $item['formatted_sell'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div class="syr-footer-note">
					آخرین به‌روزرسانی: <?php echo $last_time ? esc_html( self::to_persian_num( date_i18n( 'Y/m/d - H:i:s', $last_time ) ) ) : 'به‌روزرسانی نشده'; ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render single rate shortcode
	 */
	public function render_single_rate_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'item' => 'usd',
			'type' => 'sell',
			'unit' => 'تومان',
		), $atts, 'sarafi_yaran_rate' );

		$rates   = $this->get_adjusted_rates();
		$target  = trim( $atts['item'] );
		$matched = null;

		foreach ( $rates as $key => $item ) {
			if ( $key === $target || $item['title'] === $target || mb_strpos( $item['title'], $target ) !== false ) {
				$matched = $item;
				break;
			}
		}

		if ( ! $matched ) {
			return '-';
		}

		$val = 'buy' === $atts['type'] ? $matched['formatted_buy'] : $matched['formatted_sell'];
		return '<span class="syr-single-price">' . esc_html( $val ) . ' ' . esc_html( $atts['unit'] ) . '</span>';
	}
}

Sarafi_Yaran_Rates_Plugin::get_instance();
