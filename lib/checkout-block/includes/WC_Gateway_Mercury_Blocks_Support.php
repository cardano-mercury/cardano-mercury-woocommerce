<?php

namespace WoocommerceGatewayCardanoMercury;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Exception;
use Mercury\Fixer;
use Mercury\Pricefeeder;

class WC_Gateway_Mercury_Blocks_Support extends AbstractPaymentMethodType {

	private $gateway;

	protected $name = GATEWAY_ID;

	public function initialize() {
		$this->log      = wc_get_logger();
		$this->settings = get_option(SETTINGS_ID);
	}

	public function is_active() {
		return true;
	}

	public function get_payment_method_script_handles() {

		$asset_path   = plugin_dir_path(__DIR__) . 'build/index.asset.php';
		$version      = null;
		$dependencies = [];
		if (file_exists($asset_path)) {
			$asset        = require $asset_path;
			$version      = isset($asset['version']) ? $asset['version'] : $version;
			$dependencies = isset($asset['dependencies']) ? $asset['dependencies'] : $dependencies;
		}

		wp_register_script('wc-gateway-mercury-blocks-integration', plugin_dir_url(__DIR__) . 'build/index.js',
			$dependencies, $version, // or time() or filemtime( ... ) to skip caching
			true);

		return ['wc-gateway-mercury-blocks-integration'];

	}

	protected function get_usd(string $fromCurr, int $interval = 300): float {

		$currency_pair = "Mercury_{$fromCurr}_USD_Price";

		$rate = get_transient($currency_pair);

		if (!$rate) {
			$apiKey = $this->get_setting('fixerioAPIKey');
			$this->log->info("Fixer API Key: {$apiKey}", processing_log);
			$Fixer = new Fixer($apiKey);
			try {
				$result = $Fixer->convert($fromCurr);
			} catch (Exception $e) {
				$this->log->error("Encountered an exception while fetching price data! " . wc_print_r($e,
						true) . "\r\n" . wc_print_r($this, true), processing_log);

				return false;
			}

			$rate = $result->data->rates->USD;

			if (!$rate) {
				$this->log->error("Count not find the rate for currency conversion? " . wc_print_r($result, true));

				return false;
			}

			set_transient($currency_pair, $rate, $interval);
		}

		return $rate;

//            return round($total * $rate, 2);

	}

	public function get_payment_method_data() {
		$curr          = get_woocommerce_currency();
		$ADAPrice      = Pricefeeder::getAveragePrice()['price'];
		$exchange_rate = 1;
		switch ($curr) {
			case 'ADA':
			case 'USD':
				break;
			default:
				$exchange_rate = $this->get_usd($curr, $this->get_setting('currencyConversionCache'));
				if (!$exchange_rate) {
					throw new Exception("Could not find exchange rate!");
				}
		}
		$accepted_native_assets = get_posts([
			'numberposts' => -1,
			'post_type'   => 'mercury-native-asset',
			'meta_key'    => 'accepted',
			'meta_value'  => 1,
		]);

		$currencies = [
			[
				'name'     => "ADA &#8371;",
				'unit'     => 'ada',
				'decimals' => 6,
				'price'    => $ADAPrice,
			],
		];

		if (count($accepted_native_assets)) {
			foreach ($accepted_native_assets as $token) {
				$currency_price = get_post_meta($token->ID, 'price', true);
				if ($currency_price <= 0) {
					continue;
				}

				$ada_offset = (1168010 + ((strlen($token->post_title) - 56) * 4310)) / 1000000;

				$currencies[] = [
					'name'        => '$' . get_post_meta($token->ID, 'ticker', true),
					'unit'        => $token->post_title,
					'decimals'    => (int)get_post_meta($token->ID, 'decimals', true),
					'minUTxO'     => $ada_offset,
					'perAdaPrice' => (float)$currency_price,
				];
			}
		}

		return [
			'title'           => $this->get_setting('title'),
			'description'     => $this->get_setting('description'),
			'exchange_rate'   => $exchange_rate,
			'ada_price'       => $ADAPrice,
			'currencies'      => $currencies,
			'processLabel'    => $this->get_setting('processFeeLabel'),
			'processFlat'     => (float)$this->get_setting('processFeeFlat'),
			'processVariable' => (float)$this->get_setting('processFeeVariable'),
		];
	}
}
