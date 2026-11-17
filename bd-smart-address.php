<?php
/**
 * Plugin Name:       BD Smart Address for WooCommerce
 * Description:       Smart checkout for Bangladesh: type the full address and District/Thana fill in automatically, a cleaned-up full address is suggested, and delivery charge is set automatically (Inside Dhaka city / Sub-urban Dhaka / Outside Dhaka). Clean mobile-first design. Optional AI fallback (OpenRouter free models, Gemini or Claude).
 * Version:           1.7.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Sajedul
 * License:           GPL-2.0-or-later
 * Text Domain:       bd-smart-address
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Another copy of this plugin (older version / different folder) is already active: do nothing instead of a fatal "cannot redeclare class".
if ( class_exists( 'BDSA_Plugin', false ) || defined( 'BDSA_VERSION' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p><b>BD Smart Address:</b> আরেকটি কপি আগে থেকেই চালু আছে। Plugins পেজে পুরোনো কপিটি Deactivate করে Delete করুন, তারপর এটি চালু করুন।</p></div>';
		}
	);
	return;
}

define( 'BDSA_VERSION', '1.7.3' );
define( 'BDSA_FILE', __FILE__ );
define( 'BDSA_DIR', plugin_dir_path( __FILE__ ) );
define( 'BDSA_URL', plugin_dir_url( __FILE__ ) );

class BDSA_Plugin {

	const OPTION = 'bdsa_settings';

	public static function defaults() {
		return array(
			// Checkout form.
			'enabled'          => 1,
			'show_box'         => 1,
			'ensure_fields'    => 1,
			'thana_dropdown'   => 1,
			'field_order'      => 1,
			'lean_checkout'    => 1,
			'validate_phone'   => 1,
			'bangla_labels'    => 1,
			'style'            => 1,
			'accent'           => '#c8102e',
			'min_chars'        => 12,
			'debounce'         => 900,
			// Delivery charge.
			'delivery_enabled' => 1,
			'fee_inside'       => 60,
			'fee_suburb'       => 100,
			'fee_outside'      => 120,
			'label_inside'     => 'ঢাকা সিটির ভেতরে ডেলিভারি',
			'label_suburb'     => 'সাব-আরবান ঢাকা সিটিতে ডেলিভারি',
			'label_outside'    => 'ঢাকার বাইরে ডেলিভারি',
			'extra_inside'     => '',
			'suburb_extra'     => 'Gazipur:Gazipur Sadar, Gazipur:Tongi, Gazipur:Basan, Gazipur:Gachha, Gazipur:Konabari, Narayanganj:Narayanganj Sadar, Narayanganj:Fatullah, Narayanganj:Siddhirganj, Narayanganj:Bandar',
			'dhaka_unknown'    => 'inside',
			'lock_delivery'    => 1,
			'delivery_note'    => 1,
			'respect_free'     => 1,
			'save_addresses'   => 1,
			'phone_required'   => 1,
			'email_optional'   => 1,
			'show_email'       => 1,
			'thana_optional'   => 1,
			// AI (optional).
			'ai_enabled'       => 0,
			'ai_provider'      => 'gemini',
			'gemini_key'       => '',
			'gemini_model'     => 'gemini-3.5-flash-lite',
			'openrouter_key'   => '',
			'openrouter_model' => 'openrouter/free',
			'api_key'          => '',
			'model'            => 'claude-haiku-5-5',
			'ai_when'          => 'fallback',
			'ai_daily_cap'     => 300,
			'ip_limit'         => 120,
			'ai_ip_limit'      => 12,
		);
	}

	public static function get( $key ) {
		static $cache = null;
		if ( null === $cache ) {
			$saved = get_option( self::OPTION, array() );
			$saved = is_array( $saved ) ? $saved : array();
			$cache = array_merge( self::defaults(), $saved );
			// Sites that set up a Claude key before Gemini was added keep using Claude.
			if ( ! isset( $saved['ai_provider'] ) && ! empty( $saved['api_key'] ) ) {
				$cache['ai_provider'] = 'claude';
			}
		}
		return isset( $cache[ $key ] ) ? $cache[ $key ] : null;
	}

	/**
	 * Remember the installed version and make WooCommerce forget cached shipping rates
	 * once after an update, so the automatic delivery charge shows straight away.
	 */
	public static function maybe_upgrade() {
		$prev = (string) get_option( 'bdsa_version', '' );
		if ( BDSA_VERSION === $prev ) {
			return;
		}
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
		update_option( 'bdsa_version', BDSA_VERSION );
	}

	public static function boot() {
		self::maybe_upgrade();

		// GitHub auto-updates (also runs on WP-Cron, so it is not limited to the admin).
		require_once BDSA_DIR . 'includes/class-bdsa-updater.php';
		BDSA_Updater::init();

		require_once BDSA_DIR . 'includes/class-bdsa-geo.php';
		require_once BDSA_DIR . 'includes/class-bdsa-ai.php';
		require_once BDSA_DIR . 'includes/class-bdsa-delivery.php';
		require_once BDSA_DIR . 'includes/class-bdsa-rest.php';
		require_once BDSA_DIR . 'includes/class-bdsa-checkout.php';
		require_once BDSA_DIR . 'includes/class-bdsa-order.php';
		require_once BDSA_DIR . 'includes/class-bdsa-admin.php';

		BDSA_REST::init();
		BDSA_Checkout::init();
		BDSA_Order::init();
		BDSA_Delivery::init();
		if ( is_admin() ) {
			BDSA_Admin::init();
		}
	}

	public static function activate() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
		update_option( 'bdsa_version', BDSA_VERSION );
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}
}

register_activation_hook( __FILE__, array( 'BDSA_Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'BDSA_Plugin', 'boot' ) );
