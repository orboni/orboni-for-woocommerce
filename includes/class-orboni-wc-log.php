<?php

defined('ABSPATH') || exit;

/**
 * WooCommerce → Status → Logs, source "orboni", while the gateway's debug
 * setting is on. Never given a key or a signing secret.
 */
class Orboni_WC_Log
{
    /** @var bool|null */
    private static $enabled = null;

    public static function info($message)
    {
        self::write('info', $message);
    }

    public static function error($message)
    {
        // Errors are kept whatever the setting: a merchant asking why crypto failed needs them.
        self::write('error', $message, true);
    }

    private static function write($level, $message, $always = false)
    {
        if (! function_exists('wc_get_logger')) {
            return;
        }
        if (! $always) {
            if (self::$enabled === null) {
                $settings = get_option('woocommerce_orboni_settings', []);
                self::$enabled = is_array($settings) && ($settings['debug'] ?? 'no') === 'yes';
            }
            if (! self::$enabled) {
                return;
            }
        }

        wc_get_logger()->log($level, (string) $message, ['source' => 'orboni']);
    }
}
