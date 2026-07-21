<?php
/**
 * TNXL Product Integration
 */

if (!defined('ABSPATH')) exit;

class TNXL_Product {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_product_options_shipping', array($this, 'add_shipping_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_shipping_fields'));
    }

    /**
     * Add "Is Document" checkbox to Product Shipping tab
     */
    public function add_shipping_fields() {
        $product = wc_get_product(get_the_ID());
        $eligibility_meta = $product
            ? get_post_meta($product->get_id(), '_tnxl_shipping_eligible', true)
            : '';

        echo '<div class="options_group">';
        woocommerce_wp_checkbox(array(
            'id'            => '_tnxl_is_document',
            'label'         => __('Is Document?', 'thai-nexus-logistics'),
            'description'   => __('Check this if the product is a document. If all items in cart are documents, document-specific rates will be retrieved.', 'thai-nexus-logistics'),
            'desc_tip'      => true,
        ));
        woocommerce_wp_checkbox(array(
            'id'            => '_tnxl_shipping_eligible',
            'label'         => __('Include in Thai Nexus shipping', 'thai-nexus-logistics'),
            'description'   => __('When disabled, Thai Nexus rates are hidden if this product is in the cart.', 'thai-nexus-logistics'),
            'desc_tip'      => true,
            'value'         => $eligibility_meta === '' || $eligibility_meta === 'yes' ? 'yes' : 'no',
        ));
        woocommerce_wp_checkbox(array(
            'id'            => '_tnxl_is_boxed_product',
            'label'         => __('Is Boxed Product?', 'thai-nexus-logistics'),
            'description'   => __('For a cart containing only this product, quote each unit using its retail dimensions instead of merchant packing boxes.', 'thai-nexus-logistics'),
            'desc_tip'      => true,
        ));
        echo '</div>';
    }

    /**
     * Save Product Shipping fields
     */
    public function save_shipping_fields($post_id) {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'update-post_' . $post_id)) {
            // Check for WooCommerce's own nonce if standard one is not present
            if (!isset($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woocommerce_meta_nonce'])), 'woocommerce_save_data')) {
                return;
            }
        }

        $is_document = isset($_POST['_tnxl_is_document']) ? 'yes' : 'no';
        update_post_meta($post_id, '_tnxl_is_document', sanitize_text_field($is_document));

        $shipping_eligible = isset($_POST['_tnxl_shipping_eligible']) ? 'yes' : 'no';
        update_post_meta($post_id, '_tnxl_shipping_eligible', $shipping_eligible);

        $is_boxed_product = isset($_POST['_tnxl_is_boxed_product']) ? 'yes' : 'no';
        update_post_meta($post_id, '_tnxl_is_boxed_product', $is_boxed_product);
    }

    public static function is_shipping_eligible($product): bool {
        if (!$product instanceof WC_Product) {
            return false;
        }

        $ids = array_filter(array($product->get_id(), $product->get_parent_id()));
        $excluded = TNXL_Settings::get_ineligible_product_ids();
        if (array_intersect($ids, $excluded)) {
            return false;
        }

        foreach ($ids as $id) {
            $value = get_post_meta($id, '_tnxl_shipping_eligible', true);
            if ($value !== '' && $value !== 'yes') {
                return false;
            }
        }

        return true;
    }

    public static function is_boxed_product($product): bool {
        return self::get_boolean_product_meta($product, '_tnxl_is_boxed_product');
    }

    public static function is_document($product): bool {
        return self::get_boolean_product_meta($product, '_tnxl_is_document');
    }

    /**
     * Resolve variation measurements with parent fallback and normalize to cm/kg.
     *
     * @return array{length: float, width: float, height: float, weight: float}
     */
    public static function get_shipping_measurements($product): array {
        if (!$product instanceof WC_Product) {
            return array('length' => 0.0, 'width' => 0.0, 'height' => 0.0, 'weight' => 0.0);
        }

        $values = array(
            'length' => (float) $product->get_length(),
            'width'  => (float) $product->get_width(),
            'height' => (float) $product->get_height(),
            'weight' => (float) $product->get_weight(),
        );

        if ($product->get_parent_id() && in_array(0.0, $values, true)) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                foreach ($values as $key => $value) {
                    if ($value <= 0) {
                        $getter = 'get_' . $key;
                        $values[$key] = (float) $parent->{$getter}();
                    }
                }
            }
        }

        return array(
            'length' => (float) wc_get_dimension($values['length'], 'cm'),
            'width'  => (float) wc_get_dimension($values['width'], 'cm'),
            'height' => (float) wc_get_dimension($values['height'], 'cm'),
            'weight' => (float) wc_get_weight($values['weight'], 'kg'),
        );
    }

    private static function get_boolean_product_meta($product, string $key): bool {
        if (!$product instanceof WC_Product) {
            return false;
        }

        $value = $product->get_meta($key);
        if ($value === '' && $product->get_parent_id()) {
            $value = get_post_meta($product->get_parent_id(), $key, true);
        }

        return $value === 'yes';
    }
}

