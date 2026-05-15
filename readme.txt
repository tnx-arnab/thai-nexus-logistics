=== Thai Nexus Logistics ===
Contributors: thainexus
Tags: shipping, logistics, woocommerce, thailand, currency conversion
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.5.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Real-time shipping quotations and automated shipment creation via Thai Nexus API.

== Description ==

Real-time shipping quotations and automated shipment creation via Thai Nexus API. This plugin integrates seamless logistics management into your WooCommerce store, including dynamic currency conversion for global customers.

= Features =

* **Real-time Shipping Quotes**: Automatically fetches shipping rates from Thai Nexus API based on product dimensions, weight, and destination.
* **Dynamic Currency Conversion**: Automatically converts THB-based API rates into your website's active currency.
* **Optimized Checkout Flow**: Hides shipping rates on the cart page and displays them on the checkout page.
* **Modern Admin Dashboard**: A sleek React & Vite-powered admin interface.
* **Block Support**: Full compatibility with the modern WooCommerce Checkout and Cart blocks.

== Privacy Disclosure ==

This plugin communicates with external APIs to provide logistics services:
* **Thai Nexus API (app.thainexus.co.th)**: We send customer shipping addresses, product weights, and dimensions to calculate real-time shipping rates and create shipments.
* **Frankfurter API (api.frankfurter.app)**: We send currency codes to fetch the latest exchange rates for price conversion.

No personal identifiable information (PII) other than shipping destination details is transmitted for rate calculation.

== Installation ==

1. Upload the `thai-nexus-logistics` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure WooCommerce is installed and active.

== Frequently Asked Questions ==

= Do I need a Thai Nexus account? =
Yes, you need an active account and an API token from [Thai Nexus](https://app.thainexus.co.th/).

== Screenshots ==

1. The modern admin dashboard.
2. Shipping rates at checkout.

== Changelog ==

= 1.5.3 =
* Security: Implemented nonce verification for product metadata saving.
* Security: Redacted API tokens from debug logs.
* Compliance: Disables debug mode by default for production.
* Compliance: Improved late-stage escaping for all admin and order displays.

= 1.5.2 =
* Initial public release standards compliance.
* Added GPLv2 licensing.
* Added privacy disclosures.
