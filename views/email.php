<?php
if (!defined('ABSPATH')) {
    die();
}
/**
 * Variables referenced within this template
 *
 * @var WC_Gateway_Cardano_Mercury $this
 * @var array                      $mercury_settings
 * @var WC_Order                   $order
 * @var object                     $PaymentDetails
 * @var WP_Post                    $Token
 */

if (!function_exists('format_currency')) {
    function format_currency($value, $decimals = 0) {
        return number_format(($value / pow(10, $decimals)), $decimals, '.', '');
    }
}

?>
<div id="cardanoPaymentDetails">
    <h2><?= apply_filters('mercury_payment_details_heading', 'Cardano Payment Details'); ?></h2>
    <p>
        To complete your payment, please send your payment in ADA using the details shown below. Or, you can
        <a href="<?= $this->get_return_url($order); ?>" target="_blank">click here</a>
        to return to our website and connect your wallet to complete payment.
    </p>
    <h3>
        <a href="<?= $this->get_return_url($order); ?>" target="_blank">Click here to complete your order.</a>
    </h3>
    <?php
    if ($this->orderTimeout): ?>
        <p>
            You have <?= $this->seconds_to_time($this->orderTimeout); ?> to complete your order. Otherwise, it will be
            cancelled and you will need to submit your order again.
        </p>
    <?php
    endif; ?>
</div>
