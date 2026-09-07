<?php
/**
 * Plugin Name: قیمت‌های صرافی یاران (Sarafi Yaran Rates)
 * Plugin URI:  https://www.sarafiyaran.com/
 * Description: دریافت و به‌روزرسانی 24 ساعته قیمت‌های طلا، سکه و دلار از سایت صرافی یاران با امکان تنظیم درصدی و افزایشی/کاهشی قیمت خرید و فروش و نمایش با شورت‌کد.
 * Version:     1.0.0
 * Author:      Ali Besharati
 * Text Domain: sarafi-yaran-rates
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'SYR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SYR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SYR_VERSION', '1.0.0' );

class Sarafi_Yaran_Rates_Plugin {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
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
		// Do initial fetch on activation
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
	 * Render full/filtered rates table or cards shortcode
	 * Usage: [sarafi_yaran_rates category="gold|coin|currency" layout="table|card"]
	 */
	public function render_rates_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'category' => '', // 'gold', 'coin', 'currency' or empty for all
			'layout'   => 'table', // 'table' or 'card'
			'unit'     => 'تومان',
		), $atts, 'sarafi_yaran_rates' );

		$rates = $this->get_adjusted_rates();

		if ( empty( $rates ) ) {
			return '<div class="syr-rates-wrapper"><p>قیمتی برای نمایش یافت نشد.</p></div>';
		}

		// Filter by category if specified
		if ( ! empty( $atts['category'] ) ) {
			$cat   = sanitize_key( $atts['category'] );
			$rates = array_filter( $rates, function( $item ) use ( $cat ) {
				return $item['category'] === $cat;
			} );
		}

		if ( empty( $rates ) ) {
			return '<div class="syr-rates-wrapper"><p>قیمتی در این دسته‌بندی یافت نشد.</p></div>';
		}

		ob_start();
		?>
		<div class="syr-rates-wrapper">
			<?php if ( 'card' === $atts['layout'] ) : ?>
				<div class="syr-cards-grid">
					<?php foreach ( $rates as $item ) : ?>
						<div class="syr-card-item">
							<div class="syr-card-title"><?php echo esc_html( $item['title'] ); ?></div>
							<div class="syr-card-row">
								<span>قیمت خرید:</span>
								<span class="syr-price-buy"><?php echo esc_html( $item['formatted_buy'] ); ?> <span class="syr-unit"><?php echo esc_html( $atts['unit'] ); ?></span></span>
							</div>
							<div class="syr-card-row">
								<span>قیمت فروش:</span>
								<span class="syr-price-sell"><?php echo esc_html( $item['formatted_sell'] ); ?> <span class="syr-unit"><?php echo esc_html( $atts['unit'] ); ?></span></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<table class="syr-rates-table">
					<thead>
						<tr>
							<th>عنوان</th>
							<th>قیمت خرید (<?php echo esc_html( $atts['unit'] ); ?>)</th>
							<th>قیمت فروش (<?php echo esc_html( $atts['unit'] ); ?>)</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rates as $item ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $item['title'] ); ?></strong></td>
								<td class="syr-price-buy"><?php echo esc_html( $item['formatted_buy'] ); ?></td>
								<td class="syr-price-sell"><?php echo esc_html( $item['formatted_sell'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<div class="syr-footer-note">منبع: صرافی یاران</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render single item rate shortcode
	 * Usage: [sarafi_yaran_rate item="دلار_آمریکا" type="sell|buy" unit="تومان"]
	 */
	public function render_single_rate_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'item' => 'usd', // Item key or title
			'type' => 'sell', // 'sell' or 'buy'
			'unit' => 'تومان',
		), $atts, 'sarafi_yaran_rate' );

		$rates   = $this->get_adjusted_rates();
		$target  = trim( $atts['item'] );
		$matched = null;

		foreach ( $rates as $key => $item ) {
			if ( $key === $target || $item['title'] === $target || mb_strpos( $item['title'], $target ) !== false || mb_strpos( $key, $target ) !== false ) {
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

	public function register_admin_menu() {
		add_menu_page(
			__( 'قیمت‌های صرافی یاران', 'sarafi-yaran-rates' ),
			__( 'صرافی یاران', 'sarafi-yaran-rates' ),
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

			$options['global_buy_adjustment_type']  = isset( $_POST['global_buy_adjustment_type'] ) && 'fixed' === $_POST['global_buy_adjustment_type'] ? 'fixed' : 'percent';
			$options['global_buy_adjustment_val']   = isset( $_POST['global_buy_adjustment_val'] ) ? floatval( $_POST['global_buy_adjustment_val'] ) : 0;
			$options['global_sell_adjustment_type'] = isset( $_POST['global_sell_adjustment_type'] ) && 'fixed' === $_POST['global_sell_adjustment_type'] ? 'fixed' : 'percent';
			$options['global_sell_adjustment_val']  = isset( $_POST['global_sell_adjustment_val'] ) ? floatval( $_POST['global_sell_adjustment_val'] ) : 0;

			$item_adjustments = array();
			if ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) {
				foreach ( $_POST['items'] as $item_key => $item_data ) {
					$item_adjustments[ sanitize_key( $item_key ) ] = array(
						'use_custom' => ! empty( $item_data['use_custom'] ),
						'buy_type'   => isset( $item_data['buy_type'] ) && 'fixed' === $item_data['buy_type'] ? 'fixed' : 'percent',
						'buy_val'    => isset( $item_data['buy_val'] ) ? floatval( $item_data['buy_val'] ) : 0,
						'sell_type'  => isset( $item_data['sell_type'] ) && 'fixed' === $item_data['sell_type'] ? 'fixed' : 'percent',
						'sell_val'   => isset( $item_data['sell_val'] ) ? floatval( $item_data['sell_val'] ) : 0,
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

		$options     = $this->get_options();
		$raw_rates   = get_option( 'sarafi_yaran_raw_rates', array() );
		$final_rates = $this->get_adjusted_rates();
		$status      = get_option( 'sarafi_yaran_last_status', array() );
		$last_time   = get_option( 'sarafi_yaran_last_success_time', false );

		?>
		<div class="wrap syr-admin-wrap" dir="rtl">
			<h1 class="wp-heading-inline">تنظیمات پلاگین قیمت‌های صرافی یاران</h1>
			<p class="description">این پلاگین به صورت ۲۴ ساعته و خودکار هر ۳۰ دقیقه قیمت‌های طلا، سکه و ارز را از سایت صرافی یاران بروزرسانی می‌کند.</p>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>تنظیمات با موفقیت ذخیره شد.</p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['refreshed'] ) ) : ?>
				<div class="notice notice-info is-dismissible"><p>بروزرسانی دستی قیمت‌ها انجام گردید.</p></div>
			<?php endif; ?>

			<!-- Status Card -->
			<div class="syr-card syr-status-card">
				<h2>وضعیت ارتباط و به روزرسانی</h2>
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
						<strong>زمان آخرین بروزرسانی موفق:</strong>
						<?php echo $last_time ? esc_html( date_i18n( 'Y/m/d - H:i:s', $last_time ) ) : 'هنوز انجام نشده'; ?>
					</div>
					<div>
						<strong>دوره به روزرسانی خودکار:</strong>
						<span>هر ۳۰ دقیقه (۲۴ ساعته)</span>
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

				<!-- Global Adjustments -->
				<div class="syr-card">
					<h2>تنظیمات عمومی قیمت‌ها (کلی)</h2>
					<p class="description">مقادیر تنظیم شده در این بخش بر روی تمام قیمت‌های دریافتی اعمال می‌شوند مگر اینکه برای یک آیتم به صورت اختصاصی تنظیم جداگانه تعریف کنید.</p>

					<table class="form-table">
						<tr>
							<th scope="row">تنظیم قیمت خرید (عمومی):</th>
							<td>
								<select name="global_buy_adjustment_type">
									<option value="percent" <?php selected( $options['global_buy_adjustment_type'], 'percent' ); ?>>درصدی (%)</option>
									<option value="fixed" <?php selected( $options['global_buy_adjustment_type'], 'fixed' ); ?>>مبلغ ثابت (تومان)</option>
								</select>
								<input type="number" step="any" name="global_buy_adjustment_val" value="<?php echo esc_attr( $options['global_buy_adjustment_val'] ); ?>" class="regular-text" style="width: 120px;">
								<p class="description">مثال: 5 یعنی ۵ درصد بالاتر، -3 یعنی ۳ درصد پایین‌تر.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">تنظیم قیمت فروش (عمومی):</th>
							<td>
								<select name="global_sell_adjustment_type">
									<option value="percent" <?php selected( $options['global_sell_adjustment_type'], 'percent' ); ?>>درصدی (%)</option>
									<option value="fixed" <?php selected( $options['global_sell_adjustment_type'], 'fixed' ); ?>>مبلغ ثابت (تومان)</option>
								</select>
								<input type="number" step="any" name="global_sell_adjustment_val" value="<?php echo esc_attr( $options['global_sell_adjustment_val'] ); ?>" class="regular-text" style="width: 120px;">
								<p class="description">مثال: 2 یعنی ۲ درصد بالاتر، -5 یعنی ۵ درصد پایین‌تر.</p>
							</td>
						</tr>
					</table>
				</div>

				<!-- Per-Item Adjustments -->
				<div class="syr-card">
					<h2>تنظیمات اختصاصی قیمت‌ها به تفکیک آیتم</h2>
					<p class="description">در این جدول تمام قیمت‌های دریافت شده از صرافی یاران نشان داده شده و می‌توانید برای هر کدام به تفکیک درصد یا مبلغ اختصاصی تعیین نمایید.</p>

					<?php if ( empty( $final_rates ) ) : ?>
						<p>هیچ قیمتی هنوز ثبت نشده است. دکمه «دریافت و به روزرسانی فوری قیمت‌ها» را فشار دهید.</p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th>عنوان آیتم</th>
									<th>قیمت اصلی صرافی (خرید / فروش)</th>
									<th>قیمت نهایی نمایش داده شده</th>
									<th>تنظیم اختصاصی</th>
									<th>تغییرات خرید</th>
									<th>تغییرات فروش</th>
								</tr>
							</thead>
							<tbody>
								<?php
								$item_opts = isset( $options['item_adjustments'] ) ? $options['item_adjustments'] : array();
								foreach ( $final_rates as $key => $item ) :
									$cur_opt    = isset( $item_opts[ $key ] ) ? $item_opts[ $key ] : array();
									$use_custom = ! empty( $cur_opt['use_custom'] );
									$b_type     = isset( $cur_opt['buy_type'] ) ? $cur_opt['buy_type'] : 'percent';
									$b_val      = isset( $cur_opt['buy_val'] ) ? $cur_opt['buy_val'] : 0;
									$s_type     = isset( $cur_opt['sell_type'] ) ? $cur_opt['sell_type'] : 'percent';
									$s_val      = isset( $cur_opt['sell_val'] ) ? $cur_opt['sell_val'] : 0;
									?>
									<tr>
										<td><strong><?php echo esc_html( $item['title'] ); ?></strong></td>
										<td>
											خرید: <?php echo esc_html( self::to_persian_num( number_format( $item['raw_buy'] ) ) ); ?><br>
											فروش: <?php echo esc_html( self::to_persian_num( number_format( $item['raw_sell'] ) ) ); ?>
										</td>
										<td>
											<strong style="color: #27ae60;">خرید: <?php echo esc_html( $item['formatted_buy'] ); ?></strong><br>
											<strong style="color: #c0392b;">فروش: <?php echo esc_html( $item['formatted_sell'] ); ?></strong>
										</td>
										<td>
											<label>
												<input type="checkbox" name="items[<?php echo esc_attr( $key ); ?>][use_custom]" value="1" <?php checked( $use_custom ); ?>>
												فعال‌سازی تنظیم اختصاصی
											</label>
										</td>
										<td>
											<select name="items[<?php echo esc_attr( $key ); ?>][buy_type]">
												<option value="percent" <?php selected( $b_type, 'percent' ); ?>>درصد (%)</option>
												<option value="fixed" <?php selected( $b_type, 'fixed' ); ?>>مبلغ ثابت</option>
											</select>
											<input type="number" step="any" name="items[<?php echo esc_attr( $key ); ?>][buy_val]" value="<?php echo esc_attr( $b_val ); ?>" style="width: 80px;">
										</td>
										<td>
											<select name="items[<?php echo esc_attr( $key ); ?>][sell_type]">
												<option value="percent" <?php selected( $s_type, 'percent' ); ?>>درصد (%)</option>
												<option value="fixed" <?php selected( $s_type, 'fixed' ); ?>>مبلغ ثابت</option>
											</select>
											<input type="number" step="any" name="items[<?php echo esc_attr( $key ); ?>][sell_val]" value="<?php echo esc_attr( $s_val ); ?>" style="width: 80px;">
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<!-- Shortcode Guide -->
				<div class="syr-card">
					<h2>راهنمای کدهای کوتاه (Shortcodes)</h2>
					<p>برای نمایش قیمت‌ها در صفحات یا نوشته‌های سایت می‌توانید از کدهای زیر استفاده کنید:</p>
					<ul>
						<li><code>[sarafi_yaran_rates]</code> - نمایش جدول یا کارت‌های کامل تمام قیمت‌ها</li>
						<li><code>[sarafi_yaran_rates category="gold"]</code> - نمایش فقط قیمت‌های طلا</li>
						<li><code>[sarafi_yaran_rates category="coin"]</code> - نمایش فقط قیمت‌های سکه</li>
						<li><code>[sarafi_yaran_rates category="currency"]</code> - نمایش فقط ارزها/دلار</li>
						<li><code>[sarafi_yaran_rate item="دلار_آمریکا" type="sell"]</code> - نمایش تک قیمت فروش دلار</li>
					</ul>
				</div>

				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>
		</div>
		<?php
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
	 * Convert English digits to Persian digits
	 */
	public static function to_persian_num( $str ) {
		$num     = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		return str_replace( $num, $persian, (string) $str );
	}

	/**
	 * Get default options
	 */
	public function get_default_options() {
		return array(
			'global_buy_adjustment_type'  => 'percent', // 'percent' or 'fixed'
			'global_buy_adjustment_val'   => 0,         // e.g. 5 for +5%, -3 for -3%
			'global_sell_adjustment_type' => 'percent',
			'global_sell_adjustment_val'  => 0,
			'item_adjustments'            => array(),   // Specific adjustments per key
			'auto_update_interval'        => 30,        // minutes
		);
	}

	/**
	 * Get stored plugin options
	 */
	public function get_options() {
		$defaults = $this->get_default_options();
		$options  = get_option( 'sarafi_yaran_settings', array() );
		return wp_parse_args( $options, $defaults );
	}

	/**
	 * Calculate adjusted price based on base price, adjustment type, and value
	 */
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
	 * Get processed final rates after applying global and per-item percentage/fixed adjustments
	 */
	public function get_adjusted_rates() {
		$raw_rates = get_option( 'sarafi_yaran_raw_rates', array() );
		$options   = $this->get_options();

		if ( empty( $raw_rates ) ) {
			return array();
		}

		$adjusted_rates   = array();
		$item_adjustments = isset( $options['item_adjustments'] ) ? $options['item_adjustments'] : array();

		foreach ( $raw_rates as $key => $item ) {
			$buy_type  = $options['global_buy_adjustment_type'];
			$buy_val   = $options['global_buy_adjustment_val'];
			$sell_type = $options['global_sell_adjustment_type'];
			$sell_val  = $options['global_sell_adjustment_val'];

			// Check for item specific override
			if ( isset( $item_adjustments[ $key ] ) ) {
				$item_opt = $item_adjustments[ $key ];
				if ( ! empty( $item_opt['use_custom'] ) ) {
					$buy_type  = isset( $item_opt['buy_type'] ) ? $item_opt['buy_type'] : $buy_type;
					$buy_val   = isset( $item_opt['buy_val'] ) ? $item_opt['buy_val'] : $buy_val;
					$sell_type = isset( $item_opt['sell_type'] ) ? $item_opt['sell_type'] : $sell_type;
					$sell_val  = isset( $item_opt['sell_val'] ) ? $item_opt['sell_val'] : $sell_val;
				}
			}

			$adj_buy  = $this->calculate_adjusted_price( $item['buy'], $buy_type, $buy_val );
			$adj_sell = $this->calculate_adjusted_price( $item['sell'], $sell_type, $sell_val );

			$adjusted_rates[ $key ] = array(
				'key'            => $item['key'],
				'title'          => $item['title'],
				'category'       => $item['category'],
				'raw_buy'        => $item['buy'],
				'raw_sell'       => $item['sell'],
				'buy'            => $adj_buy,
				'sell'           => $adj_sell,
				'formatted_buy'  => self::to_persian_num( number_format( $adj_buy ) ),
				'formatted_sell' => self::to_persian_num( number_format( $adj_sell ) ),
				'updated_at'     => $item['updated_at'],
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

		if ( empty( $rates ) ) {
			return array(
				'success' => false,
				'error'   => 'اطلاعات قیمتی در HTML صرافی یاران یافت نشد.',
				'rates'   => array(),
			);
		}

		return array(
			'success' => true,
			'error'   => '',
			'rates'   => $rates,
		);
	}

	/**
	 * Parse HTML body to extract items (Dollar, Gold, Coins, Currencies)
	 */
	public function parse_html_rates( $html ) {
		$rates = array();

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		@$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );

		$rows = $xpath->query( '//table//tr' );
		if ( $rows && $rows->length > 0 ) {
			foreach ( $rows as $row ) {
				$cols = $row->getElementsByTagName( 'td' );
				if ( $cols->length >= 3 ) {
					$title = trim( $cols->item( 0 )->textContent );
					$buy   = self::parse_number( $cols->item( 1 )->textContent );
					$sell  = self::parse_number( $cols->item( 2 )->textContent );

					if ( ! empty( $title ) && ( $buy > 0 || $sell > 0 ) ) {
						$key           = $this->generate_item_key( $title );
						$category      = $this->categorize_item( $title );
						$rates[ $key ] = array(
							'key'        => $key,
							'title'      => $title,
							'category'   => $category,
							'buy'        => $buy,
							'sell'       => $sell,
							'updated_at' => current_time( 'mysql' ),
						);
					}
				}
			}
		}

		if ( empty( $rates ) ) {
			$keywords = array(
				'usd'         => array( 'دلار', 'دلار آمریکا' ),
				'eur'         => array( 'یورو' ),
				'gbp'         => array( 'پوند' ),
				'aed'         => array( 'درهم' ),
				'gold_18'     => array( 'طلا ۱۸ عیار', 'طلا 18 عیار', 'طلای ۱۸ عیار', 'طلای 18 عیار' ),
				'gold_24'     => array( 'طلا ۲۴ عیار', 'طلا 24 عیار', 'طلای ۲۴ عیار', 'طلای 24 عیار' ),
				'coin_emami'  => array( 'سکه امامی', 'سکه تمام امامی' ),
				'coin_bahar'  => array( 'سکه بهار آزادی', 'سکه طرح قدیم' ),
				'coin_half'   => array( 'نیم سکه', 'نیم سکه بهار آزادی' ),
				'coin_quarter'=> array( 'ربع سکه', 'ربع سکه بهار آزادی' ),
				'coin_gram'   => array( 'سکه گرمی' ),
			);

			foreach ( $keywords as $key => $titles ) {
				foreach ( $titles as $title ) {
					$pattern = '/' . preg_quote( $title, '/' ) . '.*?([۰-۹0-9,]+).*?([۰-۹0-9,]+)/sui';
					if ( preg_match( $pattern, $html, $matches ) ) {
						$buy  = self::parse_number( $matches[1] );
						$sell = self::parse_number( $matches[2] );
						if ( $buy > 0 || $sell > 0 ) {
							$rates[ $key ] = array(
								'key'        => $key,
								'title'      => $title,
								'category'   => $this->categorize_item( $title ),
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

	public function categorize_item( $title ) {
		if ( mb_strpos( $title, 'طلا' ) !== false || mb_strpos( $title, 'مثقال' ) !== false || mb_strpos( $title, 'انس' ) !== false ) {
			return 'gold';
		}
		if ( mb_strpos( $title, 'سکه' ) !== false || mb_strpos( $title, 'ربع' ) !== false || mb_strpos( $title, 'نیم' ) !== false ) {
			return 'coin';
		}
		return 'currency';
	}

	public function generate_item_key( $title ) {
		$clean = preg_replace( '/[^\x{0600}-\x{06FF}a-zA-Z0-9]/u', '_', $title );
		$clean = trim( preg_replace( '/_+/', '_', $clean ), '_' );
		return ! empty( $clean ) ? strtolower( $clean ) : 'item_' . md5( $title );
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
}

Sarafi_Yaran_Rates_Plugin::get_instance();
