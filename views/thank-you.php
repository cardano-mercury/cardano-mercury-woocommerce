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
 * @var object                     $PaymentDetails
 * @var WP_Post                    $Token
 */

function format_currency($value, $decimals = 0) {
    return number_format(($value / pow(10, $decimals)), $decimals, '.', '');
}

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

    .woocommerce-cardano-mercury-wallet-icon {
        max-height: 24px;
        max-width: 24px;
        vertical-align: middle;
        margin-right: 0.5em;
    }

    .woocommerce-cardano-mercury-wallet-button {
        margin: 0 0.5em 0.5em;
        vertical-align: middle;
    }
</style>
<div id="cardanoPaymentDetails">
    <h2><?= apply_filters('mercury_payment_details_heading', 'Cardano Payment Details'); ?></h2>
    <ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
        <li class="woocommerce-order-overview__wallet-address">
            <p style="word-wrap: break-word;">Wallet Address (click address to copy): <span class="all-copy"
                                                                                            id="wallet-address"><?= $this->walletAddress; ?></span>
            </p>
        </li>
        <li class="woocommerce-order-overview__amount">
            <p style="word-wrap: break-word;">
                Total (click amount to copy):
                <br/>
                <span class="woocommerce-Price-ada-amount amount">
                                    <span class="no-copy">₳</span>
                    <span class="all-copy" id="ADA-total"><?= format_currency($PaymentDetails->lovelace_value,
                                6); ?></span>
                                </span>
                <?php
                if ($Token): ?>
                    <br/>plus<span class="woocommerce-Price-asset-amount amount">
                    <span class="no-copy">$<?= $Token->ticker; ?></span>
                        <span class="all-copy" id="NA-total"><?= format_currency($PaymentDetails->token_quantity,
                                    $Token->decimals); ?></span>
                        <br/>
                        Asset Fingerprint:
                        <a href="https://cardanoscan.io/token/<?= $Token->unit; ?>" target="_blank"
                           id="NA-unit"><?= $Token->unit; ?></a>
                        <span style="display: none" id="NA-quantity"><?= $PaymentDetails->token_quantity; ?></span>
                </span>
                <?php
                endif; ?>
            </p>
        </li>
        <li class="woocommerce-order-overview__timeout">
            <p>
                You have <?= $this->seconds_to_time($this->orderTimeout); ?> from the time your order was submitted to
                complete your payment. Otherwise, your order will be cancelled.
            </p>
        </li>
        <?php
        if ($Order->has_status('on-hold')): ?>
            <li class="woocommerce-order-overview__wallets" id="walletHolder">
                Searching for Cardano light wallets...
            </li>
        <?php
        else: ?><?php
            $tx_id = $Order->get_meta('tx_hash'); ?>
            <li class="woocommerce-order-overview__status">
                <?php
                switch ($Order->get_status()):
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
        <?php
        endif; ?>
    </ul>
    <div id="mercury-notice-holder"></div>
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
    import {Blockfrost, Lucid} from "https://unpkg.com/lucid-cardano@0.10.7/web/mod.js";

    (function ($) {
        let retries = 10;
        let loop = null;
        const InstalledWallets = [];

        function showError(msg, timeout) {
            timeout = timeout || 15000;
            (function (el) {
                setTimeout(function () {
                    el.children().remove('div.is-error');
                }, timeout);
            }($('#mercury-notice-holder').append(`
            <div class="wc-block-components-notice-banner mercury-notice is-error" role="alert">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
                    <path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path>
                </svg>
                <div class="wc-block-components-notice-banner__content">
                    ${msg}
                </div>
            </div>
            `)));
        }

        function showInfo(msg, timeout) {
            timeout = timeout || 15000;
            (function (el) {
                setTimeout(function () {
                    el.children().remove('div.is-info');
                }, timeout);
            }($('#mercury-notice-holder').append(`
            <div class="wc-block-components-notice-banner mercury-notice is-info" role="alert">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
                    <path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path>
                </svg>
                <div class="wc-block-components-notice-banner__content">
                    ${msg}
                </div>
            </div>
            `)));
        }

        $(document).on('click', '.woocommerce-cardano-mercury-wallet-button', payWith);

        async function payWith($e) {
            const wallet = $e.target.dataset.wallet;
            const the_wallet = window.cardano[wallet];

            const lucid = await Lucid.new(
                    new Blockfrost("https://mainnet.blockfrost.io/api/v0", "<?= $mercury_settings['blockfrostAPIKey']; ?>"),
                    "Mainnet",
            );

            let api;

            try {
                api = await the_wallet.enable();
            } catch (e) {
                showError("Could not connect to your wallet, please try again.", 10000);
                return;
            }

            lucid.selectWallet(api);

            const lovelace_value = BigInt($('#ADA-total').html() * 1000000);

            const native_asset_unit = $('#NA-unit').html();
            const native_asset_quantity = BigInt($('#NA-quantity').html());

            let assets = {
                lovelace: lovelace_value
            };

            if (native_asset_unit && native_asset_quantity) {
                assets[native_asset_unit] = native_asset_quantity
            }

            let tx, signedTx, txHash;

            try {
                tx = await lucid.newTx()
                        .payToAddress($('#wallet-address').html(), assets)
                        .attachMetadata(674, {
                            "msg": [
                                "Payment for Order #<?= $Order->get_id(); ?>"
                            ]
                        })
                        .complete();
            } catch (e) {
                showError("Could not build the transaction, please try again!", 10000);
                return;
            }

            try {
                signedTx = await tx.sign().complete();
            } catch (e) {
                showError("Could not sign the transaction, please try again!", 10000);
                return;
            }

            try {
                txHash = await signedTx.submit();
                showInfo(`<strong>Transaction Submitted!</strong> Transaction ID: <a href="https://cardanoscan.io/transaction/${txHash}" target="_blank">${txHash}</a>`, 30000);
            } catch (e) {
                showError("Failed to submit the transaction, please try again!", 10000);
            }
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
                                `${the_wallet.name.replace(" Wallet", "")}` +
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

            Object.keys(window.cardano).forEach((walletId) => {
                if (walletId === "typhon") {
                    return;
                }

                const wallet = window.cardano[walletId];
                if (wallet.name === undefined || wallet.icon === undefined) {
                    return;
                }

                if (wallet.experimental && wallet.experimental.vespr_compat === true) {
                    return;
                }

                if (InstalledWallets.includes(walletId)) {
                    return;
                }

                if (walletId === 'typhoncip30' || walletId.toLowerCase() === wallet.name.toLowerCase()) {
                    InstalledWallets.push(walletId);
                } else {
                    console.log(walletId);
                }
            });

            retries--;
        }

        $(document).ready(() => {
            loop = setInterval(findWallets, 200);
        });
    })(jQuery);
</script>
