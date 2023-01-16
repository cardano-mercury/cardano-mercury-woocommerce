<?php
/**
 * Variables referenced within this template
 *
 * @var WC_Gateway_Cardano_Mercury $this
 * @var integer                    $adaTotal
 * @var array                      $mercury_settings
 * @var WC_Order                   $order
 */
?>
<div id="cardanoPaymentDetails">
    <h2><?= apply_filters('mercury_payment_details_heading', 'Cardano Payment Details'); ?></h2>
    <p>
        To complete your payment, please send your payment in ADA using the details shown below. Or, you can
        <a href="<?= $this->get_return_url($order); ?>" target="_blank">click here</a>
        to return to our website and connect your wallet to complete payment.
    </p>
    <h3>Payment Details</h3>
    <p>
        Please send your payment to: <strong id="address"><?= $this->walletAddress; ?></strong>
    </p>
    <p>
        Please send: <strong id="amount"><?= number_format($adaTotal, 6, '.', ''); ?></strong> ₳
    </p>
    <?php if ($this->orderTimeout): ?>
        <p>
            You have <?= seconds_to_time($this->orderTimeout); ?> to complete your order. Otherwise, it will be
            cancelled and you will need to submit your order again.
        </p>
    <?php endif; ?>
</div>
