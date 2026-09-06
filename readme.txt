=== VisualPay for WooCommerce ===
Contributors: visualpay
Tags: woocommerce, cryptocurrency, payments, crypto, checkout
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cryptocurrency payments in WooCommerce via the VisualPay Merchant API.

== Description ==

VisualPay for WooCommerce adds a cryptocurrency payment gateway to your WooCommerce store. Customers pay with supported coins through a secure payment page that shows a QR code and wallet address. Amounts are locked server-side so shoppers cannot change the charge.

**Features**

* Cryptocurrency checkout gateway for WooCommerce
* Secure payment page with QR code and copyable wallet address
* Server-side amount lock (`amount_usd`)
* Live payment status polling (awaiting / confirmed / expired / cancelled)
* Cancel payment from the payment page
* Configurable transaction TTL (5–60 minutes)
* Admin Overview dashboard
* Admin Transactions list with filters and search
* Admin Settings (API key test, TTL, checkout title/description, payment page language)
* API key stays on the server; the payment page uses WordPress AJAX only
* High-Performance Order Storage (HPOS) compatible

**Requirements**

* WordPress 6.0+
* WooCommerce 7.0+
* PHP 7.4+
* A VisualPay merchant API key ([create a free merchant account](https://visualpay.net/signup))

This plugin is an interface to the VisualPay Merchant service (SaaS). You need a VisualPay merchant account and API key for the gateway to work.

== Installation ==

1. Upload the plugin ZIP via **Plugins → Add New → Upload Plugin**, or install from the WordPress plugin directory.
2. Activate **VisualPay for WooCommerce**.
3. Ensure WooCommerce is installed and active.
4. Open **VisualPay → Settings**, enter your merchant `x-api-key`, and configure options.
5. Go to **Settings → Permalinks** and click **Save** once (flushes rewrite rules used by the `/visualpay/` payment page). Pretty permalinks are recommended.
6. Enable the gateway and place a test order.

== Frequently Asked Questions ==

= Do I need a VisualPay account? =

Yes. Create a merchant account at [visualpay.net](https://visualpay.net/signup) and generate an API key.

= Why should I flush permalinks? =

The payment page uses a custom rewrite rule (`/visualpay/`). Saving permalinks once after activation registers that rule. The plugin does not change your site permalink structure automatically.

= What currency is charged? =

Order totals are sent to VisualPay as `amount_usd`. Configure your store currency accordingly.

= Where is my API key stored? =

The API key is stored in WordPress options on your server and is never sent to the browser. Checkout uses WordPress AJAX; your server talks to VisualPay.

= Does uninstall remove data? =

Yes. Deleting the plugin removes VisualPay settings options and the local transactions table.

== External services ==

This plugin connects to the **VisualPay Merchant API** to list currencies, create payment transactions, check payment status, and cancel payments.

* Service: VisualPay — https://visualpay.net
* API base: `https://visualpay.net`
* Endpoints used (examples): `/api/v1/merchant/currencies/list`, `/api/v1/merchant/transaction/create`, `/api/v1/merchant/transaction/status`, `/api/v1/merchant/transaction/cancel`
* Data sent when creating a payment may include: currency symbol, network code, USD amount, TTL, order reference/comment, optional order id, and customer billing email. Requests include your merchant `x-api-key` header.
* The service is required for the payment gateway to function. By configuring your API key and using the gateway, you authorize these requests.
* Terms of use / privacy: https://visualpay.net (see site legal pages for the latest Terms and Privacy Policy)

No analytics, ads, or unrelated third-party tracking scripts are loaded by this plugin. Admin and checkout assets are loaded from your WordPress site. Bundled third-party libraries and their licenses are listed in `THIRD-PARTY-LICENSES.txt`.

== Screenshots ==

1. VisualPay merchant overview dashboard
2. Transactions list with filters and search
3. VisualPay Settings screen

== Changelog ==

= 1.0.2 =
* Update bundled Bootstrap from 5.3.3 to 5.3.8 (JS + CSS)
* Update bundled qrcode (node-qrcode) from 1.5.1 to 1.5.4

= 1.0.1 =
* Plugin Check fixes: input sanitization, nonce phpcs annotations, database query annotations
* Remove UTF-8 BOM from index.php guard files
* Align text domain with wordpress.org slug (visualpay-for-woocommerce)

= 1.0.0 =
* WordPress.org preparation: GPLv2 license, readme.txt, uninstall cleanup
* Stop changing site permalink structure on activation
* Remove remote Google Fonts; use system font stacks
* Load plugin textdomain; internationalize admin UI strings
* Document external VisualPay API usage and third-party libraries

== Upgrade Notice ==

= 1.0.2 =
Updates bundled Bootstrap and qrcode libraries to the latest stable versions.

= 1.0.1 =
Plugin Check and coding standards compliance updates.

= 1.0.0 =
Licensing and wordpress.org compliance updates. After updating, open Settings → Permalinks and click Save if the payment page URL is not working.
