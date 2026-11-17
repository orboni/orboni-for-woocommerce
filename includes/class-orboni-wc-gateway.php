<?php

defined('ABSPATH') || exit;

/**
 * "Crypto" at checkout: the buyer is sent to Orboni's payment page, picks a
 * coin and pays to the store's own wallet; the order is marked paid by the
 * signed webhook (`Orboni_WC_Webhook`), or by reading the session back when
 * the buyer returns to the "order received" page, whichever comes first.
 */
class Orboni_WC_Gateway extends WC_Payment_Gateway
{
    /**
     * The currencies Orboni takes a price in, and the decimals of each one's
     * smallest unit (ISO 4217): Orboni turns a price into dollars at the
     * rate of the day, the ECB's or the market's.
     */
    public const CURRENCIES = [
        'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CHF' => 2, 'PLN' => 2, 'CZK' => 2,
        'HUF' => 2, 'RON' => 2, 'DKK' => 2, 'SEK' => 2, 'NOK' => 2, 'ISK' => 0,
        'TRY' => 2, 'JPY' => 0, 'KRW' => 0, 'CNY' => 2, 'HKD' => 2, 'SGD' => 2,
        'INR' => 2, 'IDR' => 2, 'MYR' => 2, 'PHP' => 2, 'THB' => 2, 'ILS' => 2,
        'AUD' => 2, 'NZD' => 2, 'CAD' => 2, 'MXN' => 2, 'BRL' => 2, 'ZAR' => 2,
        'UAH' => 2, 'ARS' => 2, 'CLP' => 0, 'AED' => 2, 'SAR' => 2, 'TWD' => 2,
        'VND' => 0,
    ];

    /** Orboni's least payment is worth $1: checked here in a dollar store, by Orboni in any other. */
    public const MIN_CENTS = 100;

    /**
     * Time a payment page must still have to be reused when Pay is pressed
     * again: less, and the buyer gets a new one. A page Orboni still calls
     * open may be past its time (held open while a network cannot be read).
     */
    public const REUSE_MIN_SECONDS = 300;

    public const META_SESSION = '_orboni_session_id';

    /** Every session made for the order: a buyer may still pay an older one. */
    public const META_SESSIONS = '_orboni_session_ids';

    /** The amount the current session asks, in the currency's smallest unit. */
    public const META_AMOUNT = '_orboni_amount';

    public const META_ATTEMPT = '_orboni_attempt';

