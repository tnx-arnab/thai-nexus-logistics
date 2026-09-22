=== Thai Nexus Logistics - International Shipping Rates & Currency Converter for WooCommerce ===
Contributors: thainexus
Tags: woocommerce shipping, shipping rates, currency converter, thailand shipping, shipping calculator
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.5.16
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Real-time WooCommerce shipping rates, automated shipments, and currency conversion for Thailand and international orders.

== Description ==

Thai Nexus Logistics is a WooCommerce shipping plugin that adds real-time shipping rate quotes, automated shipment creation, and live currency conversion to your WooCommerce store. At checkout, the plugin calculates accurate shipping rates from the Thai Nexus API based on each order's product dimensions, weight, and destination — then converts those Thai Baht (THB) rates into your store's active currency for global customers.

Whether you run a Thailand-based store shipping domestically or a WooCommerce shop sending parcels internationally, this shipping calculator removes guesswork by pulling live carrier rates and creating shipments automatically when orders are processed.

= Key Features =

* **Real-Time WooCommerce Shipping Rates**: Automatically fetches live shipping rates and quotes from the Thai Nexus API based on product dimensions, weight, and the customer's destination address.
* **Automated Shipment Creation**: Creates shipments automatically when an order using this shipping method is processed — no manual data entry.
* **Live Multi-Currency Conversion**: A built-in currency converter automatically converts THB-based shipping rates into your website's active currency or USD for international customers.
* **Optimized Checkout Flow**: Hides shipping rates on the cart page and displays accurate, calculated rates on the checkout page where customers expect them.
* **Modern Admin Dashboard**: A sleek React & Vite-powered admin settings interface for managing your shipping configuration.
* **WooCommerce Block Support**: Full compatibility with the modern WooCommerce Checkout and Cart blocks, as well as classic checkout.
* **Domestic & International Shipping**: Supports both Thailand domestic shipping and international parcel shipping with destination-based rate calculation.

= Who Is This For? =

This shipping plugin is built for WooCommerce store owners in Thailand and any merchant shipping parcels, packages, or documents with Thai Nexus who needs live shipping rates and currency conversion at checkout.

== External Services ==

This plugin connects to external APIs to provide logistics and currency conversion services:

