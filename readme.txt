=== Crypto Payments for WooCommerce ===
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later

Accept cryptocurrency payments directly to your own wallets, with live price conversion, QR codes and on-chain verification.

== Description ==

Customers pay straight to your own wallet addresses: no payment processor and no fees beyond network gas. Works with both the classic and the block checkout.

* Bitcoin, Ethereum, Solana, BNB, Polygon, Avalanche, Arbitrum, Optimism, Base, Litecoin, Dogecoin, XRP and TRON
* USDT and USDC on the main networks
* Live price conversion through CoinGecko, with a price lock and countdown
* QR codes and copy-to-clipboard for the amount and address
* Optional automatic on-chain payment verification
* WooCommerce Subscriptions support through manual renewals

== Installation ==

1. Upload the plugin through Plugins → Add New → Upload Plugin, using crypto-payments-woo.zip from the latest GitHub release.
2. Activate it, then go to WooCommerce → Settings → Payments → Crypto Payments.
3. Enable the gateway and enter a wallet address for each network you want to accept.

== Changelog ==

= 1.3.0 =
* New: updates now come from GitHub releases through the normal WordPress update screens, with optional automatic updates.
* New: "Check for updates" and "Releases on GitHub" links on the Plugins screen, and an Updates section in the gateway settings.

= 1.2.1 =
* Fix: the coin menu didn't open with a mouse click on the block checkout.

= 1.2.0 =
* Fix: the gateway was hidden at checkout when the cart held a subscription product.

= 1.1.0 =
* New: WooCommerce Subscriptions support with manual renewals.

= 1.0.0 =
* First release.
