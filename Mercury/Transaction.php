<?php

namespace Mercury;

use WC_Order;

if (!defined('ABSPATH')) {
    die();
}

class Transaction {

    const POST_TYPE = 'mercury-transaction';
    const POST_TYPE_SINGULAR = 'Transaction';
    const POST_TYPE_PLURAL = 'Transactions';
    const LOG_INFO = 'info';
    const LOG_DEBUG = 'debug';
    const LOG_CRITICAL = 'critical';

    const LOG = ['source' => 'mercury-transaction'];

    public static function init() {
        if (!post_type_exists(self::POST_TYPE)) {
            register_post_type(self::POST_TYPE, self::post_type_args());
        }

        add_filter('manage_edit-' . self::POST_TYPE . '_columns', [
            __CLASS__,
            'custom_columns',
        ]);

        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [
            __CLASS__,
            'show_columns',
        ]);
    }

    public static function custom_columns($columns) {
        unset($columns['date']);

        $columns['title'] = "Transaction Hash";

        $columns['meta_details']         = 'View Details';
        $columns['meta_ada_value']       = 'ADA (&#8371;) Value';
        $columns['meta_payment_address'] = 'Recipient Address';
        $columns['meta_received_from']   = 'Received From';
        $columns['meta_txn_output']      = 'Native Assets';
        $columns['meta_for_order']       = 'For Order';
        $columns['meta_message']         = 'Message';
        $columns['meta_txn_source']      = 'Source';
        $columns['date']                 = 'Timestamp';

        return $columns;
    }

    public static function truncateAddress($address) {
        $prefix = substr($address, 0, 18);
        $suffix = substr($address, -8, 8);

        return "{$prefix}...{$suffix}";
    }

    public static function show_columns($column) {
        global $post, $wpdb;

        switch ($column) {
            case (bool)preg_match('/^meta_/', $column):
                $x    = substr($column, 5);
                $meta = get_post_meta($post->ID, $x, true);

                switch ($x) {
                    case 'details':
                        echo "<a href=\"https://cardanoscan.io/transaction/{$post->post_title}\" target=\"_blank\">View on Cardanoscan</a>";
                        break;
                    case 'for_order':
                        $order_id = $wpdb->get_var($wpdb->prepare("
                        SELECT post_id 
                        FROM {$wpdb->postmeta} 
                        WHERE meta_key = 'tx_hash' 
                          AND meta_value = %s", $post->post_title));
                        if ($order_id) {
                            self::write("Found Order ID? {$order_id}");
                            $order = new WC_Order($order_id);
                            $link  = $order->get_edit_order_url();
                            echo "<a href=\"{$link}\" target=\"_blank\">Order #{$order->get_id()}</a>";
                        }
                        break;
                    case 'ada_value':
                        echo $meta . " &#8371;";
                        break;
                    case 'txn_output':
                        $data = json_decode($meta);
                        foreach ($data as $token_id => $quantity) {
                            if ($token_id === 'lovelace') {
                                continue;
                            }
                            $token = NativeAsset::getToken($token_id);
                            if (!$token || !$token->ticker) {
                                continue;
                            }
                            echo "{$token->ticker}: " . (round($quantity / pow(10, $token->decimals),
                                    $token->decimals)) . "<br />";
                        }
                        break;
                    case 'payment_address':
                        $addr = self::truncateAddress($meta);
                        echo "<a href=\"https://cardanoscan.io/address/{$meta}\" target=\"_blank\">{$addr}</a>";
                        break;
                    case 'received_from':
                        foreach ($meta as $address):
                            $addr = self::truncateAddress($address);
                            echo "<a href=\"https://cardanoscan.io/address/{$address}\" target=\"_blank\">{$addr}</a><br/><br />";
                        endforeach;
                        break;
                    default:
                        if (is_array($meta)) {
                            echo join('<br />', $meta);
                        } else {
                            echo $meta;
                        }
                }
                break;
        }
    }

    /**
     * @param string $tx_hash
     *
     * @return bool
     */
    public static function exists(string $tx_hash): bool {
        return post_exists($tx_hash, '', '', self::POST_TYPE);
    }

    /**
     * @param object $transaction
     * @param string $address
     *
     * @return int
     */
    public static function process(object $transaction, string $address, array $log_config = self::LOG): int {
        $title = $transaction->tx_hash;

        if (self::exists($title)) {
            return 0;
        }

        $BF = Blockfrost::new();

        $date     = date('Y-m-d H:i:s', $transaction->block_time);
        $tx_utxo  = $BF->getTransactionUTxO($title);
        $metadata = $BF->getTransactionMetadata($title);

//        self::write("Transaction metadata?\r\n" . print_r($metadata, true));

        $tx_msg = '';

        foreach ($metadata->data as $datum) {
            switch ($datum->label) {
                case '674':
                    $tx_msg .= implode(' ', $datum->json_metadata->msg);
                    break;
                default:
                    break;
            }
        }

//        self::write("Transaction message? {$tx_msg}");

        $received_from = [];
        $txn_details   = [];

        foreach ($tx_utxo->data->inputs as $input_utxo) {
            $received_from[] = $input_utxo->address;
        }

        $received_from = array_unique($received_from);
        foreach ($tx_utxo->data->outputs as $output_utxo) {
            if ($output_utxo->address === $address) {
                foreach ($output_utxo->amount as $entry) {
                    $txn_details[$entry->unit] += $entry->quantity;
                }
            }
        }

        if ($txn_details['lovelace'] === 0) {
            return 0;
        }

        $post_args = [
            'post_date_gmt'  => $date,
            'post_title'     => $title,
            'post_status'    => 'publish',
            'post_type'      => self::POST_TYPE,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
            'meta_input'     => [
                'hash'         => $title,
                'ada_value'       => $txn_details['lovelace'] / 1000000,
                'lovelace_value'  => $txn_details['lovelace'],
                'payment_address' => $address,
                'txn_source'      => 'Blockfrost',
                'txn_output'      => json_encode($txn_details),
                'received_from'   => $received_from,
                'message'         => $tx_msg,
            ],
        ];

        $post_id = wp_insert_post($post_args);
        if ($post_id) {
            self::write("Recorded transaction ({$title}) to Post ID: {$post_id}", self::LOG_DEBUG, $log_config);
        }

        return $post_id;

    }

    public static function write($message, $level = self::LOG_INFO, $log_config = self::LOG) {
        $log = wc_get_logger();

        switch ($level) {
            case self::LOG_DEBUG:
                $log->debug($message, $log_config);
                break;
            case self::LOG_CRITICAL:
                $log->critical($message, $log_config);
                break;
            case self::LOG_INFO:
            default:
                $log->info($message, $log_config);
                break;
        }
    }

    public static function post_type_args() {
        return [
            'label'               => 'Cardano Transaction',
            'labels'              => [
                'name'               => sprintf(__('%s', mercury_text_domain), self::POST_TYPE_PLURAL),
                'singular_name'      => sprintf(__('%s', mercury_text_domain), self::POST_TYPE_SINGULAR),
                'menu_name'          => sprintf(__('%s', mercury_text_domain), self::POST_TYPE_PLURAL),
                'all_items'          => sprintf(__('%s', mercury_text_domain), self::POST_TYPE_PLURAL),
                'add_new'            => __('Add New', mercury_text_domain),
                'add_new_item'       => sprintf(__('Add New %s', mercury_text_domain), self::POST_TYPE_SINGULAR),
                'edit_item'          => sprintf(__('Edit %s', mercury_text_domain), self::POST_TYPE_SINGULAR),
                'new_item'           => sprintf(__('New %s', mercury_text_domain), self::POST_TYPE_SINGULAR),
                'view_item'          => sprintf(__('View %s', mercury_text_domain), self::POST_TYPE_SINGULAR),
                'search_items'       => sprintf(__('Search %s', mercury_text_domain), self::POST_TYPE_PLURAL),
                'not_found'          => sprintf(__('No %s found', mercury_text_domain), self::POST_TYPE_PLURAL),
                'not_found_in_trash' => sprintf(__('No %s found in Trash', mercury_text_domain),
                    self::POST_TYPE_PLURAL),
                'parent_item_colon'  => sprintf(__('Parent %s:', mercury_text_domain), self::POST_TYPE_SINGULAR),
            ],
            'description'         => 'A record of a Cardano transaction',
            'has_archive'         => false,
            'public'              => false,
            'hierarchical'        => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => true,
            'show_in_admin_bar'   => false,
            'show_in_rest'        => true,
            'rest_base'           => 'mercury/transaction',
            'menu_position'       => null,
            'menu_icon'           => 'dashicons-money-alt',
            'capability_type'     => 'page',
            'capabilities'        => [
                'read_posts'             => true,
                'read_private_posts'     => true,
                'edit_posts'             => true,
                'create_posts'           => false,
                'delete_posts'           => false,
                'delete_pages'           => false,
                'delete_private_pages'   => false,
                'delete_private_posts'   => false,
                'delete_published_pages' => false,
                'delete_published_posts' => false,
                'delete_others_pages'    => false,
                'delete_others_posts'    => false,
            ],
            'map_meta_cap'        => true,
            'supports'            => [
                'title',
            ],
        ];
    }

}