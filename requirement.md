# Plugin Requirements Specification: Thai Nexus Logistics

## 1. Overview
**Plugin Name:** Thai Nexus Logistics  
**Platform:** WordPress / WooCommerce  
**Description:** A shipping and logistics integration plugin that fetches real-time shipping quotations from the Thai Nexus Logistics (TNX) API during checkout, allows customers to select a shipping rate, and automatically generates shipment requests in the TNX platform upon order placement. 

---

## 2. Admin Dashboard & Settings
The plugin will register a custom menu in the WordPress Admin dashboard. 

### 2.1 UI/UX Design & Technology
* **Frontend Stack:** Built with **React** and **Tailwind CSS** for a high-performance, modern interface.
* **Build System:** Vite-based bundling.
* **Theme:** Modern, SaaS-style interface. 
* **Color Palette:**
    * Primary Accent: **#dc2626** (Red)
    * Secondary/Navigation: **#272262** (Dark Blue/Purple)
    * Background/Cards: **#f9fafb** (Light Gray) / **#fff** (White)

### 2.2 Settings Tab
* **API Authentication:** * Input field for `User Token`.
    * **Help Text below input:** `"https://app.thainexus.co.th/ > profile settings > api token"`
* **Store Origin Address:** Fields to define the Shipper's origin address (Name, Phone, Address, City, Country) to be passed into the API during shipment creation.

### 2.3 Shipments Dashboard Tab
* **List View:** A data table displaying a paginated list of shipments associated with the authenticated account.
* **Data Source:** Fetched via the `shipmentCrud` endpoint using `action: "list"`.
* **Displayed Columns:** Request Number, Status, Volumetric Weight, Submitted Date.

---

## 3. WooCommerce Product Settings
Integration into the native WooCommerce "Product Data" meta box (specifically under the "Shipping" tab).

* **Enable TNX Shipping (Checkbox):** A per-product toggle allowing the admin to enable or disable TNX shipping quotes for that specific item.
* **Dimension Dependencies:** The plugin must verify that the native WooCommerce Length, Width, Height, and Weight fields are populated. These are mandatory for the TNX `apiQuote` endpoint.

---

## 4. Frontend Checkout Integration
When a customer reaches the WooCommerce checkout page, the plugin will hook into the shipping rate calculation process.

### 4.1 Quotation Retrieval
* **Condition:** If the cart contains a product with "Enable TNX Shipping" checked AND has valid L/W/H/Weight data.
* **Action:** The plugin sends a `POST` request to `https://app.thainexus.co.th/apiQuote`.
* **Payload Mapping:**
    * `country`: Extracted from the customer's shipping address.
    * `actual_weight_kg`, `length_cm`, `width_cm`, `height_cm`: Extracted from the product data. 

### 4.2 Displaying Rates
* **UI:** The available quotes returned from the API (`courier_name`, `display_name`, `final_price_thb`, `estimated_days`) will be injected into the WooCommerce shipping options list.
* **Selection Constraint:** The user can select **maximum one** TNX quotation for their order.
* The selected quote's `final_price_thb` will be added to the WooCommerce order total.

---

## 5. Order Processing & Shipment Creation
Automation of shipment data transfer when an order is successfully placed.

### 5.1 Auto-Create Shipment
* **Trigger:** Upon successful order placement and payment confirmation (e.g., WooCommerce status transitions to "Processing").
* **Action:** Send a `POST` request to `shipmentCrud` with `action: "create"`.
* **Payload Mapping:**
    * `shipper_address`: Pulled from Plugin Admin Settings.
    * `consignee_address`: Pulled from WooCommerce Order Shipping Details.
    * `actual_weight_kg`, `length_cm`, etc.: Pulled from the product data.
    * `shipment_type` / `shipment_description`: Automatically populated based on the ordered product name/category.

### 5.2 Order Meta & Visibility
* **Save Response:** The plugin must save the returned `id`, `request_number`, and `status` as WooCommerce Order Meta.
* **Admin Order View:** Add a custom meta box on the WooCommerce Order editing screen displaying the attached TNX shipment details (Request Number, Status) and a button to view it in the plugin's Shipments Dashboard.

---

## 6. API Integration Matrix

All requests communicate with the base URL: `https://app.thainexus.co.th/`. 

| Feature / Trigger | Endpoint | Payload Action / Method | Key Required Fields |
| :--- | :--- | :--- | :--- |
| **Get Shipping Rates** (Checkout Page) | `/apiQuote` | `POST` | `api_token`, `country`, `actual_weight_kg`, `length_cm`, `width_cm`, `height_cm` |
| **List Shipments** (Admin Dashboard) | `shipmentCrud` | `POST` (`action: "list"`) | `api_token`, `page`, `limit` |
| **Create Shipment** (Order Placed) | `shipmentCrud` | `POST` (`action: "create"`) | `api_token`, `shipper_address`, `consignee_address`, dims/weight |
| **View Shipment Details** (Order Page) | `shipmentCrud` | `POST` (`action: "get"`) | `api_token`, `request_number` |

### Error Handling & Edge Cases
* **Missing API Token:** Disable TNX shipping options on checkout and display an admin notice in the backend.
* **API Timeout/Failure:** If the `apiQuote` endpoint fails or times out, fallback to default WooCommerce shipping methods gracefully without breaking the checkout page.
* **Soft-Deletes:** Ensure the plugin UI respects the soft-delete rules (e.g., hiding shipments locally if they are not returned by the `action: "list"` endpoint).