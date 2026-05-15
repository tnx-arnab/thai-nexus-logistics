<?php
/**
 * TNXL Currency Converter
 * 
 * Handles currency conversion from THB to website currency using Frankfurter API.
 */

if (!defined('ABSPATH')) exit;

class TNXL_Currency {

    private static $instance = null;
    private $api_base_url = 'https://api.frankfurter.app/latest?from=';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('woocommerce_get_price_html', array($this, 'append_usd_price'), 10, 2);
    }

    /**
     * Get exchange rate between two currencies
     */
    public function get_rate($from = 'THB', $to = 'USD') {
        if ($from === $to) {
            return 1.0;
        }

        $transient_key = 'tnxl_rate_' . strtolower($from) . '_' . strtolower($to);
        $rate = get_transient($transient_key);

        if (false === $rate) {
            $url = $this->api_base_url . $from . '&to=' . $to;
            $response = wp_remote_get($url);

            if (is_wp_error($response)) {
                return false;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (isset($data['rates'][$to])) {
                $rate = $data['rates'][$to];
                set_transient($transient_key, $rate, DAY_IN_SECONDS);
            } else {
                return false;
            }
        }

        return $rate;
    }

    /**
     * Backward compatibility for product price display (specifically USD)
     */
    public function get_thb_to_usd_rate() {
        return $this->get_rate('THB', 'USD');
    }

    /**
     * Append USD price to the product price HTML
     */
    public function append_usd_price($price_html, $product) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return $price_html;
        }

        // Avoid double conversion if already processed
        if (strpos($price_html, 'tnxl-usd-price') !== false) {
            return $price_html;
        }

        // The requirement specifically asked for USD next to the original price
        $rate = $this->get_thb_to_usd_rate();
        if (!$rate) {
            return $price_html;
        }

        $price = $product->get_price();
        if (empty($price)) {
            return $price_html;
        }

        // We assume the base price of the product is in THB as per requirements
        $usd_price = $price * $rate;
        $usd_price_formatted = '$' . number_format($usd_price, 2);

        $append_html = sprintf(
            ' <span class="tnxl-usd-price" style="font-size: 0.8em; color: #666;">(%s %s)</span>',
            esc_html__('USD', 'thai-nexus-logistics'),
            esc_html($usd_price_formatted)
        );

        return $price_html . $append_html;
    }
}

