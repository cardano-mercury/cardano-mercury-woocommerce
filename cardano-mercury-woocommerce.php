<?php

/*
Plugin Name: Cardano Mercury Woocommerce
Plugin URI: https://crypto2099.io/mercury-woocommerce
Description: WooCommerce Cardano (ADA) Currency Plugin
Version: 2.0
Author: Adam Dean <Adam@crypto2099.io>
Author URI: https://crypto2099.io
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

if (!defined('ABSPATH')) {
    die();
}

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    error_log("WooCommerce is not defined yet?");

    return;
}

use Mercury\Fixer;
use Mercury\NativeAsset;
use Mercury\Pricefeeder;
use Mercury\Transaction;

require_once 'vendor/autoload.php';
require_once dirname(__FILE__) . '/lib/action-scheduler/action-scheduler.php';
require_once dirname(__FILE__) . '/mercury_cron.php';
//include_once dirname(__FILE__) . '/mercury-transaction.php';
require_once dirname(__FILE__) . '/mercury-asset.php';

// Scaffold out actions for the gateway
add_action('plugins_loaded', 'scaffold_mercury', 1);

register_activation_hook(__FILE__, 'mercury_activate');
register_deactivation_hook(__FILE__, 'mercury_deactivate');
register_uninstall_hook(__FILE__, 'mercury_uninstall');
add_filter('woocommerce_payment_gateways', "add_cardano_mercury");

// add_action('init', 'setup_mercury_transaction_post_type');

add_action('init', [
        Transaction::class,
        'init',
]);

add_action('init', [
        NativeAsset::class,
        'init',
]);

add_action('mercury_sync_assets', [
        NativeAsset::class,
        'sync',
]);

add_action('woocommerce_cart_calculate_fees', [
        WC_Gateway_Cardano_Mercury::class,
        'maybe_add_fees',
], 20);

define('mercury_url', plugin_dir_url(__FILE__));

const mercury_txn_singular = 'Transaction';
const mercury_txn_plural   = 'Transactions';
const mercury_text_domain  = 'cardano-mercury';
const price_table_name     = 'mercury_order_values';
const order_payment_table  = 'mercury_order_payments';
const setup_log            = ['source' => 'mercury_setup'];
const cron_log             = ['source' => 'mercury_cron'];
const processing_log       = ['source' => 'mercury_processing'];

//function cart_custom_fee($cart) {
//    $log = wc_get_logger();
//    $log->debug("Cart Custom Fee called!", processing_log);
//
//    $post_data = [];
//    if (isset($_REQUEST['post_data'])) {
//        parse_str($_REQUEST['post_data'], $post_data);
//    }
//
//    $session = WC()->session;
//
//    if (!empty($post_data)) {
////        $log->debug("POST Data:\r\n" . print_r($post_data, true), processing_log);
//        $session->set('cardano_currency', $post_data['cardano_currency']);
//    } else {
////        $log->debug("Cart Data:\r\n" . print_r($cart, true), processing_log);
////        $log->debug("Session Data:\r\n" . print_r($session, true), processing_log);
//    }
//
//    $payment_method   = $post_data['payment_method'] ?? $session->get('chosen_payment_method');
//    $cardano_currency = $post_data['cardano_currency'] ?? $session->get('cardano_currency');
//
//    $log->debug("Payment Method: {$payment_method}", processing_log);
//    $log->debug("Cardano Currency: {$cardano_currency}", processing_log);
//
//    if ($payment_method === 'cardano_mercury') {
//        $cart->add_fee("Mercury Processing Fee", 1);
//    }
//}

function lovelace_to_ADA($lovelace) {
    return $lovelace / 1000000;
}

function ADA_to_lovelace($ADA) {
    return $ADA * 1000000;
}

function scaffold_mercury() {

    global $wpdb;

    $log = wc_get_logger();

    $mercury_settings = get_option('woocommerce_cardano_mercury_settings');

    $wpdb->actionscheduler_actions = $wpdb->prefix . 'actionscheduler_actions';
    $wpdb->actionscheduler_groups  = $wpdb->prefix . 'actionscheduler_groups';

    action_scheduler_initialize_3_dot_4_dot_0();

    if (class_exists('Action_scheduler') && Action_Scheduler::is_initialized()) {
        $next_hook = as_has_scheduled_action('mercury_cron_hook', [], 'cardano-mercury');

        if (!$next_hook && $mercury_settings['cronFrequency'] > 0) {
            $log->info("Next hook not scheduled? What is cron frequency? {$mercury_settings['cronFrequency']}s",
                    setup_log);
            as_schedule_recurring_action(time(), $mercury_settings['cronFrequency'], 'mercury_cron_hook', [],
                    'cardano-mercury');
        }
    }

    /**
     * ADA Currency Symbol
     */
    add_filter('woocommerce_currencies', 'addCardano');

    function addCardano($currencies) {
        $currencies['ADA'] = __('Cardano (ADA)', 'woocommerce');

        return $currencies;
    }

    add_filter('woocommerce_currency_symbol', 'addCardanoSymbol', 10, 2);

    function addCardanoSymbol($currency_symbol, $currency) {
        if ($currency == 'ADA') {
            $currency_symbol = '₳';
        }

        return $currency_symbol;
    }

    // Set up the Mercury payment gateway
    class WC_Gateway_Cardano_Mercury extends WC_Payment_Gateway {

        const gateway_id = "cardano_mercury";

        private $prices_table, $log;
        public $blockfrostAPIKey, $walletAddress, $currencyConverterAPI;

        const options_key = 'woocommerce_cardano_mercury_settings';
        /**
         * @var string
         */
        protected $fixerioAPIKey;
        /**
         * @var string
         */
        protected $orderTimeout;
        /**
         * @var string
         */
        private $currencyConversionCache;

        public function __construct() {
            global $wpdb;

            $this->log                  = wc_get_logger();
            $this->supports             = [
                    'products',
                    'refunds',
            ];
            $this->view_transaction_url = 'https://cardanoscan.io/transaction/%s';
            $this->prices_table         = $wpdb->prefix . price_table_name;
            $this->id                   = self::gateway_id;
            $this->icon                 = apply_filters("cardano_mercury_icon", "");
            $this->has_fields           = true;
            $this->method_title         = _x("Cardano Mercury", "Cardano Mercury payment method", "woocommerce");
            $this->method_description   = __("Accept Cardano (ADA) payments!", "woocommerce");

            $this->init_form_fields();
            $this->init_settings();

            $this->title                   = $this->get_option('title');
            $this->description             = $this->get_option('description');
            $this->blockfrostAPIKey        = $this->get_option('blockfrostAPIKey');
            $this->walletAddress           = $this->get_option('walletAddress');
            $this->currencyConverterAPI    = $this->get_option('currencyConverterAPI');
            $this->fixerioAPIKey           = $this->get_option('fixerioAPIKey');
            $this->currencyConversionCache = $this->get_option('currencyConversionCache');
            $this->orderTimeout            = $this->get_option('orderTimeout');
            $this->taptoolsAPIKey          = $this->get_option('taptoolsAPIKey');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [
                    $this,
                    'process_admin_options',
            ]);
            add_action("woocommerce_thankyou_" . $this->id, [
                    $this,
                    'thankyou_page',
            ]);

            add_action("woocommerce_cart_calculate_fees", [
                    $this,
                    'maybe_add_fees',
            ]);
//
//            add_action("woocommerce_calculate_totals", [
//                    $this,
//                    'check_totals',
//            ]);

            add_action('woocommerce_after_checkout_form', [
                    $this,
                    'inject_checkout_scripts',
            ]);
        }

        public function inject_checkout_scripts() {
            wc_enqueue_js("
                $('form.checkout').on('change', 'input[name^=\'payment_method\']', function() {
                    $('body').trigger('update_checkout');
                });
                $('form.checkout').on('change','select[name^=\'cardano_currency\']', () => {
                    $('body').trigger('update_checkout');
                });
            ");
        }

        public function check_totals($cart) {
            $this->log->debug("Checking totals...\r\n" . print_r($cart, true), processing_log);
        }

        public static function maybe_add_fees($cart) {

//            $log = wc_get_logger();

            $options = get_option(self::options_key);

            $post_data = [];
            if (isset($_REQUEST['post_data'])) {
                parse_str($_REQUEST['post_data'], $post_data);
            }

            $session = WC()->session;

            if (!empty($post_data)) {
                $session->set('cardano_currency', $post_data['cardano_currency']);
            }

            $chosen_gateway   = $post_data['payment_method'] ?? $session->get('chosen_payment_method');
            $cardano_currency = $post_data['cardano_currency'] ?? $session->get('cardano_currency');

            $order_subtotal = $cart->cart_contents_total + $cart->shipping_total;
//            $log->debug("Order Subtotal: {$order_subtotal}");
            // Add a 3% service charge

            $fee_label    = $options['processFeeLabel'];
            $flat_fee     = 0;
            $variable_fee = 0;
            $fee          = 0;

            if ($options['processFeeFlat']) {
                $flat_fee = $options['processFeeFlat'];
            }

            if ($options['processFeeVariable']) {
                $variable_fee_rate = $options['processFeeVariable'];
                $variable_fee      = $variable_fee_rate * $order_subtotal;
            }

            if ($flat_fee != 0 && $variable_fee != 0) {
                $fee = max($flat_fee, $variable_fee);
            } elseif ($flat_fee != 0) {
                $fee = $flat_fee;
            } elseif ($variable_fee != 0) {
                $fee = $variable_fee;
            }
//            $fee      = $fee_rate * $order_subtotal;
//            $pretty_fee = round($fee_rate * 100);

//            $log->debug("Chosen Gateway: {$chosen_gateway}\r\nCardano Currency: {$cardano_currency}", processing_log);
            if ($fee !== 0 && $chosen_gateway === self::gateway_id) {
                $cart->add_fee($fee_label, $fee);
            } else {
//                $cart->remove_fee($fee_label);
            }
        }

        public function get_options() {
            return get_option(self::options_key);
        }

        public function needs_setup(): bool {
            $options = $this->get_options();
            $log     = wc_get_logger();

            if (empty($options['blockfrostAPIKey'])) {
                $log->error("Blockfrost API key is not defined!", setup_log);

                return true;
            }

            if (empty($options['walletAddress'])) {
                $log->error("Wallet address is not defined!", setup_log);

                return true;
            }

            if (!in_array(get_woocommerce_currency(), [
                    'ADA',
                    'USD',
            ])) {
                if (empty($this->fixerioAPIKey)) {
                    return true;
                }
            }

            return false;
        }

        public function payment_fields() {
            parent::payment_fields();
            $cart_data = WC()->cart;

            $cart_total = $cart_data->get_totals()['total'];
            $curr       = get_woocommerce_currency();

            $ADAPrice = Pricefeeder::getAveragePrice()['price'];

            $post_data = [];
            if (isset($_REQUEST['post_data'])) {
                parse_str($_REQUEST['post_data'], $post_data);
            }

            $selected_currency = $post_data['cardano_currency'] ?? WC()->session->get('cardano_currency');

            $ada_total = 0;
            $usd_total = 0;

            switch ($curr) {
                case 'ADA':
                    $ada_total = $cart_total;
                    break;
                case 'USD':
                    $usd_total = $cart_total;
                    break;
                default:
                    $exchange_rate = $this->get_usd($curr, $this->currencyConversionCache);
                    if (!$exchange_rate) {
                        throw new Exception("Could not find exchange rate!");
                    }
                    $usd_total = round($cart_total * $exchange_rate, 2);
                    break;
            }

            if ($usd_total) {
                $ada_total = $usd_total / $ADAPrice;
            }

            echo wpautop(wp_kses_post($this->description));
            $accepted_native_assets = get_posts([
                    'numberposts' => -1,
                    'post_type'   => 'mercury-native-asset',
                    'meta_key'    => 'accepted',
                    'meta_value'  => 1,
            ]);

            if (count($accepted_native_assets)) {
                $currencies = [
                        [
                                'name'     => "ADA &#8371;",
                                'unit'     => 'ada',
                                'decimals' => 6,
                                'price'    => $ada_total,
                        ],
                ];

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
                            'price'       => ($ada_total - $ada_offset) / (float)$currency_price,
                    ];
                }

                if (count($currencies) > 1) {
                    ?>
                    <div class="native-asset-selection">
                        <p class="form-row form-row-wide native-asset-selection">
                            <label for="cardano_currency">
                                <?= __("Choose the Cardano Native Asset you will use to pay", mercury_text_domain); ?>
                            </label>
                            <select name="cardano_currency" id="cardano_currency">
                                <?php
                                $chosen_currency = null;
                                foreach ($currencies as $token) {

                                    $selected = ($token['unit'] === $selected_currency);
                                    if ($selected) {
                                        $chosen_currency = $token;
                                    }
                                    ?>
                                    <option value="<?= $token['unit']; ?>"
                                            <?= $selected ? 'selected="selected"' : ''; ?>>
                                        <?= $token['name'] . ' (' . round($token['price'], $token['decimals']) . ')'; ?>
                                    </option>
                                    <?php
                                }
                                ?>
                            </select>
                        </p>
                        <p class="form-row form-row-wide" id="minUTxO">
                            Note: if using a Native Asset other than ADA (&#8371;) for payment, your total amount due
                            reflects a minimum amount of ADA (minUTxO) required to accompany the native asset of your
                            choosing.
                        </p>
                        <p class="form-row form-row-wide" style="display: none">
                            Total Price: <?= $cart_total; ?> <?= $curr; ?>
                            <br/>
                            Conversion Rate: <?= $exchange_rate; ?> USD : 1 <?= $curr; ?>
                            <br/>
                            USD Total Price: <?= $usd_total; ?> USD
                            <br/>
                            ADA Price: <?= $ADAPrice; ?> USD
                            <br/>
                            ADA Total Price: <?= round($ada_total, 6); ?> &#8371;
                            <?php
                            if ($chosen_currency && $chosen_currency['unit'] !== 'ada'): ?>
                                <br/>Paying with: <?= $chosen_currency['name']; ?>
                                <br/>minUTxO: <?= $chosen_currency['minUTxO']; ?> &#8371;
                                <br/><?= $chosen_currency['name']; ?> ADA Price: <?= $chosen_currency['perAdaPrice']; ?> &#8371;
                                <br/>Order <?= $chosen_currency['name']; ?>  Price: <?= round($chosen_currency['price'],
                                        $chosen_currency['decimals']); ?><?php
                            endif; ?>
                        </p>
                    </div>
                    <?php
                }
            } else {
                ?>
                <input type="hidden" name="cardano_currency" id="ada_currency" value="ada"/>
                <?php
            }
        }

        public function validate_fields() {
//            $log = wc_get_logger();
//            $log->debug("Validating Form Fields!\r\n" . print_r($_POST, true), processing_log);
            if ($_POST['payment_method'] === 'cardano_mercury') {
                switch ($_POST['cardano_currency']) {
                    case 'ada':
                        // Pure-ADA payment
                        return true;
                    default:
                        $token_id = post_exists($_POST['cardano_currency'], '', '', 'mercury-native-asset');
                        if (!$token_id) {
                            wc_add_notice("Invalid payment method chosen! Please try again.");

                            return false;
                        }
                        $price = get_post_meta($token_id, 'price', true);
                        if ($price <= 0) {
                            wc_add_notice("Sorry, we don't currently have any price information for that token and cannot process your order at this time.");

                            return false;
                        }
                }
            }

            return true;
        }

        public function process_payment($order_id): ?array {
            $order = wc_get_order($order_id);
            $log   = wc_get_logger();

            $cardano_currency = sanitize_text_field($_POST['cardano_currency']);

            $order->update_meta_data('cardano_currency', $cardano_currency);

            $curr = get_woocommerce_currency();

            $usdTotal = 0;

            try {
                $ADAPrice = Pricefeeder::getAveragePrice()['price'];
            } catch (Throwable $e) {
                wc_add_notice("Sorry, we're having technical difficulties fetching current ADA price. Please try again later.");
                $order->update_status('wc-failed', "Error Message: {$e->getMessage()}", processing_log);
                $log->error("Could not fetch ADA Price Details?\r\n{$e->getMessage()}", processing_log);

                return null;
            }

            if (!$ADAPrice) {
                wc_add_notice("Sorry, we're having technical difficulties fetching current ADA price. Please try again later.");
                $order->update_status('wc-failed', "ADA Price is 0");
                $log->error("ADA Price is 0?!", processing_log);

                return null;
            }

            $ADATotal = 0;

            switch ($curr) {
                case 'ADA':
                    $ADATotal = $order->get_total();
                    break;
                case 'USD':
                    $usdTotal = $order->get_total();
                    break;
                default:
                    $exchange_rate = $this->get_usd($curr, $this->currencyConversionCache);
                    if (!$exchange_rate) {
                        throw new Exception("Could not find exchange rate!");
                    }
                    $order->update_meta_data('usd_exchange_rate', $exchange_rate);
                    $usdTotal = round($order->get_total() * $exchange_rate, 2);
                    $order->update_meta_data('usd_value', $usdTotal);
                    break;
            }

            if ($usdTotal) {
                $ADATotal = $usdTotal / $ADAPrice;
            }

            if (!$ADATotal) {
                wc_add_notice("Sorry, we're having technical difficulties fetching current ADA price. Please try again later.");
                $order->update_status('wc-failed', "ADA Total Price is 0");
                $log->error("ADA Price is 0?! {$ADATotal} {$usdTotal}", processing_log);

                return null;
            }

            $ADATotal = round($ADATotal, 6);

            $lovelace_value = $ADATotal * 1000000;
            $recorded       = 0;
            $order->update_meta_data('wallet_address', $this->walletAddress);
            $order->update_meta_data('ADA_conversion_rate', $ADAPrice);
            $order->update_meta_data('ADA_total', $ADATotal);

            if ($cardano_currency !== 'ada') {
                $token = NativeAsset::getToken($cardano_currency);
                $log->debug("Found token?\r\n" . print_r($token, true), processing_log);
                if (!$token || (float)$token->price <= 0) {
                    $this->log->debug("Could not find a token?!", processing_log);
                    wc_add_notice("Could not locate the Native Asset you selected for payment. Sorry. Please try again.");
                }

                $ada_offset  = (1168010 + ((strlen($token->post_title) - 56) * 4310)) / 1000000;
                $token_price = ($ADATotal - $ada_offset) / (float)$token->price;

                $order->update_meta_data('Pay_With_Token', $token->unit);
                $order->update_meta_data('ADA_minUTxO', $ada_offset);
                $order->update_meta_data('Token_Price', $token->price);
                $order->update_meta_data('Token_Decimals', $token->decimals);
                $order->update_meta_data('Token_Display_Price', $token_price);

                $token_quantity = round($token_price, $token->decimals) * pow(10, $token->decimals);

                $order->update_meta_data('Base_Token_Quantity', $token_quantity);

                $min_utxo_lovelace = $ada_offset * 1000000;

                // 279c909f348e533da5808898f87f9a14bb2c3dfbbacccd631d927a3f534e454b

                while (!$recorded) {
                    $this->log->debug("Trying to record price? {$token_quantity}", processing_log);
                    $recorded = $this->recordPrice($order_id, $min_utxo_lovelace, $ADAPrice, $token->unit,
                            $token_quantity, $token->price);
                    if (!$recorded) {
                        $token_quantity++;
                    }
                }

                $ADATotal = $min_utxo_lovelace / 1000000;
                $order->update_meta_data('lovelace_total', $ada_offset);
                $order->update_meta_data('token_total', $token_quantity);
                $TokenTotal = $token_quantity / pow(10, $token->decimals);
                $order_note = sprintf("Awaiting payment of %s ADA + %s %s to address %s.", $ADATotal, $TokenTotal,
                        $token->ticker, $this->walletAddress);
                WC()->session->set("ADA_total", $ADATotal);
                WC()->session->set("Token_total", $TokenTotal);
            } else {

                while (!$recorded) {
                    $recorded = $this->recordPrice($order_id, $lovelace_value, $ADAPrice);
                    if (!$recorded) {
                        $lovelace_value++;
                    }
                }

                $ADATotal = $lovelace_value / 1000000;

                $order->update_meta_data('lovelace_total', $lovelace_value);

                $order_note = sprintf("Awaiting payment of %s ADA to address %s.", $ADATotal, $this->walletAddress);
                WC()->session->set("ADA_total", $ADATotal);
            }

            add_action('woocommerce_email_order_details', [
                    $this,
                    'email_details',
            ], 10, 1);
            $order->update_status('wc-on-hold', $order_note);

            if ($curr === 'ADA') {
                $order->set_total($ADATotal);
            }

            // https://cnftcon.io/attendee-registration/order-received/8118/?tickets_provider=tribe_wooticket&key=wc_order_RtQGgVfzlnTA1

            $return_url = $this->get_return_url($order);
            $return_url = str_replace('attendee-registration', 'checkout', $return_url);
            $return_url = str_replace('tickets_provider=tribe_wooticket&', '', $return_url);

            return [
                    'result'   => 'success',
                    'redirect' => $return_url,
            ];
        }

        public function can_refund_order($order): bool {
            return false;
        }

        /**
         * Convert from the store currency to USD value in order to perform Cardano (ADA) price conversion
         *
         * @param float  $total
         * @param string $fromCurr
         * @param int    $interval
         *
         * @return float
         */
        protected function get_usd(string $fromCurr, int $interval = 300): float {

            $currency_pair = "Mercury_{$fromCurr}_USD_Price";

            $rate = get_transient($currency_pair);

            if (!$rate) {
                $this->log->info("Fixer API Key: {$this->fixerioAPIKey}", processing_log);
                $Fixer = new Fixer($this->fixerioAPIKey);
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

        public function email_details($order_id) {
            $mercury_settings = $this->get_options();
            $order            = wc_get_order($order_id);
            $PaymentDetails   = $this->getPaymentDetails($order_id);
            if ($PaymentDetails->token_id) {
                $Token = NativeAsset::getToken($PaymentDetails->token_id);
            }

            include dirname(__FILE__) . '/views/email.php';
        }

        private function recordPrice($order_id, $lovelace_value, $conversion_rate, $token_id = null,
                                     $token_quantity = 0, $token_price = 0): int {
            global $wpdb;

            $wpdb->insert($this->prices_table,
                    compact('order_id', 'lovelace_value', 'conversion_rate', 'token_id', 'token_quantity',
                            'token_price'));

            $this->log->debug("Attempted to record price?\r\n{$wpdb->last_query}\r\n{$wpdb->last_error}",
                    processing_log);

            return $wpdb->insert_id;
        }

        private function getPaymentDetails($order_id) {
            global $wpdb;
            $order_value_table = $wpdb->prefix . price_table_name;

            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$order_value_table} WHERE order_id = %d", $order_id));
        }

        public function thankyou_page($order_id) {
            $mercury_settings = $this->get_options();
            $Order            = wc_get_order($order_id);
            $PaymentDetails   = $this->getPaymentDetails($Order->get_id());
            if ($PaymentDetails->token_id) {
                $Token = NativeAsset::getToken($PaymentDetails->token_id);
            }

            include dirname(__FILE__) . '/views/thank-you.php';
        }

        public function init_form_fields() {
            $this->form_fields = [
                    'enabled'                 => [
                            'title'   => __('Enable/Disable', 'woocommerce'),
                            'type'    => 'checkbox',
                            'label'   => __('Enable Cardano (ADA) payments', 'woocommerce'),
                            'default' => 'no',
                    ],
                    'title'                   => [
                            'title'       => __('Title', 'woocommerce'),
                            'type'        => 'text',
                            'description' => __('This controls the title which user sees during checkout.',
                                    'woocommerce'),
                            'default'     => _x('Cardano (ADA)', 'Cardano payment method', 'woocommerce'),
                            'desc_tip'    => true,
                    ],
                    'description'             => [
                            'title'       => __('Description', 'woocommerce'),
                            'type'        => 'textarea',
                            'description' => __("payment method description that the customer will see on your checkout.",
                                    'woocommerce'),
                            'default'     => __("Pay with Cardano (ADA)! After placing your order payment details will be displayed."),
                            'desc_tip'    => true,
                    ],
                    'blockfrostAPIKey'        => [
                            'title'       => __('Blockfrost API Key', 'woocommerce'),
                            'type'        => 'text',
                            'description' => __('Blockfrost Project API Key', 'woocommerce'),
                            'default'     => '',
                    ],
                    'blockfrostMode'          => [
                            'title'       => __('Blockfrost Mode', 'woocommerce'),
                            'type'        => 'select',
                            'description' => __("Choose whether you'd like to run in mainnet or testnet mode.",
                                    'woocommerce'),
                            'default'     => 'mainnet',
                            'options'     => [
                                    'mainnet' => 'Mainnet',
                                    'preview' => 'Preview',
                                    'preprod' => 'Preprod',
                            ],
                    ],
                    'currencyConverterAPI'    => [
                            'title'       => __('Free Currency Converter API Key', 'woocommerce'),
                            'type'        => 'text',
                            'description' => __('You can get your own API key at: https://free.currencyconverterapi.com/free-api-key',
                                    'woocommerce'),
                            'default'     => '43d99b69ef9fcd1ae57d',
                    ],
                    'fixerioAPIKey'           => [
                            'title'       => __('Fixer.io API Key', 'woocommerce'),
                            'type'        => 'text',
                            'description' => __('Get your API key at: https://fixer.io', 'woocommerce'),
                            'required'    => true,
                    ],
                    'currencyConversionCache' => [
                            'title'       => __('Currency Conversion Cache Length', 'woocommerce'),
                            'type'        => 'select',
                            'description' => __('The time to keep the latest currency conversion rates in the WordPress cache before fetching new data. If using the Fixer.io free tier we recommend a setting of at least 8 hours.',
                                    'woocommerce'),
                            'required'    => true,
                            'default'     => '28800',
                            'options'     => [
                                    '60'    => '1 Minute',
                                    '120'   => '2 Minutes',
                                    '300'   => '5 Minutes',
                                    '600'   => '10 Minutes',
                                    '1800'  => '30 Minutes',
                                    '3600'  => '1 Hour',
                                    '7200'  => '2 Hours',
                                    '14400' => '4 Hours',
                                    '28800' => '8 Hours',
                                    '43200' => '12 Hours',
                                    '86400' => '1 Day',
                            ],
                    ],
                    'taptoolsAPIKey'          => [
                            'title'       => __('TapTools API Key', 'woocommerce'),
                            'type'        => 'text',
                            'description' => __('A TapTools API key is required for Native Asset support! Get your API key at: https://taptools.io',
                                    'woocommerce'),
                            'required'    => false,
                    ],
                    'taptoolCronFrequency'    => [
                            'title'       => __('How often should we update the local list of Top 100 tokens?',
                                    'woocommerce'),
                            'type'        => 'select',
                            'description' => __('The TapTools API will be used to fetch the Top 100 tokens by volume on the frequency specified here',
                                    'woocommerce'),
                            'required'    => true,
                            'default'     => '3600',
                            'options'     => [
                                    '60'    => '1 Minute',
                                    '120'   => '2 Minutes',
                                    '300'   => '5 Minutes',
                                    '600'   => '10 Minutes',
                                    '1800'  => '30 Minutes',
                                    '3600'  => '1 Hour',
                                    '7200'  => '2 Hours',
                                    '14400' => '4 Hours',
                                    '28800' => '8 Hours',
                                    '43200' => '12 Hours',
                                    '86400' => '1 Day',
                            ],
                    ],
                    'walletAddress'           => [
                            'title'       => __('Shelley Wallet Address', 'woocommerce'),
                            'type'        => 'text',
                            'description' => __('Cardano Shelley wallet address', 'woocommerce'),
                            'default'     => '',
                            'desc_tip'    => true,
                    ],
                    'cronFrequency'           => [
                            'title'       => __('Order Polling Frequency', 'woocommerce'),
                            'type'        => 'select',
                            'description' => __('The frequency at which we will poll for paid orders. Checking more frequently will use more API requests from your plan.',
                                    'woocommerce'),
                            'default'     => '3600',
                            'options'     => [
                                    '60'   => 'Once per minute',
                                    '120'  => 'Once every 2 minutes',
                                    '300'  => 'Once every 5 minutes',
                                    '600'  => 'Once every 10 minutes',
                                    '900'  => 'Once every 15 minutes',
                                    '1800' => 'Once every 30 minutes',
                                    '3600' => 'Once per hour',
                            ],
                    ],
                    'orderTimeout'            => [
                            'title'       => __('Order Timeout', 'woocommerce'),
                            'type'        => 'select',
                            'description' => __('This is the time to keep orders on hold before cancelling them due to non-payment.',
                                    'woocommerce'),
                            'default'     => '3600',
                            'options'     => [
                                    '0'     => 'Never',
                                    '300'   => '5 Minutes',
                                    '600'   => '10 Minutes',
                                    '1800'  => '30 Minutes',
                                    '3600'  => '1 Hour',
                                    '7200'  => '2 Hours',
                                    '14400' => '4 Hours',
                                    '21600' => '6 Hours',
                                    '43200' => '12 Hours',
                                    '86400' => '24 Hours',
                            ],
                    ],
                    'processFeeFlat'          => [
                            'title'       => __('Flat Rate Processing Fee', 'woocommerce'),
                            'type'        => 'number',
                            'description' => __('This is a flat rate number that will be added to (or subtracted from, if negative) the order total when selecting Mercury as the payment method. Leave blank or zero for none. The value provided will be added to the order in the store selected currency',
                                    'woocommerce'),
                            'default'     => 0,
                            'placeholder' => 1,
                    ],
                    'processFeeVariable'      => [
                            'title'             => __('Variable Rate Processing Fee', 'woocommerce'),
                            'type'              => 'number',
                            'description'       => __('This is a variable (percentage-based) rate that will be added to (or subtracted from, if negative) the order total when selecting Mercury as the payment method. Leave blank or zero for none. Example: A value of 0.03 will apply a 3% fee based on the order items and shipping subtotal.',
                                    'woocommerce'),
                            'default'           => 0,
                            'custom_attributes' => [
                                    'min'  => -1.0,
                                    'max'  => 1.0,
                                    'step' => 'any',
                            ],
                            'placeholder'       => 0.03,
                    ],
                    'processFeeLabel'         => [
                            'title'       => __('Processing Fee Label', 'woocommerce'),
                            'type'        => 'string',
                            'default'     => 'Processing Fee',
                            'description' => __('The label for the processing fee that will be shown to the user on cart and checkout pages.',
                                    'woocommerce'),
                    ],
            ];
        }

        public function process_admin_options() {
            $this->init_settings();

            $post_data = $this->get_post_data();

            $newCronFrequency         = $post_data['woocommerce_cardano_mercury_cronFrequency'];
            $taptoolsFrequency        = $post_data['woocommerce_cardano_mercury_taptoolCronFrequency'];
            $currentCronFrequency     = $this->get_option('cronFrequency');
            $currentTapToolsFrequency = $this->get_option('taptoolCronFrequency');

            $newWalletAddress     = $post_data['woocommerce_cardano_mercury_walletAddress'];
            $currentWalletAddress = $this->get_option('walletAddress');

            if ($newWalletAddress and $currentWalletAddress != $newWalletAddress) {
                as_schedule_single_action(time(), 'mercury_wallet_sync', [$newWalletAddress], 'cardano-mercury');
            }

            if ($taptoolsFrequency && $currentTapToolsFrequency != $taptoolsFrequency) {
                TaskManager::reschedule('mercury_sync_assets', $taptoolsFrequency);
            }

            if ($newCronFrequency and $newCronFrequency != $currentCronFrequency) {

                as_unschedule_all_actions('mercury_cron_hook');
                as_schedule_recurring_action(time(), $newCronFrequency, 'mercury_cron_hook', [], 'cardano-mercury');
            }

            parent::process_admin_options();
        }

        private function seconds_to_time($val) {
            $options = [
                    '60'    => '1 Minutes',
                    '120'   => '2 Minutes',
                    '300'   => '5 Minutes',
                    '600'   => '10 Minutes',
                    '1800'  => '30 Minutes',
                    '3600'  => '1 Hour',
                    '7200'  => '2 Hours',
                    '14400' => '4 Hours',
                    '21600' => '6 Hours',
                    '43200' => '12 Hours',
                    '86400' => '24 Hours',
            ];

            if (isset($options[$val])) {
                return strtolower($options[$val]);
            }

            return false;
        }
    }
}

