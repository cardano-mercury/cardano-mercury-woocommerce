<?php

namespace Mercury;

/**
 * PaymentData Class
 *
 * Maps to
 */
class PaymentData {

    // `order_id` int(11) unsigned not null auto_increment,
    //        `lovelace_value` bigint unsigned not null,
    //        `conversion_rate` decimal(18,6) not null,
    //        `token_id` varchar(120),
    //        `token_quantity` bigint not null default 0,
    //        `token_price` decimal(38,19) not null default 0,



    private $order_id, $lovelace_value, $conversion_rate, $token_id, $token_quantity, $token_price;

    public function __construct(object $args) {
        $this->order_id        = $args->order_id;
        $this->lovelace_value  = $args->lovelace_value;
        $this->conversion_rate = $args->conversion_rate;
        $this->token_id        = $args->token_id;
        $this->token_quantity  = $args->token_quantity;
        $this->token_price     = $args->token_price;
    }

    public function __get(string $key) {
        return $this->$key ?? null;
    }

    public function adaValue() {
        return $this->lovelace_value / 1000000;
    }

    public static function getByOrderId($order_id) {
        global $wpdb;
        $order_value_table = $wpdb->prefix . price_table_name;

        return new self($wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$order_value_table} WHERE order_id = %d
            ", $order_id)));
    }
}