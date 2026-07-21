<?php
/**
 * TNXL API Client
 */

if (!defined('ABSPATH')) exit;

class TNXL_API {

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
        return TNXL_Settings::get_api_token();
    }

    /**
     * Get Shipping Quotes
     */
    public function get_quote($data) {
        if (!TNXL_Settings::are_services_active()) {
            return new WP_Error(
                'tnxl_not_configured',
                __('Thai Nexus API token is not configured.', 'thai-nexus-logistics')
            );
        }
        $cache_key = 'tnxl_quote_' . md5(json_encode($data));
        $cached    = get_transient($cache_key);
        
        // Bypass cache if debug mode is enabled to ensure fresh pricing
        if ($cached !== false && !TNXL_Debug_Logger::is_enabled()) {
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
     * Validate a candidate token without saving it.
     */
    public function test_connection($token) {
        $token = trim((string) $token);
        if ($token === '') {
            return new WP_Error(
                'tnxl_not_configured',
                __('Thai Nexus API token is required.', 'thai-nexus-logistics')
            );
        }

        return $this->request('apiQuote', array(
            'api_token'       => $token,
            'country'         => 'TH',
            'state'           => 'BKK',
            'postcode'        => '10110',
            'city'            => 'Bangkok',
            'actual_weight_kg'=> 1,
            'length_cm'       => 20,
            'width_cm'        => 15,
            'height_cm'       => 10,
            'is_document'     => false,
        ));
    }

    /**
     * Retrieve the courier services available to the saved account.
     */
    public function get_shipping_services() {
        if (!TNXL_Settings::are_services_active()) {
            return new WP_Error(
                'tnxl_not_configured',
                __('Thai Nexus API token is not configured.', 'thai-nexus-logistics')
            );
        }

        $url = $this->base_url . 'apiShippingServices';
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_token(),
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        if ($status_code >= 400 || !is_array($body)) {
            $message = is_array($body)
                ? ($body['message'] ?? $body['error'] ?? '')
                : '';
            return new WP_Error(
                'tnxl_api_error',
                $message !== ''
                    ? sanitize_text_field($message)
                    : __('Failed to load Thai Nexus shipping services.', 'thai-nexus-logistics')
            );
        }

        return isset($body['data']) && is_array($body['data']) ? $body['data'] : array();
    }

    /**
     * CRUD Operations for Shipments
     */
    public function shipment_crud($action, $data = array()) {
        if (!TNXL_Settings::are_services_active()) {
            return new WP_Error(
                'tnxl_not_configured',
                __('Thai Nexus API token is not configured.', 'thai-nexus-logistics')
            );
        }

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
        if (empty($payload['api_token'])) {
            return new WP_Error(
                'tnxl_not_configured',
                __('Thai Nexus API token is not configured.', 'thai-nexus-logistics')
            );
        }

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

        if (TNXL_Debug_Logger::is_enabled()) {
            $log_payload = $payload;
            if (isset($log_payload['api_token'])) {
                $log_payload['api_token'] = '[REDACTED]';
            }
            self::$last_debug_data[] = array(
                'endpoint' => $endpoint,
                'payload'  => $log_payload,
                'response' => $body ?: $body_raw,
                'status'   => $status_code,
            );
        }

        if ($status_code >= 400 || empty($body)) {
            $message = '';
            if (is_array($body)) {
                $message = (string) ($body['message'] ?? $body['error'] ?? '');
            }

            return new WP_Error(
                'tnxl_api_error',
                $message !== ''
                    ? sanitize_text_field($message)
                    : __('API request failed', 'thai-nexus-logistics'),
                $body
            );
        }

        return $body;
    }
}

