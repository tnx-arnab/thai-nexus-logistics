<?php
/**
 * TNXL Data Migration Class
 * Handles one-time migration of options and meta from tnx_ to tnxl_ prefix.
 */

if (!defined('ABSPATH')) exit;

class TNXL_Migration {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'maybe_migrate'), 5);
    }

    public function maybe_migrate() {
        if (get_option('tnxl_migrated_prefix')) {
            return;
        }

        $this->migrate_options();
        $this->migrate_post_meta();

        update_option('tnxl_migrated_prefix', time());
    }

    private function migrate_options() {
        $options_to_migrate = array(
            'tnx_api_token',
            'tnx_shipper_name',
            'tnx_shipper_phone',
            'tnx_shipper_address',
            'tnx_shipper_city',
            'tnx_shipper_state',
            'tnx_shipper_postal_code',
            'tnx_shipper_country',
            'tnx_commission_rules',
            'tnx_box_definitions',
            'tnx_debug_log'
        );

        foreach ($options_to_migrate as $old_option) {
            $value = get_option($old_option);
            if ($value !== false) {
                $new_option = str_replace('tnx_', 'tnxl_', $old_option);
                update_option($new_option, $value);
            }
        }
    }

    private function migrate_post_meta() {
        global $wpdb;

        $meta_keys = array(
            '_tnx_is_document'    => '_tnxl_is_document',
            '_tnx_shipment_id'     => '_tnxl_shipment_id',
            '_tnx_request_number'  => '_tnxl_request_number',
            '_tnx_status'          => '_tnxl_status',
            '_tnx_all_shipments'   => '_tnxl_all_shipments',
            '_tnx_packed_boxes'    => '_tnxl_packed_boxes'
        );

        foreach ($meta_keys as $old_key => $new_key) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                 SELECT post_id, %s, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = %s
                 AND post_id NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s)",
                $new_key, $old_key, $new_key
            ));
        }
    }
}
