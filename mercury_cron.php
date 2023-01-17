<?php
/**
 * @global array cron_log
 */

use Mercury\Blockfrost;

if (!defined('ABSPATH')) {
    die();
}

if (!function_exists('post_exists')) {
    require_once(ABSPATH . 'wp-admin/includes/post.php');
}

class TaskManager {

    const cron_log = ['source' => 'mercury_cron'];
    const LOG_CRITICAL = 'critical';
    const LOG_INFO = 'info';
    const LOG_DEBUG = 'debug';

    private $log, $options, $blockfrostMode, $blockfrostAPIKey, $blockfrostClient, $blockfrostHealthy, $defaultAddress, $orderTimeout;

    public function __construct() {
        $this->options          = get_option('woocommerce_cardano_mercury_settings');
        $this->blockfrostMode   = $this->getOption('blockfrostMode');
        $this->blockfrostAPIKey = $this->getOption('blockfrostAPIKey');
        $this->defaultAddress   = $this->getOption('walletAddress');
        $this->orderTimeout     = $this->getOption('orderTimeout');

        add_action('mercury_cron_hook', [
            $this,
            'do_cron',
        ]);

        add_action('mercury_wallet_sync', [
            $this,
            'sync_wallet',
        ]);
    }

    public function Blockfrost() {
        if (!$this->blockfrostClient) {
            if (!$this->blockfrostMode) {
                throw new Exception("Blockfrost Mode has not been set! Cannot continue.");
            }

            if (!$this->blockfrostAPIKey) {
                throw new Exception("Blockfrost API Key has not been set! Cannot continue.");
            }
            $this->blockfrostClient = new Blockfrost($this->blockfrostMode, $this->blockfrostAPIKey);
        }

        if (!$this->blockfrostHealthy) {
            $this->blockfrostHealthy = $this->blockfrostClient->isHealthy();
            if (!$this->blockfrostHealthy) {
                throw new Exception("Blockfrost is currently not reporting as healthy!");
            }
        }

        return $this->blockfrostClient;
    }

    private function getOption($key) {
        return $this->options[$key];
    }

