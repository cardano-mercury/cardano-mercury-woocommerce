<?php

use Mercury\Blockfrost;

/**
 * Perform payment checks based on the user-set scheduling frequency and triggered via wp-cron
 */
function mercury_do_cron() {
    global $wpdb;

    $mercury_settings = get_option('woocommerce_cardano_mercury_settings');
    $log              = wc_get_logger();

    $orders = get_posts([
                            'post_type'   => 'shop_order',
                            'post_status' => 'wc-on-hold',
                            'meta_key'    => '_payment_method',
                            'meta_value'  => 'cardano_mercury',
                        ]);

    if (empty($orders)) {
        // There are no on-hold orders utilizing the Cardano Mercury payment method...
        $log->info("No orders in need of processing.", cron_log);

        return;
    } else {
        $BF        = new Blockfrost($mercury_settings['blockfrostMode'], $mercury_settings['blockfrostAPIKey']);
        $addresses = [];

        // Get the address to check as well as the Cardano (Ada) value in Lovelace for each order.
        foreach ($orders as $order) {
            $order_meta                             = get_post_meta($order->ID);
            $ada_total                              = $order_meta['ada_total'][0];
            $wallet_address                         = $order_meta['wallet_address'][0];
            $addresses[$wallet_address][$order->ID] = $ada_total * 1000000;
        }

        foreach ($addresses as $address => $dust_values) {
            $utxo = $BF->get('addresses/' . $address . '/utxos');
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
                                    $log->info("Transaction Hash in use! {$entry->tx_hash}", cron_log);
                                    continue 2;
                                } else {
                                    $order_id = array_search($token->quantity, $dust_values);
                                    $log->info("We should update Order #{$order_id} as complete!", cron_log);
                                    $Order = wc_get_order($order_id);
                                    $Order->payment_complete($entry->tx_hash);
                                    $tx_detail = $BF->get('txs/' . $entry->tx_hash);
                                    $log->info("TX DETAIL: " . wc_print_r($tx_detail->data, true), cron_log);
                                    $tx_utxo = $BF->get('txs/' . $entry->tx_hash . '/utxos');
                                    $log->info("TX UTXO: " . wc_print_r($tx_utxo->data, true), cron_log);
                                    update_post_meta($order_id, 'tx_hash', $entry->tx_hash);
                                    update_post_meta($order_id, 'tx_detail',
                                                     json_encode($tx_detail->data, JSON_PRETTY_PRINT));
                                    update_post_meta($order_id, 'tx_utxo', $tx_utxo->data);
                                    $orderNote = sprintf("Order payment of %s verified at %s. Transaction Hash: %s",
                                                         lovelace_to_ada($token->quantity) . " ADA",
                                                         date('Y-m-d H:i:s', $tx_detail->data->blocktime),
                                                         $entry->tx_hash);
                                    $Order->add_order_note($orderNote);
                                    $input_wallets = [];
                                    foreach ($tx_utxo->data->inputs as $input) {
                                        $input_wallets[] = $input->address;
                                    }
                                    update_post_meta($order_id, 'input_address',
                                                     json_encode($input_wallets, JSON_PRETTY_PRINT));
                                }
                            }
                        }
                    }
                }
            }
            $log->info(wc_print_r($utxo, true), cron_log);
        }
    }

    if ($mercury_settings['orderTimeout']) {

        // Get orders that should be marked as expired due to non-payment.
        $expired = get_posts([
                                 'post_type'   => 'shop_order',
                                 'post_status' => 'wc-on-hold',
                                 'meta_key'    => '_payment_method',
                                 'meta_value'  => 'cardano_mercury',
                                 'date_query'  => [
                                     'before' => '-' . seconds_to_time($mercury_settings['orderTimeout']),
                                 ],
                             ]);

        $log->info("Expired Orders:" . wc_print_r($expired, true), cron_log);

        if (!empty($expired)) {
            $log->info("EXPIRED ORDERS", cron_log);
            $log->info(wc_print_r($expired, true), cron_log);

            foreach ($expired as $order) {
                $Order       = wc_get_order($order->ID);
                $order_notes = <<<note
Your order was <strong>cancelled</strong> due to not receiving payment in the allotted time. 
Please do not send any funds to the payment address. 
If you would like to complete your order please visit our website and try again.
note;
                $Order->update_status('wc-cancelled');
                $Order->add_order_note($order_notes, true);
                $log->info("Order #{$order->ID} cancelled due to non-payment.", cron_log);
            }
        }
    }

}