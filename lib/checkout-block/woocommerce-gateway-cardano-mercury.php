<?php
/**
 * Plugin Name: Woocommerce Gateway Cardano Mercury
 * Version: 0.1.0
 * Author: Adam Dean
 * Author URI: https://cardano-mercury.com
 * Text Domain: woocommerce-gateway-cardano-mercury
 * Domain Path: /languages
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package extension
 */

defined('ABSPATH') || exit;

if (!defined('MAIN_PLUGIN_FILE')) {
	define('MAIN_PLUGIN_FILE', __FILE__);
}

require_once plugin_dir_path(__FILE__) . 'vendor/autoload_packages.php';

use WoocommerceGatewayCardanoMercury\WC_Gateway_Mercury_Blocks_Support;

// phpcs:disable WordPress.Files.FileName

//const GATEWAY_ID  = "cardano_mercury";
//const SETTINGS_ID = "woocommerce_cardano_mercury_settings";



register_activation_hook(__FILE__, 'woocommerce_gateway_cardano_mercury_activate');

/**
 * Activation hook.
 *
 * @since 0.1.0
 */
function woocommerce_gateway_cardano_mercury_activate() {
	if (!class_exists('WooCommerce')) {
		add_action('admin_notices', 'woocommerce_gateway_cardano_mercury_missing_wc_notice');

		return;
	}
}

/**
 * WooCommerce fallback notice.
 *
 * @since 0.1.0
 */
function woocommerce_gateway_cardano_mercury_missing_wc_notice() {
	/* translators: %s WC download URL link. */
	echo '<div class="error"><p><strong>' . sprintf(esc_html__('Woocommerce Gateway Cardano Mercury requires WooCommerce to be installed and active. You can download %s here.',
			'woocommerce_gateway_cardano_mercury'),
			'<a href="https://woo.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
}

if (!class_exists('woocommerce_gateway_cardano_mercury')) :
	/**
	 * The woocommerce_gateway_cardano_mercury class.
	 */
	class woocommerce_gateway_cardano_mercury {

		/**
		 * This class instance.
		 *
		 * @var \woocommerce_gateway_cardano_mercury single instance of this class.
		 */
		private static $instance;

		/**
		 * Constructor.
		 */
		public function __construct() {
//			if ( is_admin() ) {
//				new Setup();
//			}
			add_action('woocommerce_blocks_loaded', [
				__CLASS__,
				'declare_block_support',
			]);
		}

		public static function declare_block_support() {
			require_once __DIR__ . '/includes/WC_Gateway_Mercury_Blocks_Support.php';
			add_action('woocommerce_blocks_payment_method_type_registration',
				static function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $paymentMethodRegistry) {
					$paymentMethodRegistry->register(new WC_Gateway_Mercury_Blocks_Support);
				});
		}

		/**
		 * Cloning is forbidden.
		 */
		public function __clone() {
			wc_doing_it_wrong(__FUNCTION__, __('Cloning is forbidden.', 'woocommerce_gateway_cardano_mercury'),
				$this->version);
		}

		/**
		 * Unserializing instances of this class is forbidden.
		 */
		public function __wakeup() {
			wc_doing_it_wrong(__FUNCTION__,
				__('Unserializing instances of this class is forbidden.', 'woocommerce_gateway_cardano_mercury'),
				$this->version);
		}

		/**
		 * Gets the main instance.
		 * Ensures only one instance can be loaded.
		 *
		 * @return \woocommerce_gateway_cardano_mercury
		 */
		public static function instance() {

			if (null === self::$instance) {
				self::$instance = new self();
			}

			return self::$instance;
		}
	}
endif;

add_action('plugins_loaded', 'woocommerce_gateway_cardano_mercury_init', 10);

/**
 * Initialize the plugin.
 *
 * @since 0.1.0
 */
function woocommerce_gateway_cardano_mercury_init() {
	load_plugin_textdomain('woocommerce_gateway_cardano_mercury', false,
		plugin_basename(dirname(__FILE__)) . '/languages');

	if (!class_exists('WooCommerce')) {
		add_action('admin_notices', 'woocommerce_gateway_cardano_mercury_missing_wc_notice');

		return;
	}

	woocommerce_gateway_cardano_mercury::instance();

}

if (function_exists('woocommerce_store_api_register_update_callback')) {
	woocommerce_store_api_register_update_callback([
		'namespace' => 'cardano-mercury',
		'callback'  => 'cardano_mercury_update_payment_method',
	]);
}

function cardano_mercury_update_payment_method($data) {
	if (isset($data['payment_method'])) {
		WC()->session->set('chosen_payment_method', $data['payment_method']);
	}

	WC()->cart->calculate_totals();
}