    private static function seconds_to_time($val) {
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

    public function write($msg, $level = 'info') {
        if (!$this->log) {
            $this->log = wc_get_logger();
        }
        switch ($level) {
            case 'critical':
                $this->log->critical($msg, self::cron_log);
                break;
            case 'debug':
                $this->log->debug($msg, self::cron_log);
                break;
            case 'info':
            default:
                $this->log->info($msg, self::cron_log);
                break;
        }
    }

    public function do_cron() {
        global $wpdb;

        $this->log = wc_get_logger();

        try {
            $BF = $this->Blockfrost();
        } catch (Exception $e) {
            $this->write($e->getMessage(), self::LOG_CRITICAL);

            return;
        }

        $this->write("Looking for addresses to gather transactions for.", self::LOG_DEBUG);

        $pending_orders = get_posts([
                                        'post_type'   => 'shop_order',
                                        'post_status' => 'wc-on-hold',
                                        'meta_key'    => '_payment_method',
                                        'meta_value'  => 'cardano_mercury',
                                    ]);

        $addresses_to_check = [
            $this->defaultAddress,
        ];

        foreach ($pending_orders as $order) {
            $wallet_address       = get_post_meta($order->ID, 'wallet_address', true);
            $addresses_to_check[] = $wallet_address;
        }

        $addresses_to_check = array_unique($addresses_to_check);

        $this->write("Found " . count($addresses_to_check) . " addresses to check for transactions...",
                     self::LOG_DEBUG);

        foreach ($addresses_to_check as $address) {
            $page = 1;

            while ($transactions = $BF->get("addresses/{$address}/transactions", [
                'query' => [
                    'page'  => $page,
                    'order' => 'desc',
                ],
            ])) {
                if (!count($transactions->data)) {
                    $this->write("No transaction data found?\r\n" . wc_print_r($transactions, true), self::LOG_INFO);
                    break;
                }
                foreach ($transactions->data as $transaction) {
                    $processed = $this->processTransaction($transaction, $address);
                    if (!$processed) {
                        break 2;
                    }

                    if (count($transactions->data) < 100) {
                        break 2;
                    }
                    $page++;
                }
            }
        }

        if (empty($pending_orders)) {
            $this->write("No orders in need of processing", self::LOG_INFO);

            return;
        }

        foreach ($pending_orders as $order) {
            $ada_total      = get_post_meta($order->ID, 'ADA_total', true);
            $wallet_address = get_post_meta($order->ID, 'wallet_address', true);

            $payments_args = [
                'post_type'  => 'mercury-transaction',
                'meta_query' => [
                    [
                        'key'   => 'ada_value',
                        'value' => $ada_total,
                    ],
                    [
                        'key'   => 'payment_address',
                        'value' => $wallet_address,
                    ],
                ],
                'date_query' => [
                    [
                        'column' => 'post_date_gmt',
                        'after'  => $order->post_date_gmt,
                    ],
                ],
                'nopaging'   => true,
            ];

            $matching_payments = get_posts($payments_args);
            if (count($matching_payments) === 1) {
                $payment = $matching_payments[0];
                $in_use  = $wpdb->get_var($wpdb->prepare("
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = 'tx_hash' 
                  AND meta_value = %s", $payment->post_title));
                if ($in_use) {
                    $this->write("The specified transaction hash is already in use by Order #{$in_use}",
                                 self::LOG_INFO);
                    continue;
                }

                $Order = wc_get_order($order->ID);
                $Order->payment_complete($payment->post_title);
                $Order->update_meta_data('tx_hash', $payment->post_title);
                $orderNote = sprintf("Order payment of %s verified at %s. Transaction ID: %s", $ada_total . " ADA",
                                     $payment->post_date_gmt, $payment->post_title);
                $Order->add_order_note($orderNote);
                $Order->update_meta_data('input_address', get_post_meta($payment->ID, 'received_from', true));
                continue;
            }

            $this->write("Found " . count($matching_payments) . " matching transactions...", self::LOG_DEBUG);
            $this->write(wc_print_r($matching_payments, true), self::LOG_DEBUG);
        }

        if ($this->orderTimeout) {
            $expired = get_posts([
                                     'post_type'   => 'shop_order',
                                     'post_status' => 'wc-on-hold',
                                     'meta_key'    => '_payment_method',
                                     'meta_value'  => 'cardano_mercury',
                                     'date_query'  => [
                                         'before' => '-' . self::seconds_to_time($this->orderTimeout),
                                     ],
                                     'nopaging'    => true,
                                     'order'       => 'ASC',
                                 ]);

            if (!empty($expired)) {
                $this->write("EXPIRED ORDERS");
                $this->write(wc_print_r($expired, true), self::LOG_DEBUG);
                foreach ($expired as $order) {
                    $Order       = wc_get_order($order->ID);
                    $order_notes = <<<note
Your order was <strong>cancelled</strong> due to not receiving payment in the allotted time. 
Please do not send any funds to the payment address. 
If you would like to complete your order please visit our website and try again.
note;
                    $Order->update_status('wc-cancelled');
                    $Order->add_order_note($order_notes, true);
                    $this->write("Order #{$order->ID} cancelled due to non-payment before the timeout.");
                }
            }
        }

    }

    public function sync_wallet($address) {

        $this->write("Attempting to Sync Wallet. Wallet to check: {$address}");
        try {
            $BF = $this->Blockfrost();
        } catch (Exception $e) {
            $this->write($e->getMessage(), self::LOG_CRITICAL);

            return;
        }

        $page            = 1;
        $total_skipped   = 0;
        $total_processed = 0;

        // Fetch the oldest first so we don't conflict with the regular cron task...
        while ($transactions = $BF->getTransactions($address, $page, 'asc')) {
            if ($transactions->code !== 200) {
                $this->write("Error looking up transactions? {$transactions->message}", self::LOG_DEBUG);

                break;
            }

            if (!count($transactions->data)) {
                // No transactions found to process...
                break;
            }

            $this->write("Wallet sync processing page #{$page} of transaction information...");

            foreach ($transactions->data as $transaction) {
                $inserted = $this->processTransaction($transaction, $address);

                $total_processed += $inserted;
                $total_skipped   += ($inserted - 1) * -1;
            }

            if (count($transactions->data) < 100) {
                $this->write("Transaction count less than 100, should be the end of processing...", self::LOG_DEBUG);
                break;
            }
            $page++;
        }
        $this->write("Done syncing wallet orders after {$page} pages of results. Processed: {$total_processed} Skipped: {$total_skipped}",
                     self::LOG_DEBUG);
    }

    public function transactionExists($tx_hash) {
        return post_exists($tx_hash, '', '', 'mercury-transaction');
    }

    public function processTransaction($transaction, $address) {
        if ($this->transactionExists($transaction->tx_hash)) {
            $this->write("Txn: {$transaction->tx_hash} has already been recorded...", self::LOG_DEBUG);

            return 0;
        }

        $title         = $transaction->tx_hash;
        $date          = date('Y-m-d H:i:s', $transaction->block_time);
        $tx_utxo       = $this->Blockfrost()
                              ->get("txs/{$title}/utxos");
        $received_from = [];
        $txn_details   = [];

        foreach ($tx_utxo->data->inputs as $input_utxo) {
            $received_from[] = $input_utxo->address;
        }
        $received_from = array_unique($received_from);

        foreach ($tx_utxo->data->outputs as $output_utxo) {
            if ($output_utxo->address === $address) {
                foreach ($output_utxo->amount as $entry) {
                    $txn_details[$entry->unit] = $entry->quantity;
                }
            }
        }

        if ($txn_details['lovelace'] === 0) {
            $this->write("We received no funds from this transaction, probably sending funds out. Skip it!",
                         self::LOG_DEBUG);

            return 0;
        }

        $post_args = [
            'post_date_gmt'  => $date,
            'post_title'     => $title,
            'post_status'    => 'publish',
            'post_type'      => 'mercury-transaction',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
            'meta_input'     => [
                'ada_value'       => lovelace_to_ADA($txn_details['lovelace']),
                'lovelace_value'  => $txn_details['lovelace'],
                'payment_address' => $address,
                'txn_source'      => 'Blockfrost',
                'txn_output'      => json_encode($txn_details, JSON_PRETTY_PRINT),
                'received_from'   => $received_from,
            ],
        ];

        $post_id = wp_insert_post($post_args);
        $this->write("Recorded transaction ({$title}) to Post ID: {$post_id}", self::LOG_DEBUG);
    }

}

$MercuryCronTaskManager = new TaskManager();