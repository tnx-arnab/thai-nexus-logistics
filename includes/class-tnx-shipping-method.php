<?php
/**
 * TNX Shipping Method
 */

if (!defined('ABSPATH')) exit;

class TNX_Shipping_Method extends WC_Shipping_Method {

    public function __construct($instance_id = 0) {
        $this->id = 'tnx_shipping';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Thai Nexus Logistics', 'thai-nexus-logistics');
        $this->method_description = __('Real-time shipping quotations from Thai Nexus.', 'thai-nexus-logistics');
        $this->supports = array('shipping-zones', 'instance-settings');

        $this->init();
    }

    public function init() {
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title', __('Thai Nexus Shipping', 'thai-nexus-logistics'));
        $this->enabled = $this->get_option('enabled', 'yes');

        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'thai-nexus-logistics'),
                'type'    => 'checkbox',
                'label'   => __('Enable Thai Nexus Shipping', 'thai-nexus-logistics'),
                'default' => 'yes',
            ),
            'title'   => array(
                'title'       => __('Method Title', 'thai-nexus-logistics'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'thai-nexus-logistics'),
                'default'     => __('Thai Nexus Shipping', 'thai-nexus-logistics'),
                'desc_tip'    => true,
            ),
        );
    }

    public function calculate_shipping($package = array()) {
        $debug_enabled = TNX_Debug_Logger::is_enabled();
        if ($debug_enabled) {
            TNX_API::$last_debug_data = array(); // Reset
        }

        error_log("TNX Debug: Calculating shipping for " . count($package['contents']) . " items.");
        $dest = $package['destination'];

        if ($this->enabled === 'no') {
            return;
        }

        $items = $package['contents'];
        $tnx_items = array();
        $debug_products = array();

        // Process all items in the package
        foreach ($items as $item_id => $values) {
            $tnx_items[] = $values;
            if ($debug_enabled) {
                $product = $values['data'];
                $debug_products[] = array(
                    'id'    => $product->get_id(),
                    'title' => $product->get_name(),
                    'qty'   => $values['quantity'],
                    'dimensions' => sprintf('%sx%sx%s cm', $product->get_length(), $product->get_width(), $product->get_height()),
                    'weight' => $product->get_weight() . ' kg',
                );
            }
        }

        if (empty($tnx_items)) {
            return;
        }

        // 3D Box Packing Implementation
        $packer = TNX_Box_Packer::get_instance();
        $packing_result = $packer->pack_items($tnx_items);

        if ($packing_result->has_errors()) {
            foreach ($packing_result->get_errors() as $error) {
                wc_add_notice($error, 'error');
            }
            return;
        }

        $packed_boxes = $packing_result->get_all_shipment_boxes();
        error_log("TNX Debug: Packing complete. Boxes to quote: " . count($packed_boxes));

        if (empty($packed_boxes)) {
            return;
        }

        $all_quotes = array();
        $target_currency = get_woocommerce_currency();
        $rate = TNX_Currency::get_instance()->get_rate('THB', $target_currency);
        $commission = TNX_Commission::get_instance()->get_total_commission();

        foreach ($packed_boxes as $box) {
            $response = TNX_API::get_instance()->get_quote(array(
                'country'           => $dest['country'],
                'state'             => $dest['state'],
                'postcode'          => $dest['postcode'],
                'city'              => $dest['city'],
                'actual_weight_kg'  => $box['weight'],
                'length_cm'         => $box['length'],
                'width_cm'          => $box['width'],
                'height_cm'         => $box['height'],
            ));

            if (is_wp_error($response) || !isset($response['quotes'])) {
                continue;
            }

            foreach ($response['quotes'] as $quote) {
                $courier = $quote['courier_name'];
                if (!isset($all_quotes[$courier])) {
                    $all_quotes[$courier] = array(
                        'display_name'   => $quote['display_name'],
                        'estimated_days' => $quote['estimated_days'],
                        'total_price'    => 0,
                        'count'          => 0,
                    );
                }
                $all_quotes[$courier]['total_price'] += (float) $quote['final_price_thb'];
                $all_quotes[$courier]['count']++;
            }
        }

        // Only show couriers that could quote ALL boxes
        $box_count = count($packed_boxes);
        $final_quotes_debug = array();

        foreach ($all_quotes as $courier => $data) {
            if ($data['count'] < $box_count) continue;

            $cost = $data['total_price'];
            if ($rate) {
                $cost = $cost * $rate;
            }

            // Add hidden commission buffer
            $cost += $commission;

            // Add destination hash to ID to force WooCommerce to refresh rates when address changes
            $rate_id = 'tnx_' . sanitize_title($courier) . '_' . substr(md5($dest['country'] . $dest['postcode']), 0, 6);

            $this->add_rate(array(
                'id'    => $rate_id,
                'label' => $data['display_name'] . ' (' . ($data['estimated_days'] ?: 'TBA') . ' days)',
                'cost'  => $cost,
                'meta_data' => array(
                    'tnx_courier' => $courier,
                    'tnx_boxes'   => $packed_boxes,
                    'tnx_breakdown' => array(
                        'base_price' => $cost - $commission,
                        'commission' => $commission,
                        'total'      => $cost
                    )
                )
            ));

            if ($debug_enabled) {
                $final_quotes_debug[] = array(
                    'courier' => $data['display_name'],
                    'price_thb' => $data['total_price'],
                    'final_cost' => $cost,
                    'days' => $data['estimated_days'] ?: 'TBA',
                );
            }
        }

        // Save Debug Log
        if ($debug_enabled) {
            TNX_Debug_Logger::get_instance()->log_entry(array(
                'products'       => $debug_products,
                'boxes'          => $packed_boxes,
                'box_count'      => count($packed_boxes),
                'api_calls'      => TNX_API::$last_debug_data,
                'destination'    => $dest,
                'final_quotes'   => $final_quotes_debug,
                'currency'       => $target_currency,
                'exchange_rate'  => $rate,
                'commission'     => $commission,
            ));
        }
    }
}
