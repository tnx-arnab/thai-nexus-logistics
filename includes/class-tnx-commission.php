<?php
/**
 * TNX Commission and Fees class
 */

if (!defined('ABSPATH')) exit;

class TNX_Commission {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // No longer hooking into woocommerce_cart_calculate_fees
        // Commission will be added as a buffer to shipping rates instead
    }

    /**
     * Calculate total commission based on current cart
     * 
     * @return float Total commission amount
     */
    public function get_total_commission() {
        if (!isset(WC()->cart)) {
            return 0;
        }

        $rules = get_option('tnx_commission_rules', array());
        if (empty($rules) || !is_array($rules)) {
            return 0;
        }

        $cart = WC()->cart;
        $cart_subtotal = $cart->get_subtotal();
        $total_commission = 0;
        
        foreach ($rules as $rule) {
            $condition_type = $rule['condition_type'] ?? '';
            $fee_type       = $rule['fee_type'] ?? 'fixed';
            $fee_value      = floatval($rule['fee_value'] ?? 0);
            
            if ($fee_value <= 0) continue;

            $fee_amount = 0;
            $apply_fee = false;

            if ($condition_type === 'subtotal_range') {
                $min_range = floatval($rule['min_range'] ?? 0);
                $max_range = floatval($rule['max_range'] ?? 0);

                if ($cart_subtotal >= $min_range && ($max_range == 0 || $cart_subtotal <= $max_range)) {
                    $apply_fee = true;
                    if ($fee_type === 'percentage') {
                        $fee_amount = ($cart_subtotal * $fee_value) / 100;
                    } else {
                        $fee_amount = $fee_value;
                    }
                }
            } elseif ($condition_type === 'specific_products') {
                $specific_products = $rule['specific_products'] ?? array();
                if (empty($specific_products)) continue;

                $matching_products_total = 0;
                $found_product = false;

                foreach ($cart->get_cart() as $cart_item) {
                    $product_id = $cart_item['product_id'];
                    $variation_id = $cart_item['variation_id'];
                    
                    if (in_array($product_id, $specific_products) || in_array($variation_id, $specific_products)) {
                        $found_product = true;
                        $matching_products_total += $cart_item['line_total'];
                    }
                }

                if ($found_product) {
                    $apply_fee = true;
                    if ($fee_type === 'percentage') {
                        $fee_amount = ($matching_products_total * $fee_value) / 100;
                    } else {
                        $fee_amount = $fee_value;
                    }
                }
            }

            if ($apply_fee && $fee_amount > 0) {
                $total_commission += $fee_amount;
            }
        }

        return $total_commission;
    }
}
