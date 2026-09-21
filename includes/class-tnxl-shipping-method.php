<?php
/**
 * TNXL Shipping Method
 */

if (!defined('ABSPATH')) exit;

class TNXL_Shipping_Method extends WC_Shipping_Method {

    /** @var bool */
    private static $packing_errors_notified = false;

    public function __construct($instance_id = 0) {
        $this->id = 'tnxl_shipping';
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
        if (!TNXL_Settings::can_fetch_checkout_rates()) {
            return;
        }

        try {
            $this->calculate_shipping_internal($package);
        } catch (\Throwable $e) {
            if (defined('TNXL_DEBUG') && TNXL_DEBUG) {
                error_log('TNXL calculate_shipping: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $package
     */
    private function calculate_shipping_internal($package = array()) {
        $debug_enabled = TNXL_Debug_Logger::is_enabled();
        if ($debug_enabled) {
            TNXL_API::$last_debug_data = array(); // Reset
        }

        $dest = $package['destination'] ?? array();

        if ($this->enabled === 'no') {
            return;
        }

        if (empty($dest['country'])) {
            return;
        }

        $items = $package['contents'];
        $tnxl_items = array();
        $debug_products = array();
        $is_document = true;

        // Process all items in the package
        foreach ($items as $item_id => $values) {
            $product = $values['data'] ?? null;
            if (!$product instanceof WC_Product || !$product->needs_shipping()) {
                continue;
            }

            if (!TNXL_Product::is_shipping_eligible($product)) {
                return;
            }

            $tnxl_items[] = $values;

            // If any product is NOT a document, the whole shipment is not a document
            if (!TNXL_Product::is_document($product)) {
                $is_document = false;
            }

            if ($debug_enabled) {
                $debug_products[] = array(
                    'id'    => $product->get_id(),
                    'title' => $product->get_name(),
                    'qty'   => $values['quantity'],
                    'dimensions' => sprintf('%sx%sx%s cm', $product->get_length(), $product->get_width(), $product->get_height()),
                    'weight' => $product->get_weight() . ' kg',
                    'is_document' => TNXL_Product::is_document($product),
                    'is_boxed_product' => TNXL_Product::is_boxed_product($product),
                );
            }
        }

        if (empty($tnxl_items)) {
            return;
        }

        // 3D Box Packing Implementation
        if (!class_exists('TNXL_Box_Packer')) {
            return;
        }
        $packer = TNXL_Box_Packer::get_instance();
        $packing_result = $packer->pack_items($tnxl_items);

        if ($packing_result->has_errors()) {
            $this->maybe_add_packing_notices($packing_result->get_errors());
            return;
        }

        $packed_boxes = $packing_result->get_all_shipment_boxes();

        if (empty($packed_boxes)) {
            return;
        }

        $all_quotes = array();
        $target_currency = get_woocommerce_currency();
        $rate = false;
        if (class_exists('TNXL_Currency')) {
            $rate = TNXL_Currency::get_instance()->get_rate('THB', $target_currency);
        }
        $needs_conversion = strtoupper((string) $target_currency) !== 'THB';
        $has_rate = is_numeric($rate) && (float) $rate > 0;

        if ($needs_conversion && !$has_rate) {
            $this->maybe_add_packing_notices(array(
                __('Thai Nexus shipping rates could not be converted to your store currency. Please try again.', 'thai-nexus-logistics'),
            ));
            return;
        }

        $commission = 0;
        $commission_class = class_exists('TNXL_Commission');

        $quote_groups = array();
        foreach ($packed_boxes as $box) {
            $signature = md5(wp_json_encode(array(
                'weight' => round((float) $box['weight'], 6),
                'length' => round((float) $box['length'], 6),
                'width'  => round((float) $box['width'], 6),
                'height' => round((float) $box['height'], 6),
            )));
            if (!isset($quote_groups[$signature])) {
                $quote_groups[$signature] = array('box' => $box, 'count' => 0);
            }
            $quote_groups[$signature]['count']++;
        }

        $max_quote_requests = max(1, (int) apply_filters('tnxl_max_quote_requests', 10));
        if (count($quote_groups) > $max_quote_requests) {
            $this->maybe_add_packing_notices(array(sprintf(
                /* translators: %d: maximum unique parcel types per checkout */
                __('This cart requires more than the supported maximum of %d unique parcel quotes.', 'thai-nexus-logistics'),
                $max_quote_requests
            )));
            return;
        }

        foreach ($quote_groups as $group) {
            $box = $group['box'];
            $occurrences = $group['count'];
            $response = TNXL_API::get_instance()->get_quote(array(
                'country'           => $dest['country'],
                'state'             => $dest['state'],
                'postcode'          => $dest['postcode'],
                'city'              => $dest['city'],
                'actual_weight_kg'  => $box['weight'],
                'length_cm'         => $box['length'],
                'width_cm'          => $box['width'],
                'height_cm'         => $box['height'],
                'is_document'       => $is_document,
            ));

            if (is_wp_error($response)) {
                $this->maybe_add_packing_notices(array(
                    sprintf(
                        /* translators: %s: API error message */
                        __('Thai Nexus shipping rates are temporarily unavailable: %s', 'thai-nexus-logistics'),
                        $response->get_error_message()
                    ),
                ));
                return;
            }

            if (!isset($response['quotes']) || !is_array($response['quotes'])) {
                $this->maybe_add_packing_notices(array(
                    __('Unable to retrieve Thai Nexus shipping rates for one or more packages. Please try again.', 'thai-nexus-logistics'),
                ));
                return;
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
                $all_quotes[$courier]['total_price'] += (float) $quote['final_price_thb'] * $occurrences;
                $all_quotes[$courier]['count'] += $occurrences;
            }
        }

        $box_count = count($packed_boxes);
        $final_quotes_debug = array();
        $disabled_service_ids = TNXL_Settings::get_disabled_service_ids();
        $coverage = TNXL_Settings::get_service_coverage();
        $packed_weight = 0;
        foreach ($packed_boxes as $box) {
            $packed_weight += (float) ($box['weight'] ?? 0);
        }
        $pricing_context = $commission_class
            ? TNXL_Commission::cart_pricing_context(array(
                'destinationCountry' => (string) ($dest['country'] ?? ''),
                'cartWeightKg'       => $packed_weight,
            ))
            : array();

        $offered = array();
        foreach ($all_quotes as $courier => $data) {
            if ($data['count'] < $box_count) continue;
            $offered[] = array(
                'courier'      => $courier,
                'display_name' => $data['display_name'],
                'estimated_days' => $data['estimated_days'],
                'total_price'  => $data['total_price'],
            );
        }

        $offered = TNXL_Service_Coverage::filter_checkout_quotes(
            $offered,
            static function ($quote) {
                return array($quote['courier'], $quote['display_name']);
            },
            (string) ($dest['country'] ?? ''),
            $disabled_service_ids,
            $coverage
        );

        foreach ($offered as $row) {
            $cost_thb = (float) $row['total_price'];
            $quote_store = $cost_thb;
            if ($has_rate) {
                $quote_store = $cost_thb * (float) $rate;
            }
            $priced = $quote_store;
            $commission = 0.0;
            if ($commission_class) {
                $service_ids = TNXL_Service_Coverage::coverage_ids(array($row['courier'], $row['display_name']));
                $priced_row = TNXL_Commission::price_converted_quote(
                    $cost_thb,
                    $has_rate ? $rate : null,
                    array_merge($pricing_context, array('serviceIds' => $service_ids))
                );
                $quote_store = $priced_row['quote_store'];
                $priced = $priced_row['priced'];
                $commission = $priced_row['commission'];
            }
            $cost = $priced;

            $rate_id = 'tnxl_' . sanitize_title($row['courier']) . '_' . substr(md5($dest['country'] . $dest['postcode']), 0, 6);

            $this->add_rate(array(
                'id'    => $rate_id,
                'label' => $row['display_name'] . ' (' . ($row['estimated_days'] ?: 'TBA') . ' days)',
                'cost'  => $cost,
                'meta_data' => array(
                    'tnxl_courier' => $row['courier'],
                    'tnxl_courier_display' => $row['display_name'],
                    'tnxl_boxes'   => $packed_boxes,
                    'tnxl_breakdown' => array(
                        'base_price' => $quote_store,
                        'commission' => $commission,
                        'total'      => $cost
                    )
                )
            ));

            if ($debug_enabled) {
                $final_quotes_debug[] = array(
                    'courier' => $row['display_name'],
                    'price_thb' => $row['total_price'],
                    'final_cost' => $cost,
                    'days' => $row['estimated_days'] ?: 'TBA',
                );
            }
        }

        // Save Debug Log
        if ($debug_enabled) {
            TNXL_Debug_Logger::get_instance()->log_entry(array(
                'products'       => $debug_products,
                'boxes'          => $packed_boxes,
                'box_count'      => count($packed_boxes),
                'api_calls'      => TNXL_API::$last_debug_data,
                'destination'    => $dest,
                'final_quotes'   => $final_quotes_debug,
                'currency'       => $target_currency,
                'exchange_rate'  => $rate,
                'commission'     => $commission,
            ));
        }
    }

    /**
     * Show packing validation errors at checkout only, once per request.
     *
     * @param string[] $errors
     */
    private function maybe_add_packing_notices(array $errors) {
        if (self::$packing_errors_notified || empty($errors)) {
            return;
        }

        $show = is_checkout();
        if (!$show && defined('REST_REQUEST') && REST_REQUEST) {
            $request = method_exists('WP_REST_Server', 'get_current_request') ? WP_REST_Server::get_current_request() : null;
            $show = $request && str_contains((string) $request->get_route(), 'wc/store');
        }

        if (!$show) {
            return;
        }

        foreach ($errors as $error) {
            wc_add_notice($error, 'error');
        }
        self::$packing_errors_notified = true;
    }
}

