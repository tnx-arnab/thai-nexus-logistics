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

        register_rest_route('tnx/v1', '/shipments', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_shipments'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('tnx/v1', '/shipments/(?P<request_number>[a-zA-Z0-9-]+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_shipment_details'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }

    public function check_permission() {
        return current_user_can('manage_options');
    }

    public function get_settings() {
        return array(
            'api_token' => get_option('tnx_api_token', ''),
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
}
