<?php
/**
 * TNXL Commission and Fees class
 */

if (!defined('ABSPATH')) exit;

class TNXL_Commission {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Additive commission only (quote markup is applied in apply_checkout_pricing).
     */
    public function get_total_commission() {
        return self::apply_checkout_pricing(0, self::cart_pricing_context());
    }

    /**
     * checkout = (quote + matching_fixed) x Π(1 + quote_% / 100) + cart/product_%
     */
    public static function apply_checkout_pricing(float $quote_thb, array $context = array()): float {
        if (!TNXL_Settings::can_fetch_checkout_rates()) {
            return max(0, round($quote_thb, 2));
        }

        $rules = get_option('tnxl_commission_rules', array());
        if (!is_array($rules) || empty($rules)) {
            return max(0, round($quote_thb, 2));
        }

        $quote = $quote_thb > 0 ? $quote_thb : 0;
        $mode = TNXL_Settings::get_pricing_mode();
        $active = $mode === 'basic'
            ? array_values(array_filter($rules, array(__CLASS__, 'rule_is_always_on')))
            : $rules;

        $fixed = 0.0;
        $markup_multiplier = 1.0;
        $additive = 0.0;

        foreach ($active as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $match = self::rule_matches($rule, $context);
            if (!$match['matched']) {
                continue;
            }

            $pickup = self::pickup_from_rule($rule);
            $markup = self::markup_percent_from_rule($rule);
            $cart_percent = self::cart_percent_from_rule($rule);
            if ($pickup <= 0 && $markup <= 0 && $cart_percent <= 0) {
                if (!empty($rule['stopProcessing']) || !empty($rule['stop_processing'])) {
                    break;
                }
                continue;
            }

            if ($pickup > 0) {
                $fixed += self::scaled_pickup(
                    $pickup,
                    self::pickup_unit_from_rule($rule),
                    $match['matchingQty'],
                    (float) ($context['cartWeightKg'] ?? $context['cart_weight_kg'] ?? 0)
                );
            }
            if ($markup > 0) {
                $markup_multiplier *= 1 + ($markup / 100);
            }
            if ($cart_percent > 0) {
                $has_products = false;
                foreach (self::effective_conditions($rule) as $condition) {
                    if (($condition['type'] ?? '') === 'specific_products') {
                        $has_products = true;
                        break;
                    }
                }
                $basis = $has_products
                    ? $match['productSubtotal']
                    : (float) ($context['cartSubtotal'] ?? $context['cart_subtotal'] ?? 0);
                $additive += ($basis * $cart_percent) / 100;
            }

            if (!empty($rule['stopProcessing']) || !empty($rule['stop_processing'])) {
                break;
            }
        }

        $total = ($quote + $fixed) * $markup_multiplier + $additive;
        if (!is_finite($total) || $total < 0) {
            return 0;
        }

        return round($total, 2);
    }

    /**
     * Convert a THB quote to store currency, then apply pickup/markup/cart %
     * in that currency (admin fees UI uses the store symbol).
     *
     * @return array{quote_store:float,priced:float,commission:float}
     */
    public static function price_converted_quote(float $quote_thb, $exchange_rate, array $context = array()): array {
        $quote_store = $quote_thb > 0 ? $quote_thb : 0.0;
        if (is_numeric($exchange_rate) && (float) $exchange_rate > 0) {
            $quote_store = $quote_store * (float) $exchange_rate;
        }
        $priced = self::apply_checkout_pricing($quote_store, $context);
        return array(
            'quote_store' => round($quote_store, 2),
            'priced'      => $priced,
            'commission'  => max(0, round($priced - $quote_store, 2)),
        );
    }

