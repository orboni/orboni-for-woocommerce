=== Orboni for WooCommerce ===
Contributors: orboni
Tags: crypto, cryptocurrency, usdt, bitcoin, payment gateway
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDT, USDC, BTC, ETH and other coins straight to your own wallets. Non-custodial: Orboni never holds your money.

== Description ==

Orboni adds "Crypto" to your WooCommerce checkout. The buyer places the order, picks a coin on Orboni's payment page and pays from any wallet or exchange. The money goes straight to the wallet addresses you set in your Orboni dashboard; Orboni never receives, holds or forwards it. When the payment is final on the chain, the order is marked paid by itself.

**What buyers can pay with**

* **USDT** on TRON, Ethereum, BNB Chain, Solana, TON, Polygon, Arbitrum, Optimism and Avalanche
* **USDC** on Ethereum, Base, Solana, Polygon, Arbitrum, Optimism, Avalanche, BNB Chain and Stellar
* **DAI** and **PYUSD**
* **BTC**, **ETH**, **BNB**, **SOL**, **TRX**, **POL**, **AVAX**, **LTC**, **DOGE**, **BCH**, **XRP**, **XLM** and TON's **GRAM**

The payment page offers only the coins you have set a wallet for in the Orboni dashboard.

**How it works**

* The buyer picks "Crypto" at checkout and is sent to Orboni's payment page, in their language, with the order's items and total in your store's currency.
* The page shows the exact amount in the chosen coin, an address and a QR code, and follows the transfer on the chain.
* Orboni tells your store with a signed webhook, and the order moves on: on hold while the payment confirms, then processing (or completed) once it is final.
* Without a webhook the plugin still reads the payment back when the buyer returns to your store, and checks again by itself after the page expires.

**Built for real orders**

* Works with the block checkout and the classic checkout, and with WooCommerce's order tables (HPOS).
* Underpaid and reversed payments, and payments held for your review, put the order on hold with a note, so nothing is completed or shipped by mistake. An overpaid order is marked paid, and its note says how much arrived.
* A buyer who comes back and pays again gets the same payment page while it has more than five minutes left, and a fresh one after that.
* Test keys let you try the whole flow before you take real payments.

Your store can sell in US dollars, euros, pounds, Swiss francs, złoty, Czech koruna, forints, lei, Danish, Swedish and Norwegian kroner, Icelandic krónur, lira, yen, won, yuan, Hong Kong and Singapore dollars, rupees, rupiah, ringgit, pesos (Philippine and Mexican), baht, shekels, Australian, New Zealand and Canadian dollars, reais, rand, hryvnias, Argentine and Chilean pesos, dirhams, riyals, Taiwan dollars and đồng. Orboni turns the price into dollars at the rate of the day (the European Central Bank's, or the market's for a currency the ECB does not publish), and the buyer pays that in the coin they pick. A payment is worth at least $1.

== Installation ==

1. Install and activate the plugin (WooCommerce must be active).
2. Sign up at [orboni.com](https://orboni.com), add a project and set the wallet addresses you want to be paid to.
3. In the Orboni dashboard, under Developers → API keys, copy the secret key. Use a test key (`sk_test_…`) first.
4. In WordPress, go to WooCommerce → Settings → Payments → Orboni, paste the secret key and enable it.
5. In the Orboni dashboard, under Developers → Webhooks, add an endpoint with the address shown on the plugin's settings page (`https://your-store.example/wp-json/orboni/v1/webhook`) and paste its signing secret (`whsec_…`) into the plugin's settings.
6. Place a test order. Once it works, switch to your live key.

== Frequently Asked Questions ==

= Does Orboni hold my money? =

No. Buyers pay to the wallet addresses you set in your dashboard, and the money is yours from the moment it is on the chain. Orboni watches the chain and tells your store; it never has your keys or your coins.

= My store is not in US dollars. =

Orboni takes 37 currencies, listed above: the payment page shows the order's total in your store's currency, and the buyer pays its worth at the rate of the day. In a store with a currency outside the list crypto is not offered at checkout, and the settings page says so.

= What happens if a buyer sends too little or too much? =

Too little: the payment page asks for the rest, and the order stays on hold with a note. If the page expires part paid, the order stays on hold for you to settle with the buyer. Too much: the order is paid, and a note says how much arrived in all; send the difference back from your own wallet. Only a payment far above the total (over twice it, and over $50 more) to a Bitcoin-style address is held for your review in the Orboni dashboard first, since it may be someone else's payment; the order stays on hold until you accept it there.

= How do refunds work? =

The money is in your wallet, so you send a refund from there. Record it in the Orboni dashboard to keep your records and the buyer's receipt right.

= Where are the logs? =

Turn on "Debug log" in the plugin's settings, then look under WooCommerce → Status → Logs for the source "orboni". Errors are logged even with it off. Keys and signing secrets are never logged.

= Can I use one Orboni project for several stores? =

Yes. Each store adds its own webhook endpoint, and each one acts only on its own orders.

== External services ==

This plugin connects to Orboni (https://orboni.com), the payment service it exists for. Without it the plugin does nothing.

* **When a buyer places an order with "Crypto"**, the plugin sends Orboni the amount in US cents, the order's items and number, your store's name, the buyer's email address, the buyer's language, the addresses to return the buyer to, the order's id and a short hash of your site's address (to tell your stores apart). Orboni uses them to draw the payment page and to send the buyer a receipt.
* **When the buyer returns, when a webhook arrives, and in a few scheduled checks after the payment page expires**, the plugin asks Orboni for the payment's status by its session id.
* **Orboni sends your store webhooks** about the payment to `/wp-json/orboni/v1/webhook`, signed with your endpoint's secret.
* The buyer pays on Orboni's own payment page at `https://orboni.com/pay/…`.

Every request carries your Orboni secret key. Orboni's [Terms of Service](https://orboni.com/page/terms-of-service) and [Privacy Policy](https://orboni.com/page/privacy-policy) apply.

== Screenshots ==

1. "Crypto" at checkout.
2. Orboni's payment page: the coin, the amount, the address and its QR code.
3. The plugin's settings in WooCommerce → Settings → Payments.
4. An order paid with crypto, with Orboni's notes.

== Changelog ==

= 1.0.0 =
* First release: the block and classic checkout, prices in 37 currencies, signed webhooks, read-backs without a webhook, HPOS.
