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

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\Label\Alignment\LabelAlignmentCenter;
use Endroid\QrCode\Label\Font\NotoSans;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Writer\PngWriter;
use Mercury\Blockfrost;

include 'vendor/autoload.php';

// Scaffold out actions for the gateway
add_action('plugins_loaded', 'scaffold_mercury');
register_activation_hook(__FILE__, 'mercury_activate');
register_deactivation_hook(__FILE__, 'mercury_deactivate');
register_uninstall_hook(__FILE__, 'mercury_uninstall');
add_filter('woocommerce_payment_gateways', "add_cardano_mercury");
add_filter('cron_schedules', 'mercury_add_intervals');
add_action('mercury_cron_hook', 'mercury_do_cron');

//add_action('woocommerce_order_status_changed', 'mercury_status_changed', 10, 3);

/**
 * Filter to expand WordPress default cron frequencies
 *
 * @param $schedules
 *
 * @return mixed
 */
function mercury_add_intervals($schedules) {
    $schedules['minutes_1']  = [
        'interval' => 60,
        'display'  => "Once every 1 minute",
    ];
    $schedules['minutes_2']  = [
        'interval' => 120,
        'display'  => "Once every 2 minutes",
    ];
    $schedules['minutes_5']  = [
        'interval' => 300,
        'display'  => "Once every 5 minutes",
    ];
    $schedules['minutes_10'] = [
        'interval' => 600,
        'display'  => "Once every 10 minutes",
    ];
    $schedules['minutes_15'] = [
        'interval' => 900,
        'display'  => "Once every 15 minutes",
    ];
    $schedules['minutes_30'] = [
        'interval' => 1800,
        'display'  => "Once every 30 minutes",
    ];

    return $schedules;
}

/**
 * Perform payment checks based on the user-set scheduling frequency and triggered via wp-cron
 */
