<?php
/**
 * TNX Order Integration
 */

if (!defined('ABSPATH')) exit;

class TNX_Order {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_order_status_processing', array($this, 'queue_shipment_creation'));
        add_action('woocommerce_order_status_completed', array($this, 'queue_shipment_creation'));
        add_action('tnx_create_shipment_async', array($this, 'auto_create_shipment'));
        add_action('add_meta_boxes', array($this, 'add_shipment_meta_box'));
    }

    /**
     * Queue shipment creation in the background
     */
    public function queue_shipment_creation($order_id) {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('tnx_create_shipment_async', array('order_id' => $order_id));
        } else {
            wp_schedule_single_event(time(), 'tnx_create_shipment_async', array('order_id' => $order_id));
        }
    }

    /**
     * Auto-create shipment on TNX platform
     */
    public function auto_create_shipment($order_id) {
        error_log("TNX Debug: auto_create_shipment triggered for Order #$order_id");
        $order = wc_get_order($order_id);
        
        // Check if a TNX rate was selected
        $shipping_methods = $order->get_shipping_methods();
        $tnx_selected = false;
        foreach ($shipping_methods as $method) {
            error_log("TNX Debug: Order Shipping Method ID: " . $method->get_method_id());
            if (strpos($method->get_method_id(), 'tnx_shipping') !== false) {
                $tnx_selected = true;
                break;
            }
        }

        if (!$tnx_selected) {
            error_log("TNX Debug: No TNX shipping method found for Order #$order_id");
            return;
        }

        // Skip if already created
        if ($order->get_meta('_tnx_request_number')) {
            error_log("TNX Debug: Shipment already exists for Order #$order_id");
            return;
        }

        $api = TNX_API::get_instance();
        
        // Prepare Shipper Data
        $shipper = array(
            'name'        => get_option('tnx_shipper_name', ''),
            'phone'       => get_option('tnx_shipper_phone', ''),
            'address'     => get_option('tnx_shipper_address', ''),
            'city'        => get_option('tnx_shipper_city', ''),
            'state'       => get_option('tnx_shipper_state', ''),
            'postal_code' => get_option('tnx_shipper_postal_code', ''),
            'country'     => get_option('tnx_shipper_country', 'TH'),
        );

        // Prepare Consignee Data
        $consignee = array(
            'name'        => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'phone'       => $order->get_shipping_phone() ?: ($order->get_billing_phone() ?: '0000000000'), // Fallback to billing or placeholder
            'address'     => $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2(),
            'city'        => $order->get_shipping_city(),
            'state'       => $order->get_shipping_state(),
            'postal_code' => $order->get_shipping_postcode(),
            'country'     => $order->get_shipping_country(),
        );

        // Aggregate Product Dims
        $total_weight = 0;
        $max_length = 0;
        $max_width = 0;
        $max_height = 0;
        $items_desc = array();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $qty = $item->get_quantity();
            
            $total_weight += ((float)$product->get_weight() ?: 0.5) * $qty;
            $max_length = max($max_length, (float)$product->get_length() ?: 10);
            $max_width = max($max_width, (float)$product->get_width() ?: 10);
            $max_height = max($max_height, (float)$product->get_height() ?: 10);
            
            $items_desc[] = $product->get_name();
        }

        $payload = array(
            'data' => array(
                'shipper_address'   => array(
                    'name'          => $shipper['name'],
                    'phone'         => $shipper['phone'],
                    'address_line1' => $shipper['address'],
                    'city'          => $shipper['city'],
                    'state'         => $shipper['state'],
                    'postal_code'   => $shipper['postal_code'],
                    'country'       => $shipper['country'],
                ),
                'consignee_address' => array(
                    'name'          => $consignee['name'],
                    'phone'         => $consignee['phone'],
                    'address_line1' => $consignee['address'],
                    'city'          => $consignee['city'],
                    'state'         => $consignee['state'],
                    'postal_code'   => $consignee['postal_code'],
                    'country'       => $consignee['country'],
                ),
                'actual_weight_kg'  => $total_weight,
                'length_cm'         => $max_length,
                'width_cm'          => $max_width,
                'height_cm'         => $max_height,
                'shipment_type'     => 'parcel',
                'shipment_description' => implode(', ', $items_desc),
            )
        );

        error_log("TNX Debug: Creating shipment with payload: " . json_encode($payload));
        $response = $api->shipment_crud('create', $payload);
        error_log("TNX Debug: API Response: " . json_encode($response));

        if (!is_wp_error($response) && isset($response['data']['request_number'])) {
            $shipment_data = $response['data'];
            $order->update_meta_data('_tnx_shipment_id', $shipment_data['id']);
            $order->update_meta_data('_tnx_request_number', $shipment_data['request_number']);
            $order->update_meta_data('_tnx_status', $shipment_data['status']);
            $order->save();
        }
    }

    /**
     * Add Meta Box to Order Edit Screen
     */
    public function add_shipment_meta_box() {
        add_meta_box(
            'tnx_shipment_details',
            __('Thai Nexus Shipment', 'thai-nexus-logistics'),
            array($this, 'render_meta_box'),
            'shop_order',
            'side',
            'default'
        );
    }

    public function render_meta_box($post) {
        $order = wc_get_order($post->ID);
        $req_num = $order->get_meta('_tnx_request_number');
        $status = $order->get_meta('_tnx_status');

        if (!$req_num) {
            echo '<p>' . __('No TNX shipment associated with this order.', 'thai-nexus-logistics') . '</p>';
            return;
        }

        echo '<div class="tnx-order-meta" style="font-family: sans-serif;">';
        echo '<p><strong>' . __('Request Number:', 'thai-nexus-logistics') . '</strong> <code style="background: #f0f0f1; padding: 2px 4px; border-radius: 4px;">' . esc_html($req_num) . '</code></p>';
        echo '<p><strong>' . __('Status:', 'thai-nexus-logistics') . '</strong> <span style="color: #dc2626; font-weight: bold;">' . esc_html($status) . '</span></p>';
        echo '<hr />';
        echo '<a href="' . admin_url('admin.php?page=tnx-logistics') . '" class="button button-primary" style="background: #272262; border-color: #272262;">' . __('View in Dashboard', 'thai-nexus-logistics') . '</a>';
        echo '</div>';
    }
}
