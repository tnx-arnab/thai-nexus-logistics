<?php
/**
 * TNX REST API Endpoints
 */

if (!defined('ABSPATH')) exit;

class TNX_REST_API {

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
        register_rest_route('tnx/v1', '/settings', array(
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

        register_rest_route('tnx/v1', '/box-definitions', array(
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

        register_rest_route('tnx/v1', '/shipments', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_shipments'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('tnx/v1', '/search-products', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'search_products'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('tnx/v1', '/shipments/(?P<request_number>[a-zA-Z0-9-]+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_shipment_details'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('tnx/v1', '/debug-log', array(
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

        register_rest_route('tnx/v1', '/cache', array(
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
        return array(
            'api_token' => get_option('tnx_api_token', ''),
            'commission_rules' => get_option('tnx_commission_rules', array()),
            'currency_symbol' => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8'),
            'shipper'   => array(
                'name'        => get_option('tnx_shipper_name', ''),
                'phone'       => get_option('tnx_shipper_phone', ''),
                'address'     => get_option('tnx_shipper_address', ''),
                'city'        => get_option('tnx_shipper_city', ''),
                'state'       => get_option('tnx_shipper_state', ''),
                'postal_code' => get_option('tnx_shipper_postal_code', ''),
                'country'     => get_option('tnx_shipper_country', 'TH'),
            ),
        );
    }

    public function save_settings($request) {
        $params = $request->get_params();

        if (isset($params['api_token'])) {
            update_option('tnx_api_token', sanitize_text_field($params['api_token']));
        }

        if (isset($params['shipper'])) {
            $shipper = $params['shipper'];
            update_option('tnx_shipper_name', sanitize_text_field($shipper['name']));
            update_option('tnx_shipper_phone', sanitize_text_field($shipper['phone']));
            update_option('tnx_shipper_address', sanitize_textarea_field($shipper['address']));
            update_option('tnx_shipper_city', sanitize_text_field($shipper['city']));
            update_option('tnx_shipper_state', sanitize_text_field(isset($shipper['state']) ? $shipper['state'] : ''));
            update_option('tnx_shipper_postal_code', sanitize_text_field(isset($shipper['postal_code']) ? $shipper['postal_code'] : ''));
            update_option('tnx_shipper_country', sanitize_text_field($shipper['country']));
        }

        if (isset($params['commission_rules'])) {
            // Need custom sanitization for an array of rules
            $rules = array();
            if (is_array($params['commission_rules'])) {
                foreach ($params['commission_rules'] as $rule) {
                    $rules[] = array(
                        'condition_type'    => sanitize_text_field($rule['condition_type'] ?? ''),
                        'min_range'         => floatval($rule['min_range'] ?? 0),
                        'max_range'         => floatval($rule['max_range'] ?? 0),
                        'specific_products' => array_map('intval', (array)($rule['specific_products'] ?? array())),
                        'fee_type'          => sanitize_text_field($rule['fee_type'] ?? 'fixed'),
                        'fee_value'         => floatval($rule['fee_value'] ?? 0),
                        'fee_label'         => sanitize_text_field($rule['fee_label'] ?? ''),
                    );
                }
            }
            update_option('tnx_commission_rules', $rules);
        }

        return rest_ensure_response(array('success' => true));
    }

    public function get_shipments($request) {
        $page = $request->get_param('page') ?: 1;
        $limit = $request->get_param('limit') ?: 10;

        $api = TNX_API::get_instance();
        $response = $api->shipment_crud('list', array(
            'page'  => $page,
            'limit' => $limit,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('tnx_error', $response->get_error_message(), array('status' => 500));
        }

        return rest_ensure_response($response);
    }

    public function get_shipment_details($request) {
        $request_number = $request['request_number'];

        $api = TNX_API::get_instance();
        $response = $api->shipment_crud('get', array(
            'request_number' => $request_number,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('tnx_error', $response->get_error_message(), array('status' => 500));
        }

        // Return the 'data' part which contains the shipment entity
        return rest_ensure_response(isset($response['data']) ? $response['data'] : $response);
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

    public function get_box_definitions() {
        return rest_ensure_response(get_option('tnx_box_definitions', array()));
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

        update_option('tnx_box_definitions', $sanitized_boxes);
        return rest_ensure_response(array('success' => true));
    }

    public function get_debug_log() {
        if (!TNX_Debug_Logger::is_enabled()) {
            return new WP_Error('disabled', __('Debug logging is disabled.', 'thai-nexus-logistics'), array('status' => 403));
        }
        return rest_ensure_response(TNX_Debug_Logger::get_instance()->get_entries());
    }

    public function clear_debug_log() {
        if (!TNX_Debug_Logger::is_enabled()) {
            return new WP_Error('disabled', __('Debug logging is disabled.', 'thai-nexus-logistics'), array('status' => 403));
        }
        TNX_Debug_Logger::get_instance()->clear();
        return rest_ensure_response(array('success' => true));
    }

    public function clear_cache() {
        global $wpdb;
        
        // Delete all transients starting with tnx_quote_
        $prefix = '_transient_tnx_quote_';
        $prefix_timeout = '_transient_timeout_tnx_quote_';
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%',
                $wpdb->esc_like($prefix_timeout) . '%'
            )
        );
        
        return rest_ensure_response(array('success' => true));
    }
}