function mercury_do_cron() {
    global $wpdb;

    $cron_log = ['source' => 'mercury_cron'];
    $log      = wc_get_logger();

    $orders = get_posts([
        'post_type'   => 'shop_order',
        'post_status' => 'wc-on-hold',
        'meta_key'    => '_payment_method',
        'meta_value'  => 'cardano_mercury',
    ]);

    if (empty($orders)) {
        // There are no on-hold orders utilizing the Cardano Mercury payment method...
        $log->info("No orders in need of processing.", $cron_log);

        return;
    } else {
        $mercury_settings = get_option('woocommerce_cardano_mercury_settings');
        $bf_header        = [
            'headers' => [
                'project_id' => $mercury_settings['blockfrostAPIKey'],
            ],
        ];
        $addresses        = [];

        // Get the address to check as well as the Cardano (Ada) value in Lovelace for each order.
        foreach ($orders as $order) {
            $order_meta                             = get_post_meta($order->ID);
            $ada_total                              = $order_meta['ada_total'][0];
            $wallet_address                         = $order_meta['wallet_address'][0];
            $addresses[$wallet_address][$order->ID] = $ada_total * 1000000;
        }

        // This should be configurable in the settings somewhere...
        $BF = new Blockfrost('mainnet');

        foreach ($addresses as $address => $dust_values) {
            $utxo = $BF->get('addresses/' . $address . '/utxos', $bf_header);
            if (is_array($utxo->data) && !empty($utxo->data)) {
                foreach ($utxo->data as $entry) {
                    foreach ($entry->amount as $token) {
                        if ($token->unit === 'lovelace') {
                            if (in_array($token->quantity, array_values($dust_values))) {
                                // Dust matches
                                $in_use = $wpdb->get_var($wpdb->prepare("
                                SELECT post_id
                                FROM {$wpdb->postmeta} 
                                WHERE meta_key = 'tx_hash' 
                                  AND meta_value = %s", $entry->tx_hash));
                                if ($in_use) {
                                    $log->info("Transaction Hash in use! {$entry->tx_hash}", $cron_log);
                                    continue 2;
                                } else {
                                    $order_id = array_search($token->quantity, $dust_values);
                                    $log->info("We should update Order #{$order_id} as complete!", $cron_log);
                                    $Order = new WC_Order($order_id);
                                    $Order->payment_complete($entry->tx_hash);
                                    $tx_detail = $BF->get('txs/' . $entry->tx_hash, $bf_header);
                                    $log->info("TX DETAIL: " . wc_print_r($tx_detail->data, true), $cron_log);
                                    $tx_utxo = $BF->get('txs/' . $entry->tx_hash . '/utxos', $bf_header);
                                    $log->info("TX UTXO: " . wc_print_r($tx_utxo->data, true), $cron_log);
                                    $block = $BF->get('blocks/' . $entry->block, $bf_header);
                                    $log->info("BLOCK: " . wc_print_r($block->data, true), $cron_log);
                                    update_post_meta($order_id, 'tx_hash', $entry->tx_hash);
                                    update_post_meta($order_id, 'tx_detail', $tx_detail->data);
                                    update_post_meta($order_id, 'tx_utxo', $tx_utxo->data);
                                    update_post_meta($order_id, 'tx_block', $block->data);
                                    $orderNote = sprintf("Order payment of %s verified at %s. Transaction Hash: %s", "$token->quantity Lovelaces", date('Y-m-d H:i:s', $block->data->time), $entry->tx_hash);
                                    $Order->add_order_note($orderNote);
                                    $input_wallets = [];
                                    foreach ($tx_utxo->data->inputs as $input) {
                                        $input_wallets[] = $input->address;
                                    }
                                    update_post_meta($order_id, 'input_address', implode(' | ', $input_wallets));
                                }
                            }
                        }
                    }
                }
            }
            $log->info(wc_print_r($utxo, true), $cron_log);
        }
    }

    // Get orders that should be marked as expired due to non-payment. The expiration window should also be configurable...
    $expired = get_posts([
        'post_type'   => 'shop_order',
        'post_status' => 'wc-on-hold',
        'meta_key'    => '_payment_method',
        'meta_value'  => 'cardano_mercury',
        'date_query'  => [
            'column' => 'post_date_gmt',
            'before' => '-1 hour',
        ],
    ]);

    if (!empty($expired)) {
        $log->info("EXPIRED ORDERS", $cron_log);
        $log->info(wc_print_r($expired, true), $cron_log);

        foreach ($expired as $order) {
            $Order       = new WC_Order($order->ID);
            $order_notes = "Your order was <strong>cancelled</strong> due to not receiving payment in the allotted time. Please do not send any funds to the payment address. If you would like to complete your order please visit our website and try again.";
            $Order->update_status('wc-cancelled');
            $Order->add_order_note($order_notes, true);
            $log->info("Order #{$order->ID} cancelled due to non-payment.", $cron_log);
        }
    }

}

function scaffold_mercury() {

    $mercury_settings = get_option('woocommerce_cardano_mercury_settings');

    if (!wp_next_scheduled('mercury_cron_hook')) {
        wp_schedule_event(time(), $mercury_settings['cronFrequency'], 'mercury_cron_hook');
    } else {
        $logger   = wc_get_logger();
        $schedule = wp_get_schedule('mercury_cron_hook');
        if ($schedule !== $mercury_settings['cronFrequency']) {
            $logger->info("Schedule: {$schedule} Setting: {$mercury_settings['cronFrequency']}", ['source' => 'mercury_cron']);
            $logger->info("Rescheduling the cron job!", ['source' => 'mercury_cron']);
            wp_unschedule_hook('mercury_cron_hook');
            wp_schedule_event(time(), $mercury_settings['cronFrequency'], 'mercury_cron_hook');
        }
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
        switch ($currency) {
            case 'ADA':
                $currency_symbol = '₳';
                break;
        }

        return $currency_symbol;
    }

    // Set up the Mercury payment gateway
    class WC_Gateway_Cardano_Mercury extends WC_Payment_Gateway {

        public $dustMin, $dustMax, $blockfrostAPIKey, $walletAddress, $currencyConverterAPI;

        public function __construct() {
            $this->id                 = "cardano_mercury";
            $this->icon               = apply_filters("cardano_mercury_icon", "");
            $this->has_fields         = true;
            $this->method_title       = _x("Cardano Mercury", "Cardano Mercury payment method", "woocommerce");
            $this->method_description = __("Accept Cardano (Ada) payments!", "woocommerce");

            $this->init_form_fields();
            $this->init_settings();

            $this->title                = $this->get_option('title');
            $this->description          = $this->get_option('description');
            $this->dustMin              = $this->get_option('dustMin');
            $this->dustMax              = $this->get_option('dustMax');
            $this->blockfrostAPIKey     = $this->get_option('blockfrostAPIKey');
            $this->walletAddress        = $this->get_option('walletAddress');
            $this->currencyConverterAPI = $this->get_option('currencyConverterAPI');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [
                $this,
                'process_admin_options',
            ]);
            add_action("woocommerce_thankyou_" . $this->id, [
                $this,
                'thankyou_page',
            ]);

            add_action('woocommerce_email_before_order_table', [
                $this,
                'email_instructions',
            ], 10, 3);
        }

        public function process_payment($order_id) {
            $order = new WC_Order($order_id);

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
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
        protected function get_usd($total, $fromCurr, $interval = 300) {
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

        /**
         * Check for the current market price from three different public APIs and return the average value across
         * all three. This should avoid any price "spikes" from a particular platform.
         *
         * @return float
         */
        protected function getPrices() {
            $prices = [];
            try {
                $prices[] = $this->get_bittrex_price();
            } catch (Exception $e) {
                // Bittrex is offline!
            }
            try {
                $prices[] = $this->get_coingecko_price();
            } catch (Exception $e) {
                // CoinGecko is offline!
            }
            try {
                $prices[] = $this->get_hitbtc_price();
            } catch (Exception $e) {
                // HitBTC is offline!
            }

            // Strip out empty prices to avoid hurting the average market price
            array_filter($prices);

            return array_sum($prices) / count($prices);
        }

        /**
         * Fetch and cache the current ADA:USD price from HitBTC. Default cache time is 5 minutes.
         *
         * @param int $interval
         *
         * @return float
         */
        protected function get_hitbtc_price($interval = 300) {
            $transient_key = "hitbtc_ada_price";
            $hitbtcPrice   = get_transient($transient_key);

            if ($hitbtcPrice) {
                return $hitbtcPrice;
            }

            $response = wp_remote_get("https://api.hitbtc.com/api/2/public/ticker/ADAUSD");

            if (is_wp_error($response) || $response['response']['code'] !== 200) {
                throw new Exception("HitBTC API is offline!");
            }

            $body = json_decode(wp_remote_retrieve_body($response));
            //            $body        = wp_remote_retrieve_body($response);
            $hitbtcPrice = (float)$body->last;

            set_transient($transient_key, $hitbtcPrice, $interval);

            return $hitbtcPrice;
        }

        /**
         * Fetch and cache the current ADA:USD price from CoinGecko. Default cache time is 5 minutes.
         *
         * @param int $interval
         *
         * @return float
         */
        protected function get_coingecko_price($interval = 300) {
            $transient_key  = "coingecko_ada_price";
            $coingeckoPrice = get_transient($transient_key);

            if ($coingeckoPrice) {
                return $coingeckoPrice;
            }

            $response = wp_remote_get("https://api.coingecko.com/api/v3/simple/price?ids=cardano&vs_currencies=usd");
            if (is_wp_error($response)) {
                throw new Exception("CoinGecko API is offline!");
            }

            $body = json_decode(wp_remote_retrieve_body($response));

            $coingeckoPrice = (float)$body->cardano->usd;

            set_transient($transient_key, $coingeckoPrice, $interval);

            return $coingeckoPrice;
        }

        /**
         * Fetch and cache the current ADA:USD price from Bittrex. Default cache time is 5 minutes.
         *
         * @param int $interval
         *
         * @return float
         */
        protected function get_bittrex_price($interval = 300) {
            $transient_key = "bittrex_ada_price";
            $bittrexPrice  = get_transient($transient_key);

            if ($bittrexPrice) {
                return $bittrexPrice;
            }

            $response = wp_remote_get('https://bittrex.com/api/v1.1/public/getticker?market=USD-ADA');
            if (is_wp_error($response) || $response['response']['code'] !== 200) {
                throw new Exception("Bittrex API is offline!");
            }
            $responseBody = json_decode($response['body']);

            $bittrexPrice = (float)$responseBody->result->Last;

            set_transient($transient_key, $bittrexPrice, $interval);

            return $bittrexPrice;
        }

        public function thankyou_page($order_id) {
            $order = new WC_Order($order_id);
            try {
                $orderAddressExists = get_post_meta($order_id, 'wallet_address');

                if (count($orderAddressExists)) {
                    // Handle a refresh here...
                    $this->output_thank_you_html(get_post_meta($order_id, 'ada_total', true), $order_id);

                    return;
                }

                $curr = get_woocommerce_currency();
                if ($curr === 'ADA') {
                    // price is already in Ada...
                    $adaTotal = $order->get_total();
                } else {
                    if ($curr === 'USD') {
                        $usdTotal = $order->get_total();
                    } else {
                        $usdTotal = $this->get_usd($order->get_total(), $curr);
                    }
                    $adaPrice = $this->getPrices();
                    //                    echo "What's Ada Price? {$adaPrice} {$usdTotal}";
                    $adaTotal = $usdTotal / $adaPrice;
                }

                $adaTotal = round($adaTotal, 6);

                $dust = random_int($this->dustMin, $this->dustMax);

                $dustAmount = apply_filters('mercury_get_dust', $dust, $adaTotal);

                $adaTotal += ($dustAmount / 1000000);

                update_post_meta($order_id, 'wallet_address', $this->walletAddress);
                update_post_meta($order_id, 'dust_amount', $dustAmount);
                update_post_meta($order_id, 'ada_total', $adaTotal);

                if ($curr === 'ADA') {
                    $order->set_total($adaTotal);
                }

                $order_note = sprintf("Awaiting payment of %s Ada to address %s.", $adaTotal, $this->walletAddress);

                WC()->session->set("ada_total", $adaTotal);
                add_action('woocommerce_email_order_details', [
                    $this,
                    'email_details',
                ], 10, 4);
                $order->update_status('wc-on-hold', $order_note);

                $this->output_thank_you_html($adaTotal, $order_id);

            } catch (Exception $e) {
                $order->update_status('wc-failed', "Error Message: {$e->getMessage()}");
                echo '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">';
                echo '<ul class="woocommerce-error">';
                echo '<li>';
                echo 'Sorry, something went wrong. Please try again later.<br>';
                echo '</li>';
                echo '</ul>';
                echo '</div>';
            }
        }

        /**
         * Render the thank-you page HTML...
         *
         * @param float $adaTotal
         * @param int   $order_id
         *
         * @todo Move HTML to a template file
         */
        public function output_thank_you_html($adaTotal, $order_id) {
            $qr_code = $this->generate_qr_code($this->walletAddress, $adaTotal);
            ?>
            <style type="text/css">
                span.all-copy {
                    position: relative;
                    -webkit-user-select: all;
                    -moz-user-select: all;
                    -ms-user-select: all;
                    user-select: all;
                }

                span.no-copy {
                    -webkit-user-select: none;
                    -moz-user-select: none;
                    -ms-user-select: none;
                    user-select: none;
                }

                #cardanoPaymentDetails .flash {
                    transition: all 0.2s;
                    border-bottom: 1px solid #111;
                }

                #cardanoPaymentDetails .flash:after {
                    content: "Copied!";
                    position: absolute;
                    left: calc(100% + 1rem);
                    color: #111;
                }
            </style>

            <div id="cardanoPaymentDetails">
                <h2><?= apply_filters('mercury_payment_details_heading', 'Cardano Payment Details'); ?></h2>
                <ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
                    <li class="woocommerce-order-overview__qr-code">
                        <p style="word-wrap: break-word">QR Code for Payment:</p>
                        <div class="qr-code-container">
                            <img id="payment-qr-code" src="<?= $qr_code; ?>"/>
                        </div>
                    </li>
                    <li class="woocommerce-order-overview__wallet-address">
                        <p style="word-wrap: break-word;">Wallet Address (click address to copy):
                            <strong>
                                <span class="all-copy" id="wallet-address"><?= $this->walletAddress; ?></span>
                            </strong>
                        </p>
                    </li>
                    <li class="woocommerce-order-overview__amount">
                        <p style="word-wrap: break-word;">Total (click amount to copy):
                            <strong>
                                <span class="woocommerce-Price-amount amount">
                                    <span class="no-copy">₳</span> <span class="all-copy" id="ada-total"><?= $adaTotal; ?></span>
                                </span>
                            </strong>
                        </p>
                    </li>
                </ul>
            </div>
            <script type="text/javascript">
                var cardanoPayment = document.getElementById('cardanoPaymentDetails');
                cardanoPayment.addEventListener('click', function(e) {
                    if (e.target.className !== 'all-copy') return;
                    var element = e.target;
                    copyText(element);
                    flashElement(element);
                });

                function copyText(element) {

                    var textToCopy = element.innerText;

                    var myTemporaryInputElement = document.createElement("input");
                    myTemporaryInputElement.type = "text";
                    myTemporaryInputElement.value = textToCopy;

                    document.body.appendChild(myTemporaryInputElement);

                    myTemporaryInputElement.select();
                    document.execCommand("Copy");

                    document.body.removeChild(myTemporaryInputElement);
                }

                function flashElement(element) {
                    element.classList.add("flash");
                    document.addEventListener("transitionend", function () {
                        setTimeout(function () {
                            element.classList.remove("flash");
                        }, 1000);
                    });
                }
            </script>
            <?php
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
                    'description' => __("payment method description that the customer will see on your checkout.", 'woocommerce'),
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
                'walletAddress'        => [
                    'title'       => __('Shelley Wallet Address', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Cardano Shelley wallet address', 'woocommerce'),
                    'default'     => '',
                    'desc_tip'    => true,
                ],
                'dustMin'              => [
                    'title'       => __('Dust Minimum', 'woocommerce'),
                    'type'        => 'number',
                    'description' => __('The minimum amount of dust that will be added to orders. Specified in Lovelaces (0 - 999,999)', 'woocommerce'),
                    'default'     => 0,
                    'desc_tip'    => true,
                ],
                'dustMax'              => [
                    'title'       => __('Dust Maximum', 'woocommerce'),
                    'type'        => 'number',
                    'description' => __('The maximum amount of dust that will be added to orders. Specified in Lovelaces (0 - 999,999)', 'woocommerce'),
                    'default'     => 0,
                    'desc_tip'    => true,
                ],
                'currencyConverterAPI' => [
                    'title'       => __('Free Currency Converter API Key', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('You can get your own API key at: https://free.currencyconverterapi.com/free-api-key', 'woocommerce'),
                    'default'     => '43d99b69ef9fcd1ae57d',
                ],
                'cronFrequency'        => [
                    'title'       => __('Order Polling Frequency', 'woocommerce'),
                    'type'        => 'select',
                    'description' => __('The frequency at which we will poll for paid orders. Checking more frequently will use more API requests from your plan.', 'woocommerce'),
                    'default'     => 'minutes_1',
                    'options'     => [
                        'minutes_1'  => 'Once per minute',
                        'minutes_2'  => 'Once every 2 minutes',
                        'minutes_5'  => 'Once every 5 minutes',
                        'minutes_10' => 'Once every 10 minutes',
                        'minutes_15' => 'Once every 15 minutes',
                        'minutes_30' => 'Once every 30 minutes',
                        'hourly'     => 'Once per hour',
                    ],
                ],
                'orderTimeout'         => [
                    'title'       => __('Order Timeout', 'woocommerce'),
                    'type'        => 'select',
                    'description' => __('This is the time to keep orders on hold before cancelling them due to non-payment.', 'woocommerce'),
                    'default'     => '3600',
                    'options'     => [
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

        public function generate_qr_code($address, $total) {

            $web_link = "web+cardano:{$address}?amount={$total}";

            $result = Builder::create()
                             ->writer(new PngWriter())
                             ->writerOptions([])
                             ->data($web_link)
                             ->encoding(new Encoding('UTF-8'))
                             ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
                             ->size(300)
                             ->margin(10)
                             ->roundBlockSizeMode(new RoundBlockSizeModeMargin())
                             ->build();

            return $result->getDataUri();
        }

        public function process_admin_options() {
            $this->init_settings();

            $post_data = $this->get_post_data();
            $fields    = $this->get_form_fields();

            if (isset($fields['cronFrequency'])) {
                wp_reschedule_event(time(), $fields['cronFrequency'], 'mercury_cron_hook');
            }

            parent::process_admin_options();
        }
    }
}

/**
 * Placeholder function for future functionality
 */
function mercury_activate() {

}

/**
 * Placeholder function for future functionality
 */
function mercury_deactivate() {

}

/**
 * Placeholder function for future functionality
 */
function mercury_uninstall() {

}

/**
 * Add the Cardano Mercury payment method...
 */
function add_cardano_mercury($methods) {
    $methods[] = "WC_Gateway_Cardano_Mercury";

    return $methods;
}