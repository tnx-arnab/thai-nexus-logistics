# Thai Nexus Logistics for WooCommerce

Real-time shipping quotations and automated shipment creation via Thai Nexus API. This plugin integrates seamless logistics management into your WooCommerce store, including dynamic currency conversion for global customers.

## 🚀 Key Features

- **Real-time Shipping Quotes**: Automatically fetches shipping rates from Thai Nexus API based on product dimensions, weight, and destination.
- **Dynamic Currency Conversion**: 
    - Automatically converts THB-based API rates into your website's active currency.
    - Displays converted prices (e.g., USD) next to product prices using the Frankfurter API.
    - Caches exchange rates for 24 hours to ensure high performance.
- **Optimized Checkout Flow**:
    - Hides shipping rates on the cart page to reduce clutter and focus on product subtotal.
    - Full calculation and display on the checkout page where destination details are confirmed.
- **Modern Admin Dashboard**: A sleek React & Vite-powered admin interface for managing shipments and settings.
- **Block Support**: Full compatibility with the modern WooCommerce Checkout and Cart blocks.

## 🛠 Installation

1. Download the plugin folder.
2. Upload the `thai-nexus-logistics` folder to your WordPress site's `/wp-content/plugins/` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Ensure WooCommerce is installed and active.

## ⚙️ Configuration

1. Navigate to **Thai Nexus** in your WordPress sidebar.
2. Enter your API credentials in the Settings tab.
3. Enable the shipping method in **WooCommerce > Settings > Shipping > Thai Nexus Shipping**.

## 💻 Technical Stack

- **Backend**: PHP 7.4+, WordPress 5.8+, WooCommerce.
- **Frontend (Admin)**: React, Vite, Tailwind CSS.
- **External APIs**: 
    - **Thai Nexus API**: For real-time logistics.
    - **Frankfurter API**: For currency exchange rates.

## 📄 License

This project is proprietary and built for Thai Nexus Logistics.