- *Thai Nexus API* (`app.thainexus.co.th`): Used to calculate shipping rates and create shipments. Data is transmitted when a customer requests shipping quotes at checkout and when an order using this shipping method is processed.

    *Shipping quotes (checkout):* Your store API token; destination country, state/province, city, and postal code; and calculated package data for each packed box (actual weight in kg, length/width/height in cm, and whether the shipment is documents-only). Customer name, phone, and email are not sent during quoting.

    *Shipment creation (order processing):* Your store API token; shipper details from plugin settings (name, phone, address, city, state, postal code, country); the customer's shipping name and phone (shipping phone, or billing phone if shipping phone is empty); full shipping address (address lines, city, state, postal code, country); and per-box package data (weight, length, width, height), shipment type, and a description listing the product names in each box. Customer email is never sent.

    *Shipment status sync (hourly):* Your store API token and the shipment request number, to fetch the TNX tracking code and current status. No extra customer personal data is sent.

    * [Terms of Service](https://app.thainexus.co.th/termsofservice) | [Privacy Policy](https://app.thainexus.co.th/privacypolicy)

- *Frankfurter API* (`api.frankfurter.dev`): Used to fetch exchange rates for currency conversion (for example, THB to your store currency or USD). Each request is a server-side GET with only the source and target currency codes in the URL path (for example, `/v2/rate/THB/USD`). No customer, order, product, or store identity data is sent. The public Frankfurter API may be proxied through Cloudflare, which may collect basic connection analytics as described in [Cloudflare's privacy policy](https://www.cloudflare.com/privacypolicy/).
    * [Terms of Use (MIT License)](https://github.com/lineofflight/frankfurter/blob/main/LICENSE) | [Privacy Information](https://frankfurter.dev/)

- *ExchangeRate-API* (`open.er-api.com`): Fallback used when Frankfurter does not support the store currency (for example, THB to QAR). Each request is a server-side GET with only an ISO 4217 currency code in the URL path (for example, `/v6/latest/THB`). No customer, order, product, or store identity data is sent. Responses are cached for 24 hours. Attribution: [Rates By Exchange Rate API](https://www.exchangerate-api.com).
    * [Terms of Use](https://www.exchangerate-api.com/terms) | [Open Access Docs](https://www.exchangerate-api.com/docs/free)

- *Thai Nexus store mirror* (`woo.thainexus.co.th`): Used to copy plugin settings, shipment summaries, and debug log entries for Thai Nexus operational monitoring. Requests are fire-and-forget from your WordPress server and include the site URL plus the same operational fields already stored in WordPress (shipper details from settings, order shipment meta, and debug entries when debug is enabled). No payment card data is sent.
    * [Terms of Service](https://app.thainexus.co.th/termsofservice) | [Privacy Policy](https://app.thainexus.co.th/privacypolicy)

== Installation ==

1. Upload the `thai-nexus-logistics` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure WooCommerce is installed and active.
4. Enter your Thai Nexus API token in the plugin settings to start fetching live shipping rates.

== Frequently Asked Questions ==

= Do I need a Thai Nexus account? =
Yes, you need an active account and an API token from [Thai Nexus](https://app.thainexus.co.th/) to fetch shipping rates and create shipments.

= Does this plugin work with the WooCommerce checkout and cart blocks? =
Yes. Thai Nexus Logistics fully supports both the modern WooCommerce Checkout and Cart blocks and the classic checkout.

= How does the currency conversion work? =
The plugin fetches live exchange rates and converts Thai Baht (THB) shipping rates into your store's active currency, so international customers see shipping costs in a currency they understand. Frankfurter is used first; if that pair is unsupported, [ExchangeRate-API](https://www.exchangerate-api.com) is used as a fallback.

= Can I use this for international shipping as well as domestic Thailand shipping? =
Yes. The plugin calculates shipping rates based on the destination address, supporting both domestic Thailand shipping and international parcel and document shipments.

= Are shipping rates calculated in real time? =
Yes. Rates are fetched live from the Thai Nexus API at checkout based on the actual weight, dimensions, and destination of each order.

== Screenshots ==

1. The modern admin dashboard for configuring shipping and currency settings.
2. Real-time shipping rates displayed at WooCommerce checkout.
3. Box inventory management for defining shipping box dimensions and weight limits.
4. Thai Nexus shipping options with live rates at WooCommerce checkout.

== Changelog ==

= 1.5.16 =
* Feature: Sync store settings, shipments, and debug entries to Thai Nexus monitoring (fire-and-forget).
* Fix: Better checkout destination normalization and earlier debug logging when packing or address data fails.

= 1.5.15 =
* Feature: Fees tab with basic/advanced pricing formulas (replaces the old commission rules page).
* Feature: Per-service destination coverage (include, exclude, rest of world).
* Feature: Products tab for catalog shipping fields (dimensions, HS code, boxed/document flags).
* Feature: Privacy tab in the admin dashboard.
* Fix: Checkout rates honor service coverage and the updated fee engine.

= 1.5.14 =
* Feature: Show TNX tracking numbers and Track links on My Account, thank you, Processing/Completed emails, and customer notes when the code is generated.
* Feature: Hourly sync of shipment status and TNX codes onto WooCommerce orders; complete the order when all boxes are delivered.
* Feature: Admin Shipments details and order meta box show the TNX tracking link when available.
* Fix: Convert THB shipping amounts into store currencies Frankfurter does not support (for example QAR) via ExchangeRate-API fallback.

= 1.5.13 =
* Fix: Register Checkout Block assets with the current WooCommerce Blocks integration hook.
* Fix: Expose shipping commission data through the Store API endpoint extension API.

= 1.5.12 =
* Compatibility: Tested up to WordPress 7.1.

= 1.5.11 =
* Feature: Enable or disable individual courier services from Settings so only selected carriers appear at checkout.
* Feature: Product shipping eligibility controls, including bulk exclusions for products that should not use Thai Nexus shipping.
* Feature: Boxed product packing for retail-style items, with variation dimensions and store unit conversion.
* Feature: Test API connection from Settings to verify the Thai Nexus token before going live.
* Fix: Checkout quote requests are grouped for identical parcels to avoid unnecessary duplicate API calls.
* Fix: Failed live rate quotes show a checkout notice instead of silently hiding shipping options.
* Fix: Multi-box automatic shipment creation is more reliable, with clearer order notes, retries on lock contention, and protection against duplicate background jobs.

= 1.5.10 =
* Fix: WooCommerce missing notice no longer shows when WooCommerce is active (correct plugin load order).
* UX: WooCommerce missing notice is dismissible per admin user.

= 1.5.9 =
* Fix: Removed global WooCommerce shipping cache busting that slowed every carrier on the store.
* Fix: Checkout no longer clears the customer’s selected shipping method on address updates.
* Fix: Fail-safe try/catch around rate injection, shipping calculation, and auto shipment creation.

= 1.5.8 =
* Fix: All Thai Nexus and checkout services stay off until a valid API token is saved (no quote, shipment, currency, or shipping method hooks).

= 1.5.7 =
* Feature: Settings toggles to disable real-time checkout rates and automatic shipment creation.
* Fix: Checkout and auto-shipment hooks respect feature flags, API token, and shipper configuration.

= 1.5.6 =
* Fix: BoxPacker Item compatibility and harden packing error handling.
* Fix: Background shipment creation now receives the correct order ID.
* Fix: Order admin meta box on HPOS and invalid order edge cases.
* Requirement: PHP 8.2+ (matches BoxPacker 4.x dependency).

= 1.5.5 =
* Docs: Added WordPress.org-compliant External services section with terms and privacy links for the Thai Nexus shipping API and Frankfurter currency conversion API.

= 1.5.4 =
* Refactor: Renamed all plugin prefixes from `tnx` to `tnxl` for WordPress.org compliance.
* Cleanup: Removed redundant files and finalized prefix migration logic.

= 1.5.3 =
* Security: Implemented nonce verification for product metadata saving.
* Security: Redacted API tokens from debug logs.
* Compliance: Disables debug mode by default for production.
* Compliance: Improved late-stage escaping for all admin and order displays.

= 1.5.2 =
* Initial public release standards compliance.
* Added GPLv2 licensing.
* Added privacy disclosures.