<?php
/**
 * TNX Debug Logger
 * Handles temporary storage of shipping API debug logs.
 */

if (!defined('ABSPATH')) exit;

class TNX_Debug_Logger {
    private static $instance = null;
    private $option_name = 'tnx_debug_log';
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
        return defined('TNX_DEBUG_LOG') && TNX_DEBUG_LOG;
    }

    /**
     * Add a new log entry
     */
    public function log_entry($data) {
        if (!self::is_enabled()) return;

        $logs = get_option($this->option_name, array());
        if (!is_array($logs)) $logs = array();

        // Generate fingerprint for duplicate detection (exclude ephemeral fields)
        $fingerprint = md5(json_encode($data));
        
        // Check if the most recent log is the same and within a short time frame (60 seconds)
        if (!empty($logs)) {
            $last_log = $logs[0];
            $last_fingerprint = $last_log['fingerprint'] ?? '';
            $last_time = strtotime($last_log['timestamp']);
            $current_time = strtotime(current_time('mysql'));

            if ($fingerprint === $last_fingerprint && ($current_time - $last_time) < 60) {
                // Duplicate detected within 60 seconds, skip logging
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
