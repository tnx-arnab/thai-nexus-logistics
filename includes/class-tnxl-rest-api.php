<?php
/**
 * TNXL REST API Endpoints
 */

if (!defined('ABSPATH')) exit;

class TNXL_REST_API {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route('tnxl/v1', '/settings', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'get_settings'),
                'permission_callback' => array($this, 'check_permission'),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'save_settings'),
                'permission_callback' => array($this, 'check_permission'),
            ),
        ));

        register_rest_route('tnxl/v1', '/box-definitions', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'get_box_definitions'),
                'permission_callback' => array($this, 'check_permission'),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'save_box_definitions'),
                'permission_callback' => array($this, 'check_permission'),
            ),
        ));

        register_rest_route('tnxl/v1', '/shipments', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_shipments'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('tnxl/v1', '/product-catalog', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_product_catalog'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('tnxl/v1', '/products/(?P<id>\d+)', array(
            'methods'             => 'PUT',
            'callback'            => array($this, 'update_product'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('tnxl/v1', '/search-products', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'search_products'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('tnxl/v1', '/products', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_products_by_ids'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('tnxl/v1', '/shipping-services', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_shipping_services'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('tnxl/v1', '/check-connection', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'check_connection'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('tnxl/v1', '/shipments/(?P<request_number>[a-zA-Z0-9-]+)/sync', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'sync_shipment'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('tnxl/v1', '/shipments/(?P<request_number>[a-zA-Z0-9-]+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_shipment_details'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('tnxl/v1', '/debug-log', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'get_debug_log'),
                'permission_callback' => array($this, 'check_permission'),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array($this, 'clear_debug_log'),
                'permission_callback' => array($this, 'check_permission'),
            ),
        ));

        register_rest_route('tnxl/v1', '/cache', array(
            array(
                'methods'             => 'DELETE',
                'callback'            => array($this, 'clear_cache'),
                'permission_callback' => array($this, 'check_permission'),
            ),
        ));
    }

    public function check_permission() {
        return current_user_can('manage_options');
    }

    public function get_settings() {
        $currency_symbol = '$';
        if (function_exists('get_woocommerce_currency_symbol')) {
            $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
        }

        return array(
            'api_token' => TNXL_Settings::get_api_token(),
            'features'  => TNXL_Settings::get_features(),
            'commission_rules' => $this->sanitize_commission_rules(get_option('tnxl_commission_rules', array())),
            'disabled_service_ids' => TNXL_Settings::get_disabled_service_ids(),
            'shipping_ineligible_product_ids' => TNXL_Settings::get_ineligible_product_ids(),
            'product_weight_unit' => TNXL_Settings::get_product_weight_unit(),
            'charge_actual_weight_only' => TNXL_Settings::is_actual_weight_only(),
            'service_coverage' => TNXL_Settings::get_service_coverage(),
            'pricing_mode' => TNXL_Settings::get_pricing_mode(),
            'currency_symbol' => $currency_symbol,
            'shipper'   => array(
                'name'        => get_option('tnxl_shipper_name', ''),
                'phone'       => get_option('tnxl_shipper_phone', ''),
                'address'     => get_option('tnxl_shipper_address', ''),
                'city'        => get_option('tnxl_shipper_city', ''),
                'state'       => get_option('tnxl_shipper_state', ''),
                'postal_code' => get_option('tnxl_shipper_postal_code', ''),
                'country'     => get_option('tnxl_shipper_country', 'TH'),
            ),
        );
    }

    public function save_settings($request) {
        $params = $request->get_params();

        if (array_key_exists('api_token', $params)) {
            update_option('tnxl_api_token', sanitize_text_field(trim((string) $params['api_token'])));
        }

        if (isset($params['shipper']) && is_array($params['shipper'])) {
            $shipper = $params['shipper'];
            $shipper_fields = array(
                'name'        => array('tnxl_shipper_name', 'sanitize_text_field'),
                'phone'       => array('tnxl_shipper_phone', 'sanitize_text_field'),
                'address'     => array('tnxl_shipper_address', 'sanitize_textarea_field'),
                'city'        => array('tnxl_shipper_city', 'sanitize_text_field'),
                'state'       => array('tnxl_shipper_state', 'sanitize_text_field'),
                'postal_code' => array('tnxl_shipper_postal_code', 'sanitize_text_field'),
                'country'     => array('tnxl_shipper_country', 'sanitize_text_field'),
            );

            foreach ($shipper_fields as $key => $field) {
                if (!array_key_exists($key, $shipper)) {
                    continue;
                }
                update_option($field[0], call_user_func($field[1], $shipper[$key]));
            }
        }

        if (isset($params['features']) && is_array($params['features'])) {
            TNXL_Settings::save_features($params['features']);
        }

        if (isset($params['disabled_service_ids']) && is_array($params['disabled_service_ids'])) {
            TNXL_Settings::save_disabled_service_ids($params['disabled_service_ids']);
        }

        if (isset($params['shipping_ineligible_product_ids']) && is_array($params['shipping_ineligible_product_ids'])) {
            TNXL_Settings::save_ineligible_product_ids($params['shipping_ineligible_product_ids']);
        }

        if (array_key_exists('product_weight_unit', $params)) {
            TNXL_Settings::save_product_weight_unit($params['product_weight_unit']);
        }
        if (array_key_exists('charge_actual_weight_only', $params)) {
            TNXL_Settings::save_actual_weight_only($params['charge_actual_weight_only']);
        }
        if (isset($params['service_coverage']) && is_array($params['service_coverage'])) {
            TNXL_Settings::save_service_coverage($params['service_coverage']);
        }
        if (array_key_exists('pricing_mode', $params)) {
            TNXL_Settings::save_pricing_mode($params['pricing_mode']);
        }

        if (isset($params['commission_rules'])) {
            update_option('tnxl_commission_rules', $this->sanitize_commission_rules($params['commission_rules']));
        }

        return rest_ensure_response(array('success' => true));
    }

    public function get_shipping_services() {
        $services = TNXL_API::get_instance()->get_shipping_services();
        if (is_wp_error($services)) {
            return new WP_Error(
                'tnxl_services_error',
                $services->get_error_message(),
                array(
                    'status' => $services->get_error_code() === 'tnxl_not_configured'
                        ? 400
                        : 502,
                )
            );
        }

        return rest_ensure_response(array('services' => $services));
    }

    public function check_connection($request) {
        $token = trim((string) $request->get_param('api_token'));
        if ($token === '') {
            $token = TNXL_Settings::get_api_token();
        }

        $result = TNXL_API::get_instance()->test_connection($token);
        if (is_wp_error($result)) {
            return rest_ensure_response(array(
                'valid'   => false,
                'message' => $result->get_error_message(),
            ));
        }

        return rest_ensure_response(array(
            'valid'   => true,
            'message' => __('Connected to Thai Nexus.', 'thai-nexus-logistics'),
        ));
    }

    public function get_shipments($request) {
        if (!TNXL_Settings::are_services_active()) {
            return new WP_Error(
                'tnxl_not_configured',
                __('Thai Nexus API token is not configured.', 'thai-nexus-logistics'),
                array('status' => 403)
            );
        }

        $page = $request->get_param('page') ?: 1;
        $limit = $request->get_param('limit') ?: 10;

        $api = TNXL_API::get_instance();
        $response = $api->shipment_crud('list', array(
            'page'  => $page,
            'limit' => $limit,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('tnxl_error', $response->get_error_message(), array('status' => 500));
        }

        if (isset($response['data']) && is_array($response['data'])) {
            $response['data'] = array_map(static function ($item) {
                return is_array($item) ? TNXL_Tracking::normalize_shipment($item) : $item;
            }, $response['data']);
        }

        return rest_ensure_response($response);
    }

    public function get_shipment_details($request) {
        if (!TNXL_Settings::are_services_active()) {
            return new WP_Error(
                'tnxl_not_configured',
                __('Thai Nexus API token is not configured.', 'thai-nexus-logistics'),
                array('status' => 403)
            );
        }

        $request_number = $request['request_number'];

        $api = TNXL_API::get_instance();
        $response = $api->shipment_crud('get', array(
            'request_number' => $request_number,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('tnxl_error', $response->get_error_message(), array('status' => 500));
        }

        $data = isset($response['data']) ? $response['data'] : $response;
        if (is_array($data)) {
            $data = TNXL_Tracking::normalize_shipment($data);
        }

        return rest_ensure_response($data);
    }

    public function sync_shipment($request) {
        if (!TNXL_Settings::are_services_active()) {
            return new WP_Error(
                'tnxl_not_configured',
                __('Thai Nexus API token is not configured.', 'thai-nexus-logistics'),
                array('status' => 403)
            );
        }

        $result = TNXL_Tracking_Sync::get_instance()->sync_request_number(
            (string) $request['request_number']
        );

        if (is_wp_error($result)) {
            return new WP_Error(
                'tnxl_error',
                $result->get_error_message(),
                array('status' => 500)
            );
        }

        return rest_ensure_response($result);
    }

    public function get_product_catalog($request) {
        $search = sanitize_text_field((string) $request->get_param('search'));
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 25,
            's'              => $search,
            'orderby'        => 'title',
            'order'          => 'ASC',
        );
        $query = new WP_Query($args);
        $products = array();
        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) {
                continue;
            }
            $m = TNXL_Product::get_shipping_measurements($product);
            $customs = TNXL_Product::get_customs_details($product);
            $products[] = array(
                'id'                 => $product->get_id(),
                'name'               => $product->get_name(),
                'sku'                => $product->get_sku(),
                'length'             => $m['length'],
                'width'              => $m['width'],
                'height'             => $m['height'],
                'weight'             => $m['weight'],
                'hs_code'            => $customs['hs_code'],
                'country_of_origin'  => $customs['country_of_origin'],
                'is_document'        => TNXL_Product::is_document($product),
                'is_boxed_product'   => TNXL_Product::is_boxed_product($product),
                'shipping_eligible'  => TNXL_Product::is_shipping_eligible($product),
                'edit_url'           => get_edit_post_link($product->get_id(), 'raw'),
            );
        }

        return rest_ensure_response($products);
    }

    public function update_product($request) {
        $id = (int) $request['id'];
        $product = wc_get_product($id);
        if (!$product) {
            return new WP_Error('tnxl_product_missing', 'Product not found.', array('status' => 404));
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = array();
        }

        if (isset($params['length']) || isset($params['width']) || isset($params['height'])) {
            $dim_unit = (string) get_option('woocommerce_dimension_unit', 'cm');
            if (isset($params['length'])) {
                $product->set_length(wc_get_dimension((float) $params['length'], $dim_unit, 'cm'));
            }
            if (isset($params['width'])) {
                $product->set_width(wc_get_dimension((float) $params['width'], $dim_unit, 'cm'));
            }
            if (isset($params['height'])) {
                $product->set_height(wc_get_dimension((float) $params['height'], $dim_unit, 'cm'));
            }
        }
        if (isset($params['weight'])) {
            $weight_unit = (string) get_option('woocommerce_weight_unit', 'kg');
            $product->set_weight(wc_get_weight((float) $params['weight'], $weight_unit, 'kg'));
        }
        $product->save();

        if (array_key_exists('is_document', $params)) {
            update_post_meta($id, '_tnxl_is_document', !empty($params['is_document']) ? 'yes' : 'no');
        }
        if (array_key_exists('is_boxed_product', $params)) {
            update_post_meta($id, '_tnxl_is_boxed_product', !empty($params['is_boxed_product']) ? 'yes' : 'no');
        }
        if (array_key_exists('shipping_eligible', $params)) {
            update_post_meta(
                $id,
                '_tnxl_shipping_eligible',
                $params['shipping_eligible'] === false ? 'no' : 'yes'
            );
        }
        if (array_key_exists('hs_code', $params)) {
            update_post_meta($id, '_tnxl_hs_code', TNXL_Product::normalize_hs_code($params['hs_code']));
        }
        if (array_key_exists('country_of_origin', $params)) {
            $origin = strtoupper(preg_replace('/[^A-Za-z]/', '', sanitize_text_field((string) $params['country_of_origin'])));
            update_post_meta($id, '_tnxl_country_of_origin', substr($origin, 0, 2));
        }

        $fresh = wc_get_product($id);
        $m = TNXL_Product::get_shipping_measurements($fresh);
        $customs = TNXL_Product::get_customs_details($fresh);
        return rest_ensure_response(array(
            'id'                => $fresh->get_id(),
            'name'              => $fresh->get_name(),
            'sku'               => $fresh->get_sku(),
            'length'            => $m['length'],
            'width'             => $m['width'],
            'height'            => $m['height'],
            'weight'            => $m['weight'],
            'hs_code'           => $customs['hs_code'],
            'country_of_origin' => $customs['country_of_origin'],
            'is_document'       => TNXL_Product::is_document($fresh),
            'is_boxed_product'  => TNXL_Product::is_boxed_product($fresh),
            'shipping_eligible' => TNXL_Product::is_shipping_eligible($fresh),
            'edit_url'          => get_edit_post_link($fresh->get_id(), 'raw'),
        ));
    }

    /**
     * @param mixed $rules
     * @return array<int, array<string, mixed>>
     */
    private function sanitize_commission_rules($rules): array {
        if (!is_array($rules)) {
            return array();
        }
        $out = array();
        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $conditions = array();
            foreach ((array) ($rule['conditions'] ?? array()) as $condition) {
                if (!is_array($condition)) {
                    continue;
                }
                $type = sanitize_key((string) ($condition['type'] ?? ''));
                $row = array('type' => $type);
                if ($type === 'subtotal_range') {
                    $row['minRange'] = floatval($condition['minRange'] ?? $condition['min_range'] ?? 0);
                    $row['maxRange'] = floatval($condition['maxRange'] ?? $condition['max_range'] ?? 0);
                } elseif ($type === 'specific_products') {
                    $row['specificProducts'] = array_values(array_filter(array_map('absint', (array) ($condition['specificProducts'] ?? $condition['specific_products'] ?? array()))));
                } elseif ($type === 'destination_country') {
                    $codes = array();
                    foreach ((array) ($condition['countries'] ?? array()) as $code) {
                        $iso = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $code));
                        if (strlen($iso) === 2) {
                            $codes[] = $iso;
                        }
                    }
                    $row['countries'] = array_values(array_unique($codes));
                    $row['excludeCountries'] = !empty($condition['excludeCountries']) || !empty($condition['exclude_countries']);
                } elseif ($type === 'weight_range') {
                    $row['minKg'] = floatval($condition['minKg'] ?? 0);
                    $row['maxKg'] = floatval($condition['maxKg'] ?? 0);
                } elseif ($type === 'item_quantity') {
                    $row['minQuantity'] = absint($condition['minQuantity'] ?? 0);
                    $row['maxQuantity'] = absint($condition['maxQuantity'] ?? 0);
                } elseif ($type === 'shipping_service') {
                    $ids = array();
                    foreach ((array) ($condition['serviceIds'] ?? $condition['service_ids'] ?? array()) as $id) {
                        $norm = TNXL_Settings::normalize_service_id($id);
                        if ($norm !== '') {
                            $ids[] = $norm;
                        }
                    }
                    $row['serviceIds'] = array_values(array_unique($ids));
                } else {
                    continue;
                }
                $conditions[] = $row;
            }

            $id = sanitize_text_field((string) ($rule['id'] ?? ('rule_' . ($index + 1))));
            $out[] = array(
                'id'                => $id !== '' ? $id : ('rule_' . ($index + 1)),
                'conditionType'     => sanitize_text_field((string) ($rule['conditionType'] ?? $rule['condition_type'] ?? 'subtotal_range')),
                'minRange'          => floatval($rule['minRange'] ?? $rule['min_range'] ?? 0),
                'maxRange'          => floatval($rule['maxRange'] ?? $rule['max_range'] ?? 0),
                'specificProducts'  => array_values(array_filter(array_map('absint', (array) ($rule['specificProducts'] ?? $rule['specific_products'] ?? array())))),
                'conditions'        => $conditions,
                'feeType'           => sanitize_text_field((string) ($rule['feeType'] ?? $rule['fee_type'] ?? 'fixed')),
                'feeValue'          => floatval($rule['feeValue'] ?? $rule['fee_value'] ?? 0),
                'markupPercent'     => floatval($rule['markupPercent'] ?? $rule['markup_percent'] ?? 0),
                'cartPercent'       => floatval($rule['cartPercent'] ?? $rule['cart_percent'] ?? 0),
                'pickupUnit'        => sanitize_text_field((string) ($rule['pickupUnit'] ?? $rule['pickup_unit'] ?? 'once')),
                'stopProcessing'    => !empty($rule['stopProcessing']) || !empty($rule['stop_processing']),
                'feeLabel'          => sanitize_text_field((string) ($rule['feeLabel'] ?? $rule['fee_label'] ?? '')),
            );
        }

        return $out;
    }

    public function search_products($request) {
        $search = $request->get_param('search');
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            's'              => sanitize_text_field($search),
        );
        $query = new WP_Query($args);
        $products = array();
        
        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                $product = wc_get_product($post->ID);
                if ($product) {
                    $products[] = array(
                        'id'   => $product->get_id(),
                        'name' => $product->get_name(),
                        'sku'  => $product->get_sku(),
                    );
                }
            }
        }
        
        return rest_ensure_response($products);
    }

    public function get_products_by_ids($request) {
        $raw_ids = (string) $request->get_param('ids');
        $ids = array_values(array_unique(array_filter(array_map('absint', explode(',', $raw_ids)))));
        $products = array();

        foreach (array_slice($ids, 0, 100) as $id) {
            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }

            $products[] = array(
                'id'   => $product->get_id(),
                'name' => $product->get_name(),
                'sku'  => $product->get_sku(),
            );
        }

        return rest_ensure_response($products);
    }

    public function get_box_definitions() {
        return rest_ensure_response(get_option('tnxl_box_definitions', array()));
    }

    public function save_box_definitions($request) {
        $boxes = $request->get_param('boxes');
        $sanitized_boxes = array();

        if (is_array($boxes)) {
            foreach ($boxes as $box) {
                $sanitized_boxes[] = array(
                    'name'         => sanitize_text_field($box['name'] ?? ''),
                    'inner_length' => floatval($box['inner_length'] ?? 0),
                    'inner_width'  => floatval($box['inner_width'] ?? 0),
                    'inner_depth'  => floatval($box['inner_depth'] ?? 0),
                    // Since we don't show outer dimensions in UI, enforce sync on save
                    'outer_length' => floatval($box['inner_length'] ?? 0),
                    'outer_width'  => floatval($box['inner_width'] ?? 0),
                    'outer_depth'  => floatval($box['inner_depth'] ?? 0),
                    'max_weight'   => floatval($box['max_weight'] ?? 0),
                    'empty_weight' => floatval($box['empty_weight'] ?? 0),
                );
            }
        }

        update_option('tnxl_box_definitions', $sanitized_boxes);
        return rest_ensure_response(array('success' => true));
    }

    public function get_debug_log() {
        if (!TNXL_Debug_Logger::is_enabled()) {
            return new WP_Error('disabled', __('Debug logging is disabled.', 'thai-nexus-logistics'), array('status' => 403));
        }
        return rest_ensure_response(TNXL_Debug_Logger::get_instance()->get_entries());
    }

    public function clear_debug_log() {
        if (!TNXL_Debug_Logger::is_enabled()) {
            return new WP_Error('disabled', __('Debug logging is disabled.', 'thai-nexus-logistics'), array('status' => 403));
        }
        TNXL_Debug_Logger::get_instance()->clear();
        return rest_ensure_response(array('success' => true));
    }

    public function clear_cache() {
        global $wpdb;
        
        // Delete all transients starting with tnxl_quote_
        $prefix = '_transient_tnxl_quote_';
        $prefix_timeout = '_transient_timeout_tnxl_quote_';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like($prefix) . '%', $wpdb->esc_like($prefix_timeout) . '%' ) );
        
        return rest_ensure_response(array('success' => true));
    }
}