    public static function cart_pricing_context(array $extra = array()): array {
        $items = array();
        $subtotal = 0.0;
        $qty = 0;
        $weight = 0.0;
        $dest = '';

        if (function_exists('WC') && WC()->cart) {
            $subtotal = (float) WC()->cart->get_subtotal();
            $customer = WC()->customer;
            if ($customer) {
                $dest = (string) $customer->get_shipping_country();
            }
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product = $cart_item['data'] ?? null;
                $line_qty = max(1, (int) ($cart_item['quantity'] ?? 1));
                $qty += $line_qty;
                $items[] = array(
                    'product_id'   => (string) ($cart_item['product_id'] ?? ''),
                    'variation_id' => (string) ($cart_item['variation_id'] ?? ''),
                    'quantity'     => $line_qty,
                    'line_total'   => (float) ($cart_item['line_total'] ?? 0),
                );
                if ($product instanceof WC_Product && class_exists('TNXL_Product')) {
                    $weight += TNXL_Product::get_shipping_measurements($product)['weight'] * $line_qty;
                }
            }
        }

        return array_merge(array(
            'items'              => $items,
            'cartSubtotal'       => $subtotal,
            'destinationCountry' => $dest,
            'cartWeightKg'       => $weight,
            'itemQuantity'       => $qty,
            'serviceIds'         => self::chosen_service_ids(),
        ), $extra);
    }

    private static function chosen_service_ids(): array {
        if (!function_exists('WC') || !WC()->session || !class_exists('TNXL_Service_Coverage')) {
            return array();
        }
        $chosen = WC()->session->get('chosen_shipping_methods');
        if (!is_array($chosen) || empty($chosen)) {
            return array();
        }
        $ids = array();
        $packages = WC()->shipping() ? WC()->shipping()->get_packages() : array();
        foreach ($packages as $index => $package) {
            $rate_id = $chosen[$index] ?? '';
            $rate = $package['rates'][$rate_id] ?? null;
            if (!$rate || !is_object($rate) || !method_exists($rate, 'get_meta')) {
                continue;
            }
            $courier = (string) $rate->get_meta('tnxl_courier');
            $display = (string) $rate->get_meta('tnxl_courier_display');
            if ($courier === '' && $display === '') {
                continue;
            }
            foreach (TNXL_Service_Coverage::coverage_ids(array($courier, $display)) as $id) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    private static function pickup_from_rule(array $rule): float {
        $type = $rule['feeType'] ?? $rule['fee_type'] ?? 'fixed';
        if ($type === 'fixed' || $type === 'mixed') {
            $value = (float) ($rule['feeValue'] ?? $rule['fee_value'] ?? 0);
            return $value > 0 ? $value : 0;
        }
        return 0;
    }

    private static function markup_percent_from_rule(array $rule): float {
        $type = $rule['feeType'] ?? $rule['fee_type'] ?? 'fixed';
        if ($type === 'mixed') {
            $value = (float) ($rule['markupPercent'] ?? $rule['markup_percent'] ?? 0);
            return $value > 0 ? $value : 0;
        }
        if ($type === 'quote_percentage') {
            $value = (float) ($rule['feeValue'] ?? $rule['fee_value'] ?? 0);
            return $value > 0 ? $value : 0;
        }
        return 0;
    }

    private static function cart_percent_from_rule(array $rule): float {
        $type = $rule['feeType'] ?? $rule['fee_type'] ?? 'fixed';
        if ($type === 'percentage') {
            $value = (float) ($rule['feeValue'] ?? $rule['fee_value'] ?? 0);
            return $value > 0 ? $value : 0;
        }
        $extra = (float) ($rule['cartPercent'] ?? $rule['cart_percent'] ?? 0);
        return $extra > 0 ? $extra : 0;
    }

    private static function pickup_unit_from_rule(array $rule): string {
        $unit = $rule['pickupUnit'] ?? $rule['pickup_unit'] ?? 'once';
        return in_array($unit, array('per_item', 'per_kg'), true) ? $unit : 'once';
    }

    private static function effective_conditions(array $rule): array {
        if (!empty($rule['conditions']) && is_array($rule['conditions'])) {
            return array_values(array_filter($rule['conditions'], array(__CLASS__, 'condition_is_active')));
        }

        $type = $rule['conditionType'] ?? $rule['condition_type'] ?? '';
        if ($type === 'specific_products') {
            $ids = array_values(array_filter(array_map('intval', (array) ($rule['specificProducts'] ?? $rule['specific_products'] ?? array()))));
            return array(array('type' => 'specific_products', 'specificProducts' => $ids));
        }

        $min = (float) ($rule['minRange'] ?? $rule['min_range'] ?? 0);
        $max = (float) ($rule['maxRange'] ?? $rule['max_range'] ?? 0);
        if ($min > 0 || $max > 0) {
            return array(array('type' => 'subtotal_range', 'minRange' => $min, 'maxRange' => $max));
        }

        return array();
    }

    public static function rule_has_effect($rule): bool {
        if (!is_array($rule)) {
            return false;
        }

        return self::pickup_from_rule($rule) > 0
            || self::markup_percent_from_rule($rule) > 0
            || self::cart_percent_from_rule($rule) > 0;
    }

    public static function rule_is_always_on($rule): bool {
        if (!is_array($rule)) {
            return false;
        }
        $conditions = self::effective_conditions($rule);
        if (empty($conditions)) {
            return true;
        }
        foreach ($conditions as $condition) {
            if (($condition['type'] ?? '') !== 'subtotal_range') {
                return false;
            }
            if ((float) ($condition['minRange'] ?? $condition['min_range'] ?? 0) > 0
                || (float) ($condition['maxRange'] ?? $condition['max_range'] ?? 0) > 0) {
                return false;
            }
        }
        return true;
    }

    private static function condition_is_active($condition): bool {
        if (!is_array($condition)) {
            return false;
        }
        $type = $condition['type'] ?? '';
        if ($type === 'subtotal_range') {
            return (float) ($condition['minRange'] ?? 0) > 0 || (float) ($condition['maxRange'] ?? 0) > 0;
        }
        if ($type === 'specific_products') {
            return !empty($condition['specificProducts']) || !empty($condition['specific_products']);
        }
        if ($type === 'destination_country') {
            return !empty($condition['countries']);
        }
        if ($type === 'weight_range') {
            return (float) ($condition['minKg'] ?? 0) > 0 || (float) ($condition['maxKg'] ?? 0) > 0;
        }
        if ($type === 'item_quantity') {
            return (float) ($condition['minQuantity'] ?? 0) > 0 || (float) ($condition['maxQuantity'] ?? 0) > 0;
        }
        return !empty($condition['serviceIds']) || !empty($condition['service_ids']);
    }

    /**
     * @return array{matched:bool,productSubtotal:float,matchingQty:int}
     */
    private static function rule_matches(array $rule, array $context): array {
        $items = $context['items'] ?? array();
        $conditions = self::effective_conditions($rule);
        $product_subtotal = 0.0;
        $matching_qty = 0;
        $has_product_filter = false;

        foreach ($conditions as $condition) {
            if (!self::condition_matches($condition, $context, $items)) {
                return array('matched' => false, 'productSubtotal' => 0.0, 'matchingQty' => 0);
            }
            if (($condition['type'] ?? '') === 'specific_products') {
                $has_product_filter = true;
                $match = self::matching_products($items, $condition['specificProducts'] ?? $condition['specific_products'] ?? array());
                $product_subtotal += $match['productSubtotal'];
                $matching_qty += $match['matchingQty'];
            }
        }

        if (!$has_product_filter) {
            $matching_qty = (int) ($context['itemQuantity'] ?? $context['item_quantity'] ?? 0);
            if ($matching_qty <= 0) {
                foreach ($items as $item) {
                    $matching_qty += max(1, (int) ($item['quantity'] ?? 1));
                }
            }
        }

        return array('matched' => true, 'productSubtotal' => $product_subtotal, 'matchingQty' => $matching_qty);
    }

    private static function condition_matches(array $condition, array $context, array $items): bool {
        $type = $condition['type'] ?? '';
        if ($type === 'subtotal_range') {
            return self::in_min_max(
                (float) ($context['cartSubtotal'] ?? $context['cart_subtotal'] ?? 0),
                (float) ($condition['minRange'] ?? 0),
                (float) ($condition['maxRange'] ?? 0)
            );
        }
        if ($type === 'specific_products') {
            $ids = $condition['specificProducts'] ?? $condition['specific_products'] ?? array();
            return self::matching_products($items, $ids)['inCart'];
        }
        if ($type === 'destination_country') {
            $dest = strtoupper(trim((string) ($context['destinationCountry'] ?? $context['destination_country'] ?? '')));
            if ($dest === '') {
                return false;
            }
            $codes = array();
            foreach ((array) ($condition['countries'] ?? array()) as $code) {
                $iso = strtoupper(trim((string) $code));
                if ($iso !== '') {
                    $codes[$iso] = true;
                }
            }
            if (empty($codes)) {
                return false;
            }
            $has = isset($codes[$dest]);
            return !empty($condition['excludeCountries']) || !empty($condition['exclude_countries']) ? !$has : $has;
        }
        if ($type === 'weight_range') {
            return self::in_min_max(
                (float) ($context['cartWeightKg'] ?? $context['cart_weight_kg'] ?? 0),
                (float) ($condition['minKg'] ?? 0),
                (float) ($condition['maxKg'] ?? 0)
            );
        }
        if ($type === 'item_quantity') {
            $qty = (int) ($context['itemQuantity'] ?? $context['item_quantity'] ?? 0);
            if ($qty <= 0) {
                foreach ($items as $item) {
                    $qty += max(1, (int) ($item['quantity'] ?? 1));
                }
            }
            return self::in_min_max($qty, (float) ($condition['minQuantity'] ?? 0), (float) ($condition['maxQuantity'] ?? 0));
        }

        $stored = $condition['serviceIds'] ?? $condition['service_ids'] ?? array();
        $quoted = $context['serviceIds'] ?? $context['service_ids'] ?? array();
        $want = array_fill_keys(TNXL_Service_Coverage::coverage_ids($stored), true);
        if (empty($want)) {
            return false;
        }
        foreach (TNXL_Service_Coverage::coverage_ids($quoted) as $id) {
            if (isset($want[$id])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed[] $items
     * @param mixed[] $product_ids
     * @return array{inCart:bool,productSubtotal:float,matchingQty:int}
     */
    private static function matching_products(array $items, array $product_ids): array {
        $ids = array();
        foreach ($product_ids as $id) {
            $key = (string) $id;
            if ($key !== '') {
                $ids[$key] = true;
            }
        }
        $subtotal = 0.0;
        $qty = 0;
        $in_cart = false;
        foreach ($items as $item) {
            $candidates = array(
                (string) ($item['product_id'] ?? ''),
                (string) ($item['variation_id'] ?? ''),
            );
            $hit = false;
            foreach ($candidates as $pid) {
                if ($pid !== '' && isset($ids[$pid])) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            $in_cart = true;
            $line_qty = max(1, (int) ($item['quantity'] ?? 1));
            $qty += $line_qty;
            $subtotal += (float) ($item['line_total'] ?? 0);
        }
        return array('inCart' => $in_cart, 'productSubtotal' => $subtotal, 'matchingQty' => $qty);
    }

    private static function scaled_pickup(float $pickup, string $unit, int $matching_qty, float $cart_weight_kg): float {
        if ($pickup <= 0) {
            return 0;
        }
        if ($unit === 'per_item') {
            return $pickup * $matching_qty;
        }
        if ($unit === 'per_kg') {
            return $pickup * ($cart_weight_kg > 0 ? $cart_weight_kg : 0);
        }
        return $pickup;
    }

    private static function in_min_max(float $value, float $min, float $max): bool {
        $lo = $min > 0 ? $min : 0;
        $hi = $max > 0 ? $max : 0;
        if ($hi > 0 && $lo > $hi) {
            return $value >= $hi && $value <= $lo;
        }
        return $value >= $lo && ($hi <= 0 || $value <= $hi);
    }
}
