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
        // Product-level settings removed as shipping is now global.
    }
}
