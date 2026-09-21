<?php
/**
 * Per-service destination coverage (All Available / selected / exclude / rest of world).
 */

if (!defined('ABSPATH')) exit;

class TNXL_Service_Coverage {

    public static function coverage_ids($service_id): array {
        $raw = is_array($service_id) ? $service_id : array($service_id);
        $seen = array();
        $ids = array();
        foreach ($raw as $value) {
            foreach (self::service_aliases((string) $value) as $alias) {
                if (isset($seen[$alias])) {
                    continue;
                }
                $seen[$alias] = true;
                $ids[] = $alias;
            }
        }
        return $ids;
    }

    /**
     * @param string[]|string $service_id
     * @param array<string, mixed> $coverage
     */
    public static function covers_destination($service_id, string $destination_country, array $disabled_ids, array $coverage): bool {
        $ids = self::coverage_ids($service_id);
        if (empty($ids)) {
            return false;
        }
        if (self::is_disabled($service_id, $disabled_ids)) {
            return false;
        }

        $rule = self::lookup($service_id, $coverage);
        if (!$rule) {
            return true;
        }

        $dest = strtoupper(trim($destination_country));
        $countries = isset($rule['countries']) && is_array($rule['countries']) ? $rule['countries'] : array();

        if (!empty($rule['excludeCountries']) || !empty($rule['exclude_countries'])) {
            if (empty($countries)) {
                return false;
            }
            if ($dest === '') {
                return false;
            }
            foreach ($countries as $code) {
                if (strtoupper(trim((string) $code)) === $dest) {
                    return false;
                }
            }
            return true;
        }

        if (!empty($rule['worldwide'])) {
            return true;
        }

        if (!empty($rule['restOfWorld']) || !empty($rule['rest_of_world'])) {
            return $dest !== '';
        }

        if (empty($countries)) {
            return false;
        }
        if ($dest === '') {
            return false;
        }
        foreach ($countries as $code) {
            if (strtoupper(trim((string) $code)) === $dest) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $quotes
     * @return array<int, array<string, mixed>>
     */
    public static function filter_checkout_quotes(array $quotes, callable $ids_for, string $destination_country, array $disabled_ids, array $coverage): array {
        $eligible = array();
        foreach ($quotes as $quote) {
            if (self::covers_destination($ids_for($quote), $destination_country, $disabled_ids, $coverage)) {
                $eligible[] = $quote;
            }
        }

        $primary = array();
        foreach ($eligible as $quote) {
            if (!self::is_rest_of_world($ids_for($quote), $coverage)) {
                $primary[] = $quote;
            }
        }

        return $primary ? $primary : $eligible;
    }

    /**
     * @param string[]|string $service_id
     * @param array<string, mixed> $coverage
     */
    public static function is_rest_of_world($service_id, array $coverage): bool {
        $rule = self::lookup($service_id, $coverage);
        return is_array($rule) && (!empty($rule['restOfWorld']) || !empty($rule['rest_of_world']));
    }

    /**
     * @param mixed[] $ids
     */
    private static function is_disabled($service_id, array $ids): bool {
        $want = array_fill_keys(self::coverage_ids($service_id), true);
        foreach ($ids as $raw) {
            foreach (self::service_aliases((string) $raw) as $alias) {
                if (isset($want[$alias])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $coverage
     * @return array<string, mixed>|null
     */
    private static function lookup($service_id, array $coverage) {
        if (empty($coverage)) {
            return null;
        }
        $want = array_fill_keys(self::coverage_ids($service_id), true);
        if (empty($want)) {
            return null;
        }
        foreach ($coverage as $key => $rule) {
            foreach (self::service_aliases((string) $key) as $alias) {
                if (isset($want[$alias]) && is_array($rule)) {
                    return $rule;
                }
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    private static function service_aliases(string $value): array {
        $raw = trim($value);
        if ($raw === '') {
            return array();
        }
        $seen = array();
        $aliases = array();
        $id = TNXL_Settings::normalize_service_id($raw);
        foreach (array($id, TNXL_Settings::normalize_service_id(str_replace('_', ' ', $raw))) as $alias) {
            if ($alias === '' || isset($seen[$alias])) {
                continue;
            }
            $seen[$alias] = true;
            $aliases[] = $alias;
        }
        return $aliases;
    }
}
