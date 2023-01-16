<?php

/*
Plugin Name: Cardano Mercury Woocommerce
Plugin URI: https://crypto2099.io/mercury-woocommerce
Description: WooCommerce Cardano (Ada) Currency Plugin
Version: 1.0
Author: Adam Dean <adam@crypto2099.io>
Author URI: https://crypto2099.io
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

use Mercury\Pricefeeder;

require_once 'vendor/autoload.php';
require_once dirname(__FILE__) . '/lib/action-scheduler/action-scheduler.php';

// Scaffold out actions for the gateway
add_action('plugins_loaded', 'scaffold_mercury');
register_activation_hook(__FILE__, 'mercury_activate');
register_deactivation_hook(__FILE__, 'mercury_deactivate');
register_uninstall_hook(__FILE__, 'mercury_uninstall');
add_filter('woocommerce_payment_gateways', "add_cardano_mercury");

const price_table_name    = 'mercury_order_values';
const order_payment_table = 'mercury_order_payments';
const setup_log           = ['source' => 'mercury_setup'];
const cron_log            = ['source' => 'mercury_cron'];
const processing_log      = ['source' => 'mercury_processing'];

require_once dirname(__FILE__) . '/mercury_cron.php';

function lovelace_to_ada($lovelace) {
    return $lovelace / 1000000;
}

function ada_to_lovelace($ada) {
    return $ada * 1000000;
}

function seconds_to_time($val) {
    $options = [
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

function scaffold_mercury() {

    global $wpdb;

    $log = wc_get_logger();

    $mercury_settings = get_option('woocommerce_cardano_mercury_settings');

    $wpdb->actionscheduler_actions = $wpdb->prefix . 'actionscheduler_actions';
    $wpdb->actionscheduler_groups  = $wpdb->prefix . 'actionscheduler_groups';

    $next_hook = as_has_scheduled_action('mercury_cron_hook');

    if (!$next_hook) {
        $log->info("Next hook not scheduled? What is cron frequency? {$mercury_settings['cronFrequency']}s", setup_log);
        as_schedule_recurring_action(time(), $mercury_settings['cronFrequency'], 'mercury_cron_hook', [],
                                     'cardano-mercury');
    }

    /**
     * ADA Currency Symbol
     */
    add_filter('woocommerce_currencies', 'addCardano');

    function addCardano($currencies) {
        $currencies['ADA'] = __('Cardano (Ada)', 'woocommerce');

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

        private $prices_table;
        public $blockfrostAPIKey, $walletAddress, $currencyConverterAPI;

        const options_key = 'woocommerce_cardano_mercury_settings';

        public function __construct() {
            global $wpdb;
            $this->supports             = [
                'products',
                'refunds',
            ];
            $this->view_transaction_url = 'https://cardanoscan.io/transaction/%s';
            $this->prices_table         = $wpdb->prefix . price_table_name;
            $this->id                   = "cardano_mercury";
            $this->icon                 = apply_filters("cardano_mercury_icon", "");
            $this->has_fields           = true;
            $this->method_title         = _x("Cardano Mercury", "Cardano Mercury payment method", "woocommerce");
            $this->method_description   = __("Accept Cardano (Ada) payments!", "woocommerce");

            $this->init_form_fields();
            $this->init_settings();

            $this->title                = $this->get_option('title');
            $this->description          = $this->get_option('description');
            $this->blockfrostAPIKey     = $this->get_option('blockfrostAPIKey');
            $this->walletAddress        = $this->get_option('walletAddress');
            $this->currencyConverterAPI = $this->get_option('currencyConverterAPI');
            $this->orderTimeout         = $this->get_option('orderTimeout');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [
                $this,
                'process_admin_options',
            ]);
            add_action("woocommerce_thankyou_" . $this->id, [
                $this,
                'thankyou_page',
            ]);
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

            if (empty($options['currencyConverterAPI']) and !in_array(get_woocommerce_currency(), [
                    'ADA',
                    'USD',
                ])) {
                $log->error("No currency converter API key! " . get_woocommerce_currency(), setup_log);

                return true;
            }

            return false;
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            try {

                $curr     = get_woocommerce_currency();
                $adaPrice = Pricefeeder::getAveragePrice()['price'];
                $usdTotal = 0;

                switch ($curr) {
                    case 'ADA':
                        $adaTotal = $order->get_total();
                        break;
                    case 'USD':
                        $usdTotal = $order->get_total();
                        break;
                    default:
                        $usdTotal = $this->get_usd($order->get_total(), $curr);
                        break;
                }

                if ($usdTotal) {
                    $adaTotal = $usdTotal / $adaPrice;
                }

                $adaTotal = round($adaTotal, 6);

                $lovelace_value = $adaTotal * 1000000;
                $recorded       = 0;
                while (!$recorded) {
                    $recorded = $this->recordPrice($order_id, $lovelace_value, $adaPrice);
                    if (!$recorded) {
                        $lovelace_value++;
                    }
                }

                $adaTotal = $lovelace_value / 1000000;

                update_post_meta($order_id, 'ada_conversion_rate', $adaPrice);
                update_post_meta($order_id, 'wallet_address', $this->walletAddress);
                update_post_meta($order_id, 'ada_total', $adaTotal);
                update_post_meta($order_id, 'lovelace_total', $lovelace_value);

                if ($curr === 'ADA') {
                    $order->set_total($adaTotal);
                }

                $order_note = sprintf("Awaiting payment of %s Ada to address %s.", $adaTotal, $this->walletAddress);

                WC()->session->set("ada_total", $adaTotal);
                add_action('woocommerce_email_order_details', [
                    $this,
                    'email_details',
                ],         10, 4);
                $order->update_status('wc-on-hold', $order_note);

            } catch (Exception $e) {
                $log = wc_get_logger();
                $log->info($e->getMessage());
                $order->update_status('wc-failed', "Error Message: {$e->getMessage()}", processing_log);
                wc_add_notice(__('Payment error:',
                                 'woothemes') . "Sorry, we encountered an error attempting to process your order. Please try again later.",
                              'error');

                return null;
            }

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        public function can_refund_order($order): bool {
            return false;
        }

        /**
         * Convert from the store currency to USD value in order to perform Cardano (Ada) price conversion
         *
         * @param float  $total
         * @param string $fromCurr
         * @param int    $interval
         *
         * @return float
         */
        protected function get_usd(float $total, string $fromCurr, int $interval = 300): float {
            $currency_pair = "{$fromCurr}_USD";

            $rate = get_transient($currency_pair);

            if (!$rate) {
                $endpoint = "https://free.currconv.com/api/v7/convert?compact=y&apiKey={$this->currencyConverterAPI}&q={$currency_pair}";
                $response = wp_remote_get($endpoint);
                if (is_wp_error($response) || $response['response']['code'] !== 200) {

                    throw new Exception("Could not reach the currency conversion service. Please try again.\r\n{$endpoint}\r\n{$this->currencyConverterAPI}\r\n{$currency_pair}");
                }

                $body = json_decode(wp_remote_retrieve_body($response));
                $rate = $body->{$currency_pair}->val;
                set_transient($currency_pair, $rate, $interval);
            }

            return round($total * $rate, 2);

        }

        public function email_details($order_id) {
            $mercury_settings = $this->get_options();
            $order            = wc_get_order($order_id);
            $adaTotal         = $order->get_meta('ada_total');

            include dirname(__FILE__) . '/views/email.php';
        }

        private function recordPrice($order_id, $lovelace_value, $conversion_rate): int {
            global $wpdb;

            $wpdb->insert($this->prices_table, [
                'order_id'        => $order_id,
                'lovelace_value'  => $lovelace_value,
                'conversion_rate' => $conversion_rate,
            ]);

            return $wpdb->insert_id;
        }

        public function thankyou_page($order_id) {
            $mercury_settings = $this->get_options();
            $Order            = wc_get_order($order_id);
            $adaTotal         = $Order->get_meta('ada_total');

            include dirname(__FILE__) . '/views/thank-you.php';

        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled'              => [
                    'title'   => __('Enable/Disable', 'woocommerce'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable Cardano (Ada) payments', 'woocommerce'),
                    'default' => 'no',
                ],
                'title'                => [
                    'title'       => __('Title', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('This controls the title which user sees during checkout.', 'woocommerce'),
                    'default'     => _x('Cardano (Ada)', 'Cardano payment method', 'woocommerce'),
                    'desc_tip'    => true,
                ],
                'description'          => [
                    'title'       => __('Description', 'woocommerce'),
                    'type'        => 'textarea',
                    'description' => __("payment method description that the customer will see on your checkout.",
                                        'woocommerce'),
                    'default'     => __("Pay with Cardano (Ada)! After placing your order payment details will be displayed."),
                    'desc_tip'    => true,
                ],
                'blockfrostAPIKey'     => [
                    'title'       => __('Blockfrost API Key', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Blockfrost Project API Key', 'woocommerce'),
                    'default'     => '',
                    'desc_tip'    => true,
                ],
                'blockfrostMode'       => [
                    'title'       => __('Blockfrost Mode', 'woocommerce'),
                    'type'        => 'select',
                    'description' => __("Choose whether you'd like to run in mainnet or testnet mode.", 'woocommerce'),
                    'default'     => 'mainnet',
                    'options'     => [
                        'mainnet' => 'Mainnet',
                        'testnet' => 'Testnet',
                    ],
                ],
                'currencyConverterAPI' => [
                    'title'       => __('Free Currency Converter API Key', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('You can get your own API key at: https://free.currencyconverterapi.com/free-api-key',
                                        'woocommerce'),
                    'default'     => '43d99b69ef9fcd1ae57d',
                ],
                'walletAddress'        => [
                    'title'       => __('Shelley Wallet Address', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Cardano Shelley wallet address', 'woocommerce'),
                    'default'     => '',
                    'desc_tip'    => true,
                ],
                'cronFrequency'        => [
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
                'orderTimeout'         => [
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
            ];
        }

        public function process_admin_options() {
            $this->init_settings();

            $post_data = $this->get_post_data();

            if ($post_data['woocommerce_cardano_mercury_cronFrequency']) {

                as_unschedule_all_actions('mercury_cron_hook');
                as_schedule_recurring_action(time(), $post_data['woocommerce_cardano_mercury_cronFrequency'],
                                             'mercury_cron_hook', [], 'cardano-mercury');
            }

            parent::process_admin_options();
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

    $sql = "
    CREATE TABLE {$order_value_table} (
        `order_id` int(11) unsigned not null auto_increment,
        `lovelace_value` bigint unsigned not null,
        `conversion_rate` decimal(18,6) not null,
        primary key (`order_id`),
        unique key (`lovelace_value`)
    ) {$charset_collage};";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Placeholder function for future functionality
 */
function mercury_deactivate() {
    as_unschedule_all_actions('mercury_cron_hook');
}

/**
 * Placeholder function for future functionality
 */
function mercury_uninstall() {
    as_unschedule_all_actions('mercury_cron_hook');
}

/**
 * Add the Cardano Mercury payment method...
 */
function add_cardano_mercury($methods) {
    $methods[] = "WC_Gateway_Cardano_Mercury";

    return $methods;
}



