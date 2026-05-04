<?php
/**
 * TNX API Client
 */

if (!defined('ABSPATH')) exit;

class TNX_API {

    private static $instance = null;
    private $base_url = 'https://app.thainexus.co.th/functions/';
    public static $last_debug_data = array();

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function get_token() {
        return get_option('tnx_api_token', '');
    }

    /**
     * Get Shipping Quotes
     */
    public function get_quote($data) {
        $cache_key = 'tnx_quote_' . md5(json_encode($data));
        $cached    = get_transient($cache_key);
        
        if ($cached !== false) {

            return $cached;
        }

        $endpoint = 'apiQuote';
        $payload = array_merge(array(
            'api_token' => $this->get_token(),
        ), $data);

        $result = $this->request($endpoint, $payload);


        if (!is_wp_error($result)) {
            // Cache successful quotes for 1 hour
            set_transient($cache_key, $result, HOUR_IN_SECONDS);
        }

        return $result;
    }

    /**
     * CRUD Operations for Shipments
     */
    public function shipment_crud($action, $data = array()) {
        $endpoint = 'shipmentCrud';
        $payload = array_merge(array(
            'api_token' => $this->get_token(),
            'action'    => $action,
        ), $data);

        return $this->request($endpoint, $payload);
    }

    /**
     * Generic Request Handler
     */
    private function request($endpoint, $payload) {
        $url = $this->base_url . $endpoint;

        $response = wp_remote_post($url, array(
            'headers'     => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'        => json_encode($payload),
            'timeout'     => 30,
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body_raw    = wp_remote_retrieve_body($response);
        $body        = json_decode($body_raw, true);

        if (TNX_Debug_Logger::is_enabled()) {
            self::$last_debug_data[] = array(
                'endpoint' => $endpoint,
                'payload'  => $payload,
                'response' => $body ?: $body_raw,
                'status'   => $status_code,
            );
        }

        if ($status_code >= 400 || empty($body)) {
            return new WP_Error('tnx_api_error', isset($body['message']) ? $body['message'] : __('API request failed', 'thai-nexus-logistics'), $body);
        }

        return $body;
    }
}
