# Cardano Mercury for WooCommerce

![Picture](mercury_banner.jpg)

A simple plugin for WordPress that enables a streamlined and reliable payment gateway to quickly and easily begin
accepting payment for orders in $ADA; the native coin of the Cardano blockchain.

***

**Version:** 2.0-RC1<br />
**Tags:** woocommerce, wordpress, cardano, ada, lovelace, payment, gateway<br />
**Requires at least:** WooCommerce v7.3<br />
**Tested up to:** WooCommerce v7.3<br />
**Stable tag:** 1.0<br />
**Requires PHP:** 7.3<br />
**License:** GPLv3<br />
**License URI:** https://www.gnu.org/licenses/gpl-3.0.en.html

## Instructions and stuff coming soon!

This plugin is still considered to be in "beta" mode for the time being. Please use at your own risk although all
testing has shown to be reliable and work accurately there may still be bugs and issues that are encountered along the
way. Please use the GitHub issues tracker to submit any errors or issues you encounter. Please use the Discussions page
for feature and help requests.

## Version 2.0 Features and Improvements

Version 2.0 is currently in _Release Candidate_ status as we do some further testing. Version 2 brings a variety of
substantial improvements including:
* Support for CIP-30 light wallets to complete payment for an order by simply clicking a button and signing the transaction
  after the customer submits their order.
* Code refactoring for improved readability.
* Use of the new Action Scheduler for improved and more reliable monitoring of transactions for automated completion of
  orders.
* Introduction of a lightweight custom database table to ensure that each order is given a unique value to help better
  identify individual payments.
* The first edition of this plugin did not support having greater than 100 UTXO present in the payment wallet due to
  pagination constraints by Blockfrost. Version 2.0 will address this issue.
* Adding payment instructions to customer emails in addition to a link to return to the store to complete payment via
  CIP-30 enabled wallet.
* Removal of the QR-code generating package included in the first edition of this software. This should make the 
  package size smaller and more easily transferable.
* Added ability to mark orders that were paid in $ADA as being manually refunded in the Orders interface for more
  accurate reporting for store owners.

## Credits

* Adam Dean GitHub: [@crypto2099](https://github.com/crypto2099) Twitter: [@adamkdean](https://twitter.com/adamkdean)

  Co-founder of Buffy Bot Publishing/cNFTcon. Board of Directors for [DripDropz](https://dripdropz.io)

* Latheesan Kanesamoorthy GitHub: [@latheesan-k](https://github.com/latheesan-k) Twitter: [@LatheesanK](https://twitter.com/LatheesanK)

  Special thanks to Latheesan Kanesamoorthy (@latheesan-k) for discussion, backend development, and much, much more!

## Thanks

* Thanks to JP Birch (MADinArt) for the development of the Mercury logo and branding.
* Thanks to [Alessandro Konrad](https://github.com/alessandrokonrad) for his [Lucid](https://github.com/spacebudz/lucid)
  library which made integrating CIP-30 compliant wallets a breeze!
* Thanks as always to the entire Cardano community for their support and encouragement.



