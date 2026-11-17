<?php

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Plugin Name:          Orboni for WooCommerce
 * Plugin URI:           https://orboni.com/docs/woocommerce
 * Description:          Accept crypto payments — USDT, USDC, BTC, ETH and more — straight to your own wallets. Orboni never holds your money.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Orboni
 * Author URI:           https://orboni.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          orboni-for-woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      11.3
 */
defined('ABSPATH') || exit;

define('ORBONI_WC_VERSION', '1.0.0');
define('ORBONI_WC_FILE', __FILE__);
define('ORBONI_WC_DIR', plugin_dir_path(__FILE__));
define('ORBONI_WC_URL', plugin_dir_url(__FILE__));

// Orders in their own tables, and the checkout drawn with blocks: both supported.
add_action('before_woocommerce_init', static function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        FeaturesUtil::declare_compatibility('custom_order_tables', ORBONI_WC_FILE, true);
        FeaturesUtil::declare_compatibility('cart_checkout_blocks', ORBONI_WC_FILE, true);
    }
});

add_action('plugins_loaded', 'orboni_wc_init');

/** Loads the gateway once WooCommerce is there. */
function orboni_wc_init()
{
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once ORBONI_WC_DIR.'includes/class-orboni-wc-log.php';
    require_once ORBONI_WC_DIR.'includes/class-orboni-wc-api.php';
    require_once ORBONI_WC_DIR.'includes/class-orboni-wc-signature.php';
    require_once ORBONI_WC_DIR.'includes/class-orboni-wc-order-sync.php';
    require_once ORBONI_WC_DIR.'includes/class-orboni-wc-gateway.php';
    require_once ORBONI_WC_DIR.'includes/class-orboni-wc-webhook.php';

    add_filter('woocommerce_payment_gateways', static function ($gateways) {
        $gateways[] = 'Orboni_WC_Gateway';

        return $gateways;
    });

    Orboni_WC_Webhook::register();
    Orboni_WC_Order_Sync::register();
}

// The block checkout's side of the gateway (WooCommerce 8.3+ draws checkout with blocks by default).
add_action('woocommerce_blocks_loaded', static function () {
    if (! class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once ORBONI_WC_DIR.'includes/class-orboni-wc-blocks.php';

    add_action('woocommerce_blocks_payment_method_type_registration', static function ($registry) {
        $registry->register(new Orboni_WC_Blocks);
    });
});

add_filter('plugin_action_links_'.plugin_basename(__FILE__), static function ($links) {
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=orboni');
    array_unshift($links, '<a href="'.esc_url($url).'">'.esc_html__('Settings', 'orboni-for-woocommerce').'</a>');

    return $links;
});

/**
 * The gateway WooCommerce made, with its saved settings.
 *
 * @return Orboni_WC_Gateway|null
 */
function orboni_wc_gateway()
{
    if (! function_exists('WC') || ! WC()->payment_gateways()) {
        return null;
    }
    $gateways = WC()->payment_gateways()->payment_gateways();

    return isset($gateways['orboni']) && $gateways['orboni'] instanceof Orboni_WC_Gateway ? $gateways['orboni'] : null;
}
