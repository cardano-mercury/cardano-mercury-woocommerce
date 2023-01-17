<?php
if (!defined('ABSPATH')) {
    die();
}
/**
 * Variables referenced within this template
 *
 * @var WC_Gateway_Cardano_Mercury $this
 * @var integer                    $ADATotal
 * @var array                      $mercury_settings
 * @var WC_Order                   $Order
 */
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
        <li class="woocommerce-order-overview__wallet-address">
            <p style="word-wrap: break-word;">Wallet Address (click address to copy):
                <span class="all-copy" id="wallet-address"><?= $this->walletAddress; ?></span>
            </p>
        </li>
        <li class="woocommerce-order-overview__amount">
            <p style="word-wrap: break-word;">
                Total (click amount to copy): <span class="woocommerce-Price-amount amount">
                                    <span class="no-copy">₳</span>
                    <span class="all-copy" id="ADA-total"><?= number_format($ADATotal, 6, '.', ''); ?></span>
                                </span>
            </p>
        </li>
        <li class="woocommerce-order-overview__timeout">
            <p>
                You have <?= $this->seconds_to_time($this->orderTimeout); ?> from the time your order was submitted to
                complete your payment. Otherwise, your order will be cancelled.
            </p>
        </li>
        <?php if ($Order->has_status('on-hold')): ?>
            <li class="woocommerce-order-overview__wallets" id="walletHolder">
                Searching for Cardano light wallets...
            </li>
        <?php else: ?><?php $tx_id = $Order->get_meta('tx_hash'); ?>
            <li class="woocommerce-order-overview__status">
                <?php switch ($Order->get_status()):
                    case 'processing':
                        echo <<<data
Your payment has been received. <br />
Transaction ID: <a href="{$this->get_transaction_url($Order)}" target="_blank">{$tx_id}</a>. <br />
Your order is being processed now.
data;
                        break;
                    case 'completed':
                        echo <<<data
Your order has been completed.<br /> 
Transaction ID: <a href="{$this->get_transaction_url($Order)}" target="_blank">{$tx_id}</a>. <br />
Thank you for your business.
data;
                        break;
                    case 'cancelled':
                        echo <<<data
Sorry, this order has been cancelled.<br />
This usually happens if your payment was not received in a sufficient amount of time.<br />
Please contact us if you already sent payment.
data;
                        break;

                endswitch; ?>
            </li>
        <?php endif; ?>
    </ul>
</div>
<script type="text/javascript">
    const cardanoPayment = document.getElementById('cardanoPaymentDetails');
    cardanoPayment.addEventListener('click', function (e) {
        if (e.target.className !== 'all-copy') return;
        const element = e.target;
        copyText(element);
        flashElement(element);
    });

    function copyText(element) {

        const textToCopy = element.innerText;

        const myTemporaryInputElement = document.createElement("input");
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
<script type="module">
    import {Blockfrost, Lucid} from "https://unpkg.com/lucid-cardano@0.8.7/web/mod.js";

    (function ($) {
        const SupportedWallets = [
            'nami',
            'eternl',
            'yoroi',
            'typhoncip30',
            'gerowallet',
            'flint',
            'LodeWallet',
            'nufi'
        ];

        let retries = 10;
        let loop = null;
        const InstalledWallets = [];

        $(document).on('click', '.woocommerce-cardano-mercury-wallet-button', payWith);

        async function payWith($e) {
            const wallet = $e.target.dataset.wallet;
            const the_wallet = window.cardano[wallet];

            const lucid = await Lucid.new(
                    new Blockfrost("https://mainnet.blockfrost.io/api/v0", "<?= $mercury_settings['blockfrostAPIKey']; ?>"),
                    "Mainnet",
            );

            const api = await the_wallet.enable();
            lucid.selectWallet(api);

            const tx = await lucid.newTx()
                    .payToAddress($('#wallet-address').html(), {lovelace: $('#ADA-total').html() * 1000000})
                    .complete();

            const signedTx = await tx.sign().complete();

            const txHash = await signedTx.submit();

            console.log(txHash);
        }

        function findWallets() {
            if (retries <= 0) {
                clearInterval(loop);
                if (InstalledWallets.length) {
                    $('#walletHolder').html(`<strong>Pay with...</strong><br />`)
                    InstalledWallets.forEach((wallet) => {
                        const the_wallet = window.cardano[wallet];
                        $('#walletHolder').append(`<button type="button" class="button wp-element-button woocommerce-cardano-mercury-wallet-button" data-wallet="${wallet}">` +
                                `<img class="woocommerce-cardano-mercury-wallet-icon" src="${the_wallet.icon}" alt="${the_wallet.name}" width="24" height="24" />` +
                                `${the_wallet.name}` +
                                `</button>`);
                    });
                } else {
                    $('#walletHolder').html(`<strong>No supported Cardano light wallets found!<br />Please send your payment manually or try opening this page in another browser!</strong>`);
                }
            }

            if (window.cardano === undefined) {
                retries--;
                return;
            }

            SupportedWallets.forEach((wallet) => {
                if (window.cardano[wallet] === undefined) {
                    return;
                }

                if (!InstalledWallets.includes(wallet)) {
                    InstalledWallets.push(wallet);
                }
            });

            retries--;
        }

        $(document).ready(() => {
            loop = setInterval(findWallets, 200);
        });
    })(jQuery);
</script>
