<?php
/**
 * TNXL Order Integration
 */

if (!defined('ABSPATH')) exit;

class TNXL_Order {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (TNXL_Settings::is_auto_shipment_enabled() && TNXL_Settings::are_services_active()) {
            add_action('woocommerce_order_status_processing', array($this, 'queue_shipment_creation'));
            add_action('woocommerce_order_status_completed', array($this, 'queue_shipment_creation'));
            add_action('tnxl_create_shipment_async', array($this, 'auto_create_shipment'));
        }
        add_action('add_meta_boxes', array($this, 'add_shipment_meta_box'));
    }

    /**
     * Queue shipment creation in the background
     */
    public function queue_shipment_creation($order_id) {
        if (!TNXL_Settings::can_auto_create_shipment()) {
            return;
        }

        $order_id = absint($order_id);
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_tnxl_shipment_complete') === 'yes') {
            return;
        }

        // Avoid duplicate processing/completed queue jobs for the same order.
        if (function_exists('as_has_scheduled_action')
            && as_has_scheduled_action('tnxl_create_shipment_async', array($order_id), 'tnxl-shipping')) {
            return;
        }

        if (!function_exists('as_enqueue_async_action')
            && wp_next_scheduled('tnxl_create_shipment_async', array($order_id))) {
            return;
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('tnxl_create_shipment_async', array($order_id), 'tnxl-shipping');
        } else {
            wp_schedule_single_event(time(), 'tnxl_create_shipment_async', array($order_id));
        }
    }

    /**
     * Auto-create shipment on TNXL platform
     */
    public function auto_create_shipment($order_id) {
        if (is_array($order_id)) {
            $order_id = isset($order_id['order_id']) ? absint($order_id['order_id']) : 0;
        }
        $order_id = absint($order_id);
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if (!TNXL_Settings::can_auto_create_shipment()) {
            return;
        }

        // Check if a TNXL rate was selected
        $shipping_methods = $order->get_shipping_methods();
        $tnxl_selected = false;
        foreach ($shipping_methods as $method) {
            if (strpos($method->get_method_id(), 'tnxl_shipping') !== false) {
                $tnxl_selected = true;
                break;
            }
        }

        if (!$tnxl_selected) {
            return;
        }

        // Fully completed multi-box run - nothing left to do.
        if ($order->get_meta('_tnxl_shipment_complete') === 'yes') {
            return;
        }

        // Legacy orders created before the complete flag: treat as finished.
        $legacy_request = $order->get_meta('_tnxl_request_number');
        $complete_flag = $order->get_meta('_tnxl_shipment_complete');
        $expected = absint($order->get_meta('_tnxl_expected_box_count'));
        if ($legacy_request && $complete_flag === '' && $expected <= 0) {
            return;
        }

        $lock_key = 'tnxl_ship_lock_' . $order_id;
        if (get_transient($lock_key)) {
            // Another worker is creating shipments - retry after the lock window.
            $this->schedule_shipment_retry($order_id, 60);
            return;
        }
        set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);

        try {
            $this->create_shipments_for_order($order);
            $order = wc_get_order($order_id);
            if ($order && $order->get_meta('_tnxl_shipment_complete') !== 'yes') {
                $this->schedule_shipment_retry($order_id, 120);
            }
        } catch (\Throwable $e) {
            if (defined('TNXL_DEBUG') && TNXL_DEBUG) {
                error_log('TNXL auto_create_shipment order ' . $order_id . ': ' . $e->getMessage());
            }
            $this->schedule_shipment_retry($order_id, 120);
        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Schedule a delayed shipment retry without stacking duplicate pending jobs.
     */
    private function schedule_shipment_retry(int $order_id, int $delay_seconds = 60): void {
        if ($order_id <= 0) {
            return;
        }

        if (function_exists('as_has_scheduled_action')
            && as_has_scheduled_action('tnxl_create_shipment_async', array($order_id), 'tnxl-shipping')) {
            return;
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay_seconds, 'tnxl_create_shipment_async', array($order_id), 'tnxl-shipping');
            return;
        }

        if (!wp_next_scheduled('tnxl_create_shipment_async', array($order_id))) {
            wp_schedule_single_event(time() + $delay_seconds, 'tnxl_create_shipment_async', array($order_id));
        }
    }

    /**
     * Create one Thai Nexus shipment per packed box, resuming after partial failures.
     */
    private function create_shipments_for_order(WC_Order $order): void {
        $api = TNXL_API::get_instance();

        $shipper = array(
            'name'        => get_option('tnxl_shipper_name', ''),
            'phone'       => get_option('tnxl_shipper_phone', ''),
            'address'     => get_option('tnxl_shipper_address', ''),
            'city'        => get_option('tnxl_shipper_city', ''),
            'state'       => get_option('tnxl_shipper_state', ''),
            'postal_code' => get_option('tnxl_shipper_postal_code', ''),
            'country'     => get_option('tnxl_shipper_country', 'TH'),
        );

        $consignee = array(
            'name'        => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'phone'       => $order->get_shipping_phone() ?: ($order->get_billing_phone() ?: '0000000000'),
            'address'     => $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2(),
            'city'        => $order->get_shipping_city(),
            'state'       => $order->get_shipping_state(),
            'postal_code' => $order->get_shipping_postcode(),
            'country'     => $order->get_shipping_country(),
        );

        $tnxl_items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->needs_shipping()) {
                $tnxl_items[] = array(
                    'data'     => $product,
                    'quantity' => $item->get_quantity(),
                );
            }
        }

        if (empty($tnxl_items) || !class_exists('TNXL_Box_Packer')) {
            if (method_exists($order, 'add_order_note')) {
                $order->add_order_note(
                    __('Thai Nexus auto-shipment skipped: no shippable items were found on the order.', 'thai-nexus-logistics')
                );
            }
            return;
        }

        $packer = TNXL_Box_Packer::get_instance();
        $packing_result = $packer->pack_items($tnxl_items);

        if ($packing_result->has_errors()) {
            $message = implode(' ', $packing_result->get_errors());
            $order->update_meta_data('_tnxl_shipment_errors', $packing_result->get_errors());
            $order->update_meta_data('_tnxl_shipment_complete', 'no');
            $order->save();
            if (method_exists($order, 'add_order_note')) {
                $order->add_order_note(sprintf(
                    /* translators: %s: packing validation errors */
                    __('Thai Nexus auto-shipment failed packing validation: %s', 'thai-nexus-logistics'),
                    $message
                ));
            }
            return;
        }

        $packed_boxes = $packing_result->get_all_shipment_boxes();
        if (empty($packed_boxes)) {
            $order->update_meta_data('_tnxl_shipment_errors', array(
                __('No packable boxes were produced for this order.', 'thai-nexus-logistics'),
            ));
            $order->update_meta_data('_tnxl_shipment_complete', 'no');
            $order->save();
            if (method_exists($order, 'add_order_note')) {
                $order->add_order_note(
                    __('Thai Nexus auto-shipment skipped: packing produced no boxes.', 'thai-nexus-logistics')
                );
            }
            return;
        }

        $expected_box_count = count($packed_boxes);
        $existing = $order->get_meta('_tnxl_all_shipments');
        $shipments_by_index = array();

        if (is_array($existing)) {
            foreach ($existing as $index => $shipment) {
                if (is_array($shipment) && !empty($shipment['request_number'])) {
                    $shipments_by_index[(int) $index] = $shipment;
                }
            }
        }

        $errors = array();

        foreach ($packed_boxes as $index => $box) {
            if (isset($shipments_by_index[$index])) {
                continue;
            }

            $box_items_desc = implode(', ', $box['items'] ?? []);
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
                    'actual_weight_kg'  => $box['weight'],
                    'length_cm'         => $box['length'],
                    'width_cm'          => $box['width'],
                    'height_cm'         => $box['height'],
                    'shipment_type'     => 'parcel',
                    'shipment_description' => 'Box ' . ($index + 1) . '/' . $expected_box_count . ': ' . $box_items_desc,
                ),
            );

            $response = $api->shipment_crud('create', $payload);

            if (!is_wp_error($response) && isset($response['data']['request_number'])) {
                $shipments_by_index[$index] = $response['data'];
            } else {
                $errors[] = sprintf(
                    /* translators: 1: box number, 2: error message */
                    __('Box %1$d: %2$s', 'thai-nexus-logistics'),
                    $index + 1,
                    is_wp_error($response)
                        ? $response->get_error_message()
                        : __('Thai Nexus did not return a request number.', 'thai-nexus-logistics')
                );
            }
        }

        ksort($shipments_by_index);
        $created_count = count($shipments_by_index);
        $complete = $created_count >= $expected_box_count && empty($errors);

        // Keep box-index keys so a later retry can resume failed boxes without duplicates.
        $order->update_meta_data('_tnxl_packed_boxes', $packed_boxes);
        $order->update_meta_data('_tnxl_expected_box_count', $expected_box_count);
        $order->update_meta_data('_tnxl_all_shipments', $shipments_by_index);
        $order->update_meta_data('_tnxl_shipment_errors', $errors);
        $order->update_meta_data('_tnxl_shipment_complete', $complete ? 'yes' : 'no');

        if ($created_count > 0) {
            $primary = reset($shipments_by_index);
            $order->update_meta_data('_tnxl_shipment_id', $primary['id'] ?? '');
            $order->update_meta_data('_tnxl_request_number', $primary['request_number']);
            $order->update_meta_data('_tnxl_status', $primary['status'] ?? '');
        }

        $order->save();

        if (!empty($errors) && method_exists($order, 'add_order_note')) {
            $order->add_order_note(sprintf(
                /* translators: 1: created count, 2: expected count, 3: error details */
                __('Thai Nexus shipment creation incomplete (%1$d/%2$d). %3$s', 'thai-nexus-logistics'),
                $created_count,
                $expected_box_count,
                implode(' ', $errors)
            ));
        }
    }

    /**
     * Add Meta Box to Order Edit Screen
     */
    public function add_shipment_meta_box() {
        $screen = 'shop_order';
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && method_exists('\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
        }

        add_meta_box(
            'tnxl_shipment_details',
            __('Thai Nexus Shipment', 'thai-nexus-logistics'),
            array($this, 'render_meta_box'),
            $screen,
            'side',
            'default'
        );
    }

    public function render_meta_box($post_or_order) {
        if ($post_or_order instanceof WC_Order) {
            $order = $post_or_order;
        } elseif ($post_or_order instanceof WP_Post) {
            $order = wc_get_order($post_or_order->ID);
        } else {
            $order = wc_get_order($post_or_order);
        }

        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'thai-nexus-logistics') . '</p>';
            return;
        }
        $req_num = $order->get_meta('_tnxl_request_number');
        $status = $order->get_meta('_tnxl_status');
        $all_shipments = $order->get_meta('_tnxl_all_shipments');
        $packed_boxes = $order->get_meta('_tnxl_packed_boxes');

        if (!$req_num) {
            echo '<p>' . esc_html__('No TNXL shipment associated with this order.', 'thai-nexus-logistics') . '</p>';
            return;
        }

        $complete = $order->get_meta('_tnxl_shipment_complete');
        $expected = absint($order->get_meta('_tnxl_expected_box_count'));
        $errors = $order->get_meta('_tnxl_shipment_errors');

        echo '<div class="tnxl-order-meta" style="font-family: sans-serif;">';

        if ($complete !== 'yes') {
            $created = is_array($all_shipments) ? count($all_shipments) : 0;
            echo '<p style="color:#b45309;"><strong>' . esc_html__('Incomplete:', 'thai-nexus-logistics') . '</strong> ';
            echo esc_html(sprintf(
                /* translators: 1: created shipments, 2: expected shipments */
                __('%1$d of %2$d boxes created. Will retry on the next order status update.', 'thai-nexus-logistics'),
                $created,
                $expected ?: max($created, 1)
            ));
            echo '</p>';
            if (is_array($errors) && !empty($errors)) {
                echo '<p style="font-size:11px;color:#64748b;">' . esc_html(implode(' ', $errors)) . '</p>';
            }
        }
        if (!empty($all_shipments) && is_array($all_shipments)) {
            echo '<p><strong>' . esc_html__('Shipments:', 'thai-nexus-logistics') . '</strong></p>';
            echo '<ul style="margin: 0 0 15px 0; padding: 0; list-style: none;">';
            foreach ($all_shipments as $index => $shipment) {
                if (!is_array($shipment)) {
                    continue;
                }
                $box = is_array($packed_boxes) ? ($packed_boxes[$index] ?? null) : null;
                $box_info = $box ? " ({$box['length']}x{$box['width']}x{$box['height']} cm, {$box['weight']} kg)" : "";
                echo '<li style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #f0f0f1;">';
                echo '<code style="background: #f0f0f1; padding: 2px 4px; border-radius: 4px;">' . esc_html($shipment['request_number'] ?? '') . '</code>';
                echo '<span style="float: right; color: #dc2626; font-weight: bold; font-size: 11px; text-transform: uppercase;">' . esc_html($shipment['status'] ?? '') . '</span>';
                echo '<div style="font-size: 11px; color: #64748b; margin-top: 4px;">' . esc_html__('Box', 'thai-nexus-logistics') . ' ' . absint($index + 1) . esc_html($box_info) . '</div>';
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p><strong>' . esc_html__('Request Number:', 'thai-nexus-logistics') . '</strong> <code style="background: #f0f0f1; padding: 2px 4px; border-radius: 4px;">' . esc_html($req_num) . '</code></p>';
            echo '<p><strong>' . esc_html__('Status:', 'thai-nexus-logistics') . '</strong> <span style="color: #dc2626; font-weight: bold;">' . esc_html($status) . '</span></p>';
        }

        echo '<hr />';
        echo '<a href="' . esc_url(admin_url('admin.php?page=tnxl-logistics')) . '" class="button button-primary" style="background: #272262; border-color: #272262; width: 100%; text-align: center;">' . esc_html__('View in Dashboard', 'thai-nexus-logistics') . '</a>';
        echo '</div>';
    }
}

