<?php
/**
 * TNX Product Integration
 */

if (!defined('ABSPATH')) exit;

class TNX_Product {

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
        echo '<div class="options_group">';
        woocommerce_wp_checkbox(array(
            'id'            => '_tnx_is_document',
            'label'         => __('Is Document?', 'thai-nexus-logistics'),
            'description'   => __('Check this if the product is a document. If all items in cart are documents, document-specific rates will be retrieved.', 'thai-nexus-logistics'),
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

        $is_document = isset($_POST['_tnx_is_document']) ? 'yes' : 'no';
        update_post_meta($post_id, '_tnx_is_document', sanitize_text_field($is_document));
    }
}