/**
 * Placeholder function for future functionality
 */
function mercury_activate() {
    setup_mercury_tables();
}

function setup_mercury_tables() {
    $log = wc_get_logger();

    global $wpdb;
    $charset_collage = $wpdb->get_charset_collate();

    $order_value_table   = $wpdb->prefix . price_table_name;
    $order_payment_table = $wpdb->prefix . order_payment_table;

    // 084154ee75830cf626e07cb548d562598936dd3a6ee494f7cda6c528

    // ALTER TABLE `wp_mercury_order_values` DROP INDEX `lovelace_value`
    $sql = "
    ALTER TABLE {$order_value_table} DROP INDEX `lovelace_value`;
    CREATE TABLE {$order_value_table} (
        `order_id` int(11) unsigned not null auto_increment,
        `lovelace_value` bigint unsigned not null,
        `conversion_rate` decimal(18,6) not null,
        `token_id` varchar(120),
        `token_quantity` bigint not null default 0,
        `token_price` decimal(38,19) not null default 0,
        primary key (`order_id`)
    ) {$charset_collage};
    ALTER TABLE {$order_value_table} ADD UNIQUE uk_lovelace_token_quantity (`lovelace_value`, `token_id`, `token_quantity`);
";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Placeholder function for future functionality
 */
function mercury_deactivate() {
    as_unschedule_all_actions('mercury_cron_hook');
    as_unschedule_all_actions('mercury_wallet_sync');
    as_unschedule_all_actions('mercury_sync_assets');
    as_unschedule_all_actions('mercury_asset_details');
}

/**
 * Placeholder function for future functionality
 */
function mercury_uninstall() {
    as_unschedule_all_actions('mercury_cron_hook');
    as_unschedule_all_actions('mercury_wallet_sync');
    as_unschedule_all_actions('mercury_sync_assets');
}

/**
 * Add the Cardano Mercury payment method...
 */
function add_cardano_mercury($methods) {
    $methods[] = "WC_Gateway_Cardano_Mercury";

    return $methods;
}



