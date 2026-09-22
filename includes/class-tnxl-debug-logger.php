<?php
/**
 * TNXL Debug Logger
 * Handles temporary storage of shipping API debug logs.
 */

if (!defined('ABSPATH')) exit;

class TNXL_Debug_Logger {
    private static $instance = null;
    private $option_name = 'tnxl_debug_log';
    private $max_logs = 50;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Check if logging is enabled via constant
     */
    public static function is_enabled() {
        return defined('TNXL_DEBUG') && TNXL_DEBUG;
    }

    /**
     * Add a new log entry
     */
    public function log_entry($data) {
        if (!self::is_enabled()) return;

        $logs = get_option($this->option_name, array());
        if (!is_array($logs)) $logs = array();

        $fingerprint = md5(wp_json_encode(array(
            'destination'  => $data['destination'] ?? array(),
            'products'     => $data['products'] ?? array(),
            'boxes'        => $data['boxes'] ?? array(),
            'final_quotes' => $data['final_quotes'] ?? array(),
            'error'        => $data['error'] ?? '',
            'messages'     => $data['messages'] ?? array(),
        )));

        if (!empty($logs)) {
            $last_log = $logs[0];
            $last_fingerprint = $last_log['fingerprint'] ?? '';
            $last_time = strtotime($last_log['timestamp']);
            $current_time = strtotime(current_time('mysql'));

            if ($fingerprint === $last_fingerprint && ($current_time - $last_time) < 3) {
                return;
            }
        }

        $entry = array_merge(array(
            'id'          => uniqid(),
            'timestamp'   => current_time('mysql'),
            'fingerprint' => $fingerprint,
        ), $data);

        // Prepend new log
        array_unshift($logs, $entry);

        // Cap at max logs
        if (count($logs) > $this->max_logs) {
            $logs = array_slice($logs, 0, $this->max_logs);
        }

        update_option($this->option_name, $logs, false); // No autoload to keep it lean
        TNXL_D1_Copy::copy_debug_entry($entry);
    }

    /**
     * Get all logs
     */
    public function get_entries() {
        return get_option($this->option_name, array());
    }

    /**
     * Clear all logs
     */
    public function clear() {
        delete_option($this->option_name);
    }
}