    public function __construct()
    {
        $this->id = 'orboni';
        $this->icon = (string) apply_filters('orboni_wc_icon', ORBONI_WC_URL.'assets/images/orboni.svg');
        $this->has_fields = false;
        $this->method_title = __('Orboni — crypto payments', 'orboni-for-woocommerce');
        $this->method_description = __('Buyers pay with USDT, USDC, BTC, ETH and other coins on Orboni\'s payment page. The money goes straight to your own wallets: Orboni never holds it.', 'orboni-for-woocommerce');
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = (string) $this->get_option('title');
        $this->description = (string) $this->get_option('description');

        add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_'.$this->id, [$this, 'on_return']);
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable', 'orboni-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Take crypto payments through Orboni', 'orboni-for-woocommerce'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'orboni-for-woocommerce'),
                'type' => 'text',
                'description' => __('What the buyer sees at checkout.', 'orboni-for-woocommerce'),
                'default' => __('Crypto (USDT, USDC, BTC and more)', 'orboni-for-woocommerce'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'orboni-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Shown under the title when the buyer picks it.', 'orboni-for-woocommerce'),
                'default' => __('Pay from your wallet or exchange. You will be taken to a secure payment page to choose a coin.', 'orboni-for-woocommerce'),
                'desc_tip' => true,
            ],
            'secret_key' => [
                'title' => __('Secret key', 'orboni-for-woocommerce'),
                'type' => 'password',
                'description' => __('In your Orboni dashboard, under Developers → API keys. A test key (sk_test_…) to try it, a live key (sk_live_…) for real payments.', 'orboni-for-woocommerce'),
                'default' => '',
            ],
            'webhook_secret' => [
                'title' => __('Webhook signing secret', 'orboni-for-woocommerce'),
                'type' => 'password',
                'description' => sprintf(
                    /* translators: %s: this store's webhook address */
                    __('In your Orboni dashboard, under Developers → Webhooks, add an endpoint with the address %s and paste its signing secret (whsec_…) here. Without it orders are marked paid only when the buyer comes back to your store.', 'orboni-for-woocommerce'),
                    '<code>'.esc_html(Orboni_WC_Webhook::url()).'</code>'
                ),
                'default' => '',
            ],
            'debug' => [
                'title' => __('Debug log', 'orboni-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Log requests to Orboni and webhooks under WooCommerce → Status → Logs', 'orboni-for-woocommerce'),
                'default' => 'no',
            ],
        ];
    }

    public function admin_options()
    {
        if (! self::supports_currency(get_woocommerce_currency())) {
            echo '<div class="notice notice-warning inline"><p>'.esc_html(sprintf(
                /* translators: 1: the store's currency code, e.g. ARS, 2: the codes Orboni takes, comma-separated */
                __('Orboni does not take prices in %1$s, this store\'s currency: crypto will not be offered at checkout. It takes %2$s.', 'orboni-for-woocommerce'),
                get_woocommerce_currency(),
                implode(', ', array_keys(self::CURRENCIES))
            )).'</p></div>';
        }

        $key = (string) $this->get_option('secret_key');
        if (strpos($key, 'sk_test_') === 0) {
            echo '<div class="notice notice-info inline"><p>'.esc_html__('Test mode: a test key makes test payments only. Open the payment page while signed in to your Orboni dashboard to simulate one.', 'orboni-for-woocommerce').'</p></div>';
        }

        parent::admin_options();
    }

    public function validate_secret_key_field($key, $value)
    {
        $value = trim((string) $value, " \t\n\r\0\x0B");
        if ($value !== '' && preg_match('/^sk_(live|test)_[A-Za-z0-9]+$/', $value) !== 1) {
            WC_Admin_Settings::add_error(__('The secret key starts with sk_live_ or sk_test_. A publishable key (pk_…) will not work here.', 'orboni-for-woocommerce'));

            return (string) $this->get_option('secret_key');
        }

        return $value;
    }

    public function validate_webhook_secret_field($key, $value)
    {
        $value = trim((string) $value, " \t\n\r\0\x0B");
        if ($value !== '' && preg_match('/^whsec_[A-Za-z0-9]+$/', $value) !== 1) {
            WC_Admin_Settings::add_error(__('The webhook signing secret starts with whsec_.', 'orboni-for-woocommerce'));

            return (string) $this->get_option('webhook_secret');
        }

        return $value;
    }

    /** Switched on, with a key, in a store whose currency Orboni takes: what any screen may ask, a cart or not. */
    public function is_configured()
    {
        return parent::is_available()
            && (string) $this->get_option('secret_key') !== ''
            && self::supports_currency(get_woocommerce_currency());
    }

    public function is_available()
    {
        if (! $this->is_configured()) {
            return false;
        }

        // A cart's or the order-pay page's total: below Orboni's minimum there is nothing to offer.
        // Elsewhere (the admin, a REST call) there is no total to read.
        $paying = absint(get_query_var('order-pay')) > 0;
        if (! $paying && ! (function_exists('WC') && WC()->cart)) {
            return true;
        }
        $total = (float) $this->get_order_total();
        $currency = get_woocommerce_currency();

        return $total <= 0 || $currency !== 'USD' || self::minor($total, $currency) >= self::MIN_CENTS;
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order) {
            return ['result' => 'failure'];
        }

        $currency = strtoupper((string) $order->get_currency());
        if (! self::supports_currency($currency)) {
            wc_add_notice(sprintf(
                /* translators: %s: the order's currency code, e.g. ARS */
                __('Crypto payments are not taken in %s.', 'orboni-for-woocommerce'),
                $currency
            ), 'error');

            return ['result' => 'failure'];
        }

        $amount = self::minor((float) $order->get_total(), $currency);
        if ($currency === 'USD' && $amount < self::MIN_CENTS) {
            wc_add_notice(__('This order is below the minimum for a crypto payment ($1).', 'orboni-for-woocommerce'), 'error');

            return ['result' => 'failure'];
        }

        $api = new Orboni_WC_Api((string) $this->get_option('secret_key'));

        // Back from the payment page, or the form sent twice: the session still open
        // for this amount is reused, unless it has run out or is about to.
        $existing = (string) $order->get_meta(self::META_SESSION);
        if ($existing !== '' && (int) $order->get_meta(self::META_AMOUNT) === $amount) {
            $session = $api->get_session($existing);
            if (self::reusable($session)) {
                return ['result' => 'success', 'redirect' => $session['url']];
            }
        }

        // The key is kept while a try fails, so a session Orboni made for a try
        // whose answer was lost is the one the next try gets.
        $attempt = (int) $order->get_meta(self::META_ATTEMPT) + 1;
        $body = $this->session_body($order, $amount, $currency);
        $session = $api->create_session($body, self::idempotency_key($order, $amount, $currency, $attempt));

        // That lost try was sent with other details (the buyer's language, an
        // edited item): Orboni refuses the key for them, and a new one is taken.
        if (is_wp_error($session) && self::error_type($session) === 'idempotency_error' && (int) (($session->get_error_data())['status'] ?? 0) === 400) {
            $attempt++;
            $session = $api->create_session($body, self::idempotency_key($order, $amount, $currency, $attempt));
        }

        if (is_wp_error($session)) {
            $type = self::error_type($session);
            Orboni_WC_Log::error(sprintf('Order %d: Orboni refused a session (%s): %s', $order->get_id(), $type, $session->get_error_message()));

            // Below the least Orboni takes: under a network's minimum, or worth under $1 in another currency.
            $param = is_array($session->get_error_data()) ? (string) (($session->get_error_data())['param'] ?? '') : '';
            if ($type === 'amount_too_small' || ($type === 'invalid_request_error' && $param === 'amount')) {
                wc_add_notice(__('This order is below the minimum amount for a crypto payment.', 'orboni-for-woocommerce'), 'error');
            } else {
                wc_add_notice(__('Crypto payment is not available right now. Please try again in a minute or choose another payment method.', 'orboni-for-woocommerce'), 'error');
            }

            return ['result' => 'failure'];
        }

        if (! is_string($session['id'] ?? null) || ! is_string($session['url'] ?? null)) {
            Orboni_WC_Log::error(sprintf('Order %d: Orboni answered without a session id or url.', $order->get_id()));
            wc_add_notice(__('Crypto payment is not available right now. Please try again in a minute or choose another payment method.', 'orboni-for-woocommerce'), 'error');

            return ['result' => 'failure'];
        }

        $known = $order->get_meta(self::META_SESSIONS);
        $known = is_array($known) ? $known : [];
        $known[] = $session['id'];

        $order->update_meta_data(self::META_SESSION, $session['id']);
        $order->update_meta_data(self::META_SESSIONS, array_values(array_unique($known)));
        $order->update_meta_data(self::META_AMOUNT, $amount);
        $order->update_meta_data(self::META_ATTEMPT, $attempt);
        $expires = (int) ($session['expires_at'] ?? 0) ?: time() + HOUR_IN_SECONDS;
        $order->update_meta_data(Orboni_WC_Order_Sync::META_EXPIRES, $expires);
        $order->add_order_note(sprintf(
            /* translators: %s: Orboni checkout session id */
            __('Buyer sent to the Orboni payment page (session %s).', 'orboni-for-woocommerce'),
            $session['id']
        ));
        $order->save();
        Orboni_WC_Order_Sync::schedule($order, $expires);

        Orboni_WC_Log::info(sprintf('Order %d: session %s for %s.', $order->get_id(), $session['id'], self::format_minor($amount, $currency)));

        return ['result' => 'success', 'redirect' => $session['url']];
    }

    /**
     * The buyer is back on "order received": the session is read once, so
     * the order is right even before the webhook comes (or without one).
     */
    public function on_return($order_id)
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order || $order->is_paid()) {
            return;
        }

        $id = (string) $order->get_meta(self::META_SESSION);
        if ($id === '') {
            return;
        }

        $session = (new Orboni_WC_Api((string) $this->get_option('secret_key')))->get_session($id);
        if (is_wp_error($session)) {
            Orboni_WC_Log::error(sprintf('Order %d: could not read session %s: %s', $order->get_id(), $id, $session->get_error_message()));

            return;
        }

        Orboni_WC_Order_Sync::apply_locked($order->get_id(), $session);

        $payment = (string) ($session['payment_status'] ?? '');
        if ($payment === 'processing') {
            echo '<p class="orboni-wc-waiting">'.esc_html__('Your payment is on its way. The order will be confirmed as soon as the network confirms the transfer — you can close this page.', 'orboni-for-woocommerce').'</p>';
        } elseif ($payment === 'held') {
            echo '<p class="orboni-wc-waiting">'.esc_html__('Your payment has arrived and the store is checking it. You will hear from the store once the order is confirmed.', 'orboni-for-woocommerce').'</p>';
        }
    }

    /** @return array<string, mixed> */
    private function session_body(WC_Order $order, $amount, $currency)
    {
        $email = (string) $order->get_billing_email();
        $body = [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'description' => self::fit(self::items($order), 250),
            'details' => self::fit(sprintf(
                /* translators: 1: order number, 2: store name */
                __('Order %1$s at %2$s', 'orboni-for-woocommerce'),
                $order->get_order_number(),
                wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
            ), 500),
            'client_reference_id' => (string) $order->get_id(),
            'customer_email' => is_email($email) ? $email : null,
            'locale' => str_replace('_', '-', determine_locale()),
            'success_url' => $this->get_return_url($order),
            // Back to choose another way to pay: the checkout, whose cart is still full,
            // or the order's own pay page for an order made in the admin.
            'cancel_url' => in_array($order->get_created_via(), ['checkout', 'store-api'], true)
                ? wc_get_checkout_url()
                : $order->get_checkout_payment_url(),
            'metadata' => [
                'woocommerce_order_id' => (string) $order->get_id(),
                'woocommerce_order_number' => (string) $order->get_order_number(),
                'woocommerce_site' => self::site_key(),
            ],
        ];

        return array_filter($body, static function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /** "Blue mug × 2, Tea" — what the payment page shows as the purchase. */
    private static function items(WC_Order $order)
    {
        $names = [];
        foreach ($order->get_items() as $item) {
            $name = trim(wp_strip_all_tags((string) $item->get_name()), " \t\n\r\0\x0B");
            $quantity = (int) $item->get_quantity();
            $names[] = $quantity > 1 ? $name.' × '.$quantity : $name;
        }

        return implode(', ', array_filter($names));
    }

    /** At most `$max` characters, cut at a word with an ellipsis. */
    private static function fit($text, $max)
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text), " \t\n\r\0\x0B");
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,.;:').'…';
    }

    /**
     * The store, the order, the amount and the try. The order's own random key
     * is in it too: a store restored from a backup numbers its orders again,
     * and Orboni keeps a key a day.
     */
    private static function idempotency_key(WC_Order $order, $amount, $currency, $attempt)
    {
        $instance = substr(hash('sha256', (string) $order->get_order_key()), 0, 8);

        return sprintf('wc-%s-%d-%s-%d%s-%d', self::site_key(), $order->get_id(), $instance, $amount, strtolower($currency), $attempt);
    }

    /**
     * A session the buyer can still pay on: open, with its page, and more
     * than REUSE_MIN_SECONDS before it runs out.
     *
     * @param  mixed  $session
     */
    private static function reusable($session)
    {
        return is_array($session)
            && ($session['status'] ?? '') === 'open'
            && is_string($session['url'] ?? null)
            && (int) ($session['expires_at'] ?? 0) > time() + self::REUSE_MIN_SECONDS;
    }

    private static function error_type(WP_Error $error)
    {
        $data = $error->get_error_data();

        return is_array($data) ? (string) ($data['type'] ?? '') : '';
    }

    public static function supports_currency($currency)
    {
        return isset(self::CURRENCIES[strtoupper((string) $currency)]);
    }

    public static function decimals($currency)
    {
        return self::CURRENCIES[strtoupper((string) $currency)] ?? 2;
    }

    /** A price in the currency's smallest unit, as Orboni takes it: 1250 for 12.50 EUR, 1500 for 1500 JPY. */
    public static function minor($amount, $currency)
    {
        return (int) round(((float) $amount) * (10 ** self::decimals($currency)));
    }

    /** "12.50 EUR" for 1250 euro cents, "1500 JPY" for 1500 yen. */
    public static function format_minor($amount, $currency)
    {
        $decimals = self::decimals($currency);

        return number_format(((int) $amount) / (10 ** $decimals), $decimals, '.', '').' '.strtoupper((string) $currency);
    }

    /**
     * This store among others on the same Orboni project: in the idempotency
     * key and the session's metadata, so two stores' order 42 never meet.
     */
    public static function site_key()
    {
        return substr(hash('sha256', (string) home_url()), 0, 12);
    }
}
