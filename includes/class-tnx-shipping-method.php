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
        $dest = $package['destination'];


        if ($this->enabled === 'no') {

            return;
        }

        $items = $package['contents'];
        $tnx_items = array();

        // Process all items in the package
        foreach ($items as $item_id => $values) {
            $tnx_items[] = $values;
        }

        if (empty($tnx_items)) {
            return;
        }

        // Aggregate dimensions
        $total_weight = 0;
        $max_length = 0;
        $max_width = 0;
        $max_height = 0;

        foreach ($tnx_items as $item) {
            $product = $item['data'];
            $qty = $item['quantity'];

            $weight = (float) $product->get_weight() ?: 0.5; // Default if missing
            $length = (float) $product->get_length() ?: 10;
            $width = (float) $product->get_width() ?: 10;
            $height = (float) $product->get_height() ?: 10;

            $total_weight += ($weight * $qty);
            $max_length = max($max_length, $length);
            $max_width = max($max_width, $width);
            $max_height = max($max_height, $height);
        }

        $response = TNX_API::get_instance()->get_quote(array(
            'country'           => $dest['country'],
            'state'             => $dest['state'],
            'postcode'          => $dest['postcode'],
            'city'              => $dest['city'],
            'actual_weight_kg'  => $total_weight,
            'length_cm'         => $max_length,
            'width_cm'          => $max_width,
            'height_cm'         => $max_height,
        ));

        if (is_wp_error($response)) {

            return; // Fail gracefully
        }

        if (isset($response['quotes']) && is_array($response['quotes'])) {
            $target_currency = get_woocommerce_currency();
            $rate = TNX_Currency::get_instance()->get_rate('THB', $target_currency);
            $commission = TNX_Commission::get_instance()->get_total_commission();

            foreach ($response['quotes'] as $quote) {
                // Add destination hash to ID to force WooCommerce to refresh rates when address changes
                $rate_id = 'tnx_' . sanitize_title($quote['courier_name']) . '_' . substr(md5($dest['country'] . $dest['postcode']), 0, 6);
                
                $cost = (float) $quote['final_price_thb'];
                if ($rate) {
                    $cost = $cost * $rate;
                }

                // Add hidden commission buffer
                $cost += $commission;

                $this->add_rate(array(
                    'id'    => $rate_id,
                    'label' => $quote['display_name'] . ' (' . ($quote['estimated_days'] ?: 'TBA') . ' days)',
                    'cost'  => $cost,
                    'meta_data' => array(
                        'tnx_courier' => $quote['courier_name'],
                        'tnx_breakdown' => array(
                            'base_price' => $cost - $commission,
                            'commission' => $commission,
                            'total'      => $cost
                        )
                    )
                ));
            }
        }
    }
}
