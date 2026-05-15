<?php
/**
 * TNX Checkout Block Integration
 */

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

if (!defined('ABSPATH')) exit;

class TNX_Checkout_Block_Integration implements IntegrationInterface {

    /**
     * The name of the integration.
     *
     * @return string
     */
    public function get_name() {
        return 'tnx-shipping';
    }

    /**
     * When called, this should register any scripts and styles for the integration.
     */
    public function initialize() {

        $script_url  = plugins_url('assets/js/tnx-checkout-block.js', dirname(dirname(__FILE__)) . '/thai-nexus-logistics.php');
        $script_file = dirname(dirname(__FILE__)) . '/assets/js/tnx-checkout-block.js';
        
        wp_register_script(
            'tnx-checkout-block-js',
            $script_url,
            array('wc-blocks-checkout', 'wp-data', 'wp-hooks', 'wc-settings'),
            filemtime($script_file),
            true
        );
    }

    /**
     * Returns an array of script handles to enqueue for this integration.
     *
     * @return array
     */
    public function get_script_handles() {
        return array('tnx-checkout-block-js');
    }

    /**
     * Returns an array of script handles to enqueue in the editor context.
     *
     * @return array
     */
    public function get_editor_script_handles() {
        return array();
    }

    /**
     * An array of key, value pairs of data made available to the block on the client side.
     *
     * @return array
     */
    public function get_script_data() {
        return array(
            'method_id' => 'tnx_shipping',
            'enabled'   => get_option('tnx_api_token', '') ? 'yes' : 'no',
        );
    }
}
