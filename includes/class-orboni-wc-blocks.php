<?php

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * The gateway in the block checkout (the default since WooCommerce 8.3): its
 * title, description and icon for `assets/js/blocks.js`. Paying goes through
 * the same `process_payment()` as the classic checkout.
 */
final class Orboni_WC_Blocks extends AbstractPaymentMethodType
{
    protected $name = 'orboni';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_orboni_settings', []);
    }

    public function is_active()
    {
        $gateway = orboni_wc_gateway();

        // The cart's total is the script's to judge (`canMakePayment`): here there may be no cart.
        return $gateway !== null && $gateway->is_configured();
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'orboni-wc-blocks',
            ORBONI_WC_URL.'assets/js/blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
            ORBONI_WC_VERSION,
            true
        );

        return ['orboni-wc-blocks'];
    }

    public function get_payment_method_data()
    {
        $gateway = orboni_wc_gateway();

        return [
            'title' => $gateway ? $gateway->get_title() : __('Crypto', 'orboni-for-woocommerce'),
            'description' => $gateway ? $gateway->get_description() : '',
            'icon' => $gateway ? $gateway->icon : '',
            'currencies' => array_keys(Orboni_WC_Gateway::CURRENCIES),
            'minimum' => Orboni_WC_Gateway::MIN_CENTS,
            'supports' => $gateway ? array_values(array_filter($gateway->supports, [$gateway, 'supports'])) : ['products'],
        ];
    }
}
