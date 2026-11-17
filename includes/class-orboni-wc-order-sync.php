<?php

defined('ABSPATH') || exit;

/**
 * An Orboni checkout session, read back, onto its WooCommerce order. The one
 * place an order changes for a payment: the webhook, the "order received"
 * page and the scheduled check all come here with the session as Orboni
 * shows it now, so the order follows the payment whatever arrives first,
 * twice or out of order.
 *
 * - complete + paid → `payment_complete()` (processing, or completed for
 *   orders WooCommerce completes itself);
 * - on its way (processing), short of the amount (partially_paid) or held
 *   for the merchant's review (held) → on hold, with what happened;
 * - expired with nothing paid → cancelled, if it is still the order's own
 *   session and the order still waits for payment;
 * - a paid order is taken back to on hold only when Orboni says money that
 *   paid it left the chain (`payment_reversed`); nothing else downgrades it.
 */
class Orboni_WC_Order_Sync
{
    /** What was last applied, "id:status:payment_status": the same state twice adds no second note. */
    public const META_STATE = '_orboni_state';

    public const META_EXPIRES = '_orboni_expires_at';

    public const REVERSED = 'checkout.session.payment_reversed';

    public const EXTRA = 'checkout.session.extra_received';

    /** The scheduled read-back (Action Scheduler, which WooCommerce ships). */
    public const CHECK_HOOK = 'orboni_wc_check_order';

    /** Minutes after the session is made, then after it expires, that it is read back. */
    private const CHECK_AFTER_CREATED = [10];

    private const CHECK_AFTER_EXPIRY = [2, 30, 65];

    /** Orboni's network keys as people write them; a network added later reads as its key. */
    private const CHAIN_NAMES = [
        'tron' => 'TRON', 'ethereum' => 'Ethereum', 'bsc' => 'BNB Chain', 'solana' => 'Solana', 'ton' => 'TON',
        'polygon' => 'Polygon', 'base' => 'Base', 'arbitrum' => 'Arbitrum', 'optimism' => 'Optimism',
        'avalanche' => 'Avalanche', 'bitcoin' => 'Bitcoin', 'litecoin' => 'Litecoin', 'dogecoin' => 'Dogecoin',
        'bitcoincash' => 'Bitcoin Cash', 'xrp' => 'XRP Ledger', 'stellar' => 'Stellar',
    ];

    /** Seconds a lock left by a request that died is waited out. */
    private const LOCK_SECONDS = 60;

    public static function register()
    {
        add_action(self::CHECK_HOOK, [self::class, 'check']);
        add_filter('woocommerce_cancel_unpaid_order', [self::class, 'keep_unpaid'], 10, 2);
    }

    /**
     * {@see apply()} with the order loaded fresh under a lock: the webhook
     * and the buyer's return can land in the same second.
     *
     * @param  array<string, mixed>  $session
     * @return bool|null true applied, false an event already taken, null the order is busy
     */
    public static function apply_locked($order_id, array $session, $event = '', $event_id = '')
    {
        $lock = 'orboni_wc_lock_'.(int) $order_id;
        // add_option() inserts or fails: one request at a time holds it.
        if (! add_option($lock, time(), '', false)) {
            if ((int) get_option($lock) > time() - self::LOCK_SECONDS) {
                return null;
            }
            update_option($lock, time(), false);
        }

        try {
            $order = wc_get_order($order_id);
            if (! $order instanceof WC_Order) {
                return false;
            }

            if ($event_id !== '') {
                $taken = $order->get_meta(Orboni_WC_Webhook::META_EVENTS);
                $taken = is_array($taken) ? $taken : [];
                if (in_array($event_id, $taken, true)) {
                    return false;
                }
                $taken[] = $event_id;
                $order->update_meta_data(Orboni_WC_Webhook::META_EVENTS, array_slice($taken, -Orboni_WC_Webhook::EVENTS_KEPT));
            }

            self::apply($order, $session, $event);
            $order->save();

            return true;
        } finally {
            delete_option($lock);
        }
    }

    /**
     * Read-backs for a new session: the order is right even when no webhook
     * is set up and the buyer never comes back.
     */
    public static function schedule(WC_Order $order, $expires_at)
    {
        if (! function_exists('as_schedule_single_action')) {
            return;
        }

        $args = ['order_id' => $order->get_id()];
        as_unschedule_all_actions(self::CHECK_HOOK, $args, 'orboni');

        $times = [];
        foreach (self::CHECK_AFTER_CREATED as $minutes) {
            $times[] = time() + $minutes * MINUTE_IN_SECONDS;
        }
        foreach (self::CHECK_AFTER_EXPIRY as $minutes) {
            $times[] = (int) $expires_at + $minutes * MINUTE_IN_SECONDS;
        }
        foreach (array_unique($times) as $time) {
            as_schedule_single_action($time, self::CHECK_HOOK, $args, 'orboni');
        }
    }

    /** A scheduled read-back. */
    public static function check($order_id)
    {
        $order = wc_get_order($order_id);
        $gateway = orboni_wc_gateway();
        if (! $order instanceof WC_Order || ! $gateway || $order->get_payment_method() !== 'orboni' || $order->is_paid()) {
            return;
        }

        $id = (string) $order->get_meta(Orboni_WC_Gateway::META_SESSION);
        if ($id === '') {
            return;
        }

        $session = (new Orboni_WC_Api((string) $gateway->get_option('secret_key')))->get_session($id);
        if (is_wp_error($session)) {
            Orboni_WC_Log::error(sprintf('Order %d: scheduled check could not read session %s: %s', $order->get_id(), $id, $session->get_error_message()));

            return;
        }

        self::apply_locked($order->get_id(), $session);
    }

    /**
     * WooCommerce cancels an order unpaid after "Hold stock" minutes (60 by
     * default). An Orboni order waits instead while its payment page is open
     * and an hour past it — what was sent in time may still confirm; the
     * last scheduled check cancels it if nothing came.
     */
    public static function keep_unpaid($cancel, $order)
    {
        if (! $order instanceof WC_Order || $order->get_payment_method() !== 'orboni') {
            return $cancel;
        }
        $expires = (int) $order->get_meta(self::META_EXPIRES);

        return $expires > 0 && time() < $expires + max(self::CHECK_AFTER_EXPIRY) * MINUTE_IN_SECONDS ? false : $cancel;
    }

    /**
     * @param  array<string, mixed>  $session  Orboni's checkout session object
     * @param  string  $event  the webhook's type, when a webhook brought it
     */
    private static function apply(WC_Order $order, array $session, $event = '')
    {
        $id = (string) ($session['id'] ?? '');
        if ($id === '' || ! self::belongs($order, $session)) {
            Orboni_WC_Log::error(sprintf('Order %d: session %s is not this order\'s; left alone.', $order->get_id(), $id));

            return;
        }

        $status = (string) ($session['status'] ?? '');
        $payment = (string) ($session['payment_status'] ?? '');
        $state = $id.':'.$status.':'.$payment;
        $paid_here = $status === 'complete' && $payment === 'paid';

        if ($order->is_paid()) {
            self::after_paid($order, $session, $event, $paid_here, $state);

            return;
        }

        if ((string) $order->get_meta(self::META_STATE) === $state) {
            return;
        }
        $order->update_meta_data(self::META_STATE, $state);

        if ($paid_here) {
            self::complete($order, $session);

            return;
        }

        switch ($payment) {
            case 'processing':
                self::hold($order, __('A payment is on the chain and waiting for confirmations. The order will move on by itself once it is final.', 'orboni-for-woocommerce'));

                return;

            case 'partially_paid':
                self::hold($order, $status === 'expired'
                    ? sprintf(
                        /* translators: 1: the amount received, 2: the amount due, each with its currency, e.g. "25.00 EUR" */
                        __('The payment page closed partly paid: %1$s of %2$s arrived in your wallet. Settle it with the buyer (send the rest back or ask for the difference); Orboni will not complete this order.', 'orboni-for-woocommerce'),
                        self::money($session, 'amount_received'),
                        self::money($session, 'amount_due')
                    )
                    : sprintf(
                        /* translators: 1: the amount received, 2: the amount due, each with its currency, e.g. "25.00 EUR" */
                        __('Partly paid so far: %1$s of %2$s. The payment page asks the buyer for the rest.', 'orboni-for-woocommerce'),
                        self::money($session, 'amount_received'),
                        self::money($session, 'amount_due')
                    ));

                return;

            case 'held':
                self::hold($order, sprintf(
                    /* translators: %s: why Orboni held the payment, e.g. "late" or "overpaid" */
                    __('The money is in your wallet but held for your review in the Orboni dashboard (reason: %s). Accept it there and this order is marked paid.', 'orboni-for-woocommerce'),
                    (string) ($session['review_reason'] ?? '—')
                ));

                return;
        }

        if ($status === 'expired' && $payment === 'unpaid') {
            // A newer session (the buyer came back and pressed Pay again) still counts.
            if ((string) $order->get_meta(Orboni_WC_Gateway::META_SESSION) === $id && $order->has_status('pending')) {
                $order->update_status('cancelled', __('The Orboni payment page expired with nothing paid.', 'orboni-for-woocommerce'));

                return;
            }
        }

        $order->save();
    }

    /**
     * This order's session: made for it by this store. The webhook checks
     * the same before reading anything back, and a session read back is
     * checked again here.
     *
     * @param  array<string, mixed>  $session
     */
    public static function belongs(WC_Order $order, array $session)
    {
        $id = (string) ($session['id'] ?? '');
        $known = $order->get_meta(Orboni_WC_Gateway::META_SESSIONS);

        return $order->get_payment_method() === 'orboni'
            && is_array($known) && in_array($id, $known, true)
            && (string) ($session['client_reference_id'] ?? '') === (string) $order->get_id()
            && (string) (((array) ($session['metadata'] ?? []))['woocommerce_site'] ?? '') === Orboni_WC_Gateway::site_key();
    }

    /** @param  array<string, mixed>  $session */
    private static function complete(WC_Order $order, array $session)
    {
        $asked = (int) ($session['amount_total'] ?? 0);
        $asked_in = strtoupper((string) ($session['currency'] ?? ''));
        $currency = strtoupper((string) $order->get_currency());
        $due = Orboni_WC_Gateway::minor((float) $order->get_total(), $currency);

        // The order was edited to cost more (or another currency) after the buyer was sent to pay.
        if ($asked_in !== $currency || $asked < $due) {
            self::hold($order, sprintf(
                /* translators: 1: the amount the session asked, with its currency, 2: the order's total, with its currency */
                __('Orboni completed a payment of %1$s, but this order now totals %2$s. Check the order before shipping.', 'orboni-for-woocommerce'),
                Orboni_WC_Gateway::format_minor($asked, $asked_in),
                Orboni_WC_Gateway::format_minor($due, $currency)
            ));

            return;
        }

        $order->add_order_note(self::paid_note($session));
        $order->payment_complete((string) $session['id']);
        Orboni_WC_Log::info(sprintf('Order %d: paid by session %s.', $order->get_id(), $session['id']));
    }

    /** @param  array<string, mixed>  $session */
    private static function after_paid(WC_Order $order, array $session, $event, $paid_here, $state)
    {
        $id = (string) $session['id'];
        $paid_by = (string) $order->get_transaction_id();

        if ($paid_by === $id) {
            if ($event === self::REVERSED && ! $paid_here) {
                // Recorded, so the session paid in full again completes the order again.
                $order->update_meta_data(self::META_STATE, $state);
                $order->update_status('on-hold', sprintf(
                    /* translators: %s: the amount still counted as received, with its currency */
                    __('Orboni reports that money which paid this order left the chain (a reorganisation or a double spend); %s still counts as received. Do not ship until the Orboni dashboard shows it paid again.', 'orboni-for-woocommerce'),
                    self::money($session, 'amount_received')
                ));
                Orboni_WC_Log::error(sprintf('Order %d: payment reversed on session %s.', $order->get_id(), $id));
            } elseif ($event === self::EXTRA) {
                $order->add_order_note(sprintf(
                    /* translators: %s: the amount received in all, with its currency */
                    __('More money arrived after this order was paid: %s received in all. Orboni does not complete an order twice; send the difference back from your wallet if the buyer asks.', 'orboni-for-woocommerce'),
                    self::money($session, 'amount_received')
                ));
            }

            return;
        }

        // Another of the order's sessions paid too: the buyer paid twice.
        if ($paid_here && (string) $order->get_meta(self::META_STATE) !== $state) {
            $order->update_meta_data(self::META_STATE, $state);
            $order->add_order_note(sprintf(
                /* translators: 1: Orboni session id, 2: the amount received, with its currency */
                __('This order was already paid, and a second payment page (%1$s) was paid as well: %2$s. Send it back to the buyer from your wallet.', 'orboni-for-woocommerce'),
                $id,
                self::money($session, 'amount_received')
            ));
            $order->save();
            Orboni_WC_Log::error(sprintf('Order %d: paid twice, again by session %s.', $order->get_id(), $id));
        }
    }

    private static function hold(WC_Order $order, $note)
    {
        if ($order->has_status('on-hold')) {
            $order->add_order_note($note);
            $order->save();

            return;
        }

        $order->update_status('on-hold', $note);
    }

    /** "Paid with 25.00 USDT on Tron (25.00 USD) through Orboni, session cs_live_…." @param  array<string, mixed>  $session */
    private static function paid_note(array $session)
    {
        $payment = is_array($session['payment'] ?? null) ? $session['payment'] : [];
        $test = empty($session['livemode']) ? ' '.__('(test payment)', 'orboni-for-woocommerce') : '';
        $late = ! empty($session['paid_late']) ? ' '.__('It arrived after the payment page expired.', 'orboni-for-woocommerce') : '';

        if (isset($payment['coin'], $payment['amount'])) {
            return sprintf(
                /* translators: 1: coin amount, 2: coin, 3: network, 4: the amount it counted for, with its currency, 5: session id */
                __('Paid with %1$s %2$s on %3$s (%4$s) through Orboni, session %5$s.', 'orboni-for-woocommerce'),
                (string) $payment['amount'],
                (string) $payment['coin'],
                self::CHAIN_NAMES[(string) ($payment['chain'] ?? '')] ?? ucfirst((string) ($payment['chain'] ?? '')),
                self::money($session, 'amount_received'),
                (string) $session['id']
            ).$test.$late;
        }

        return sprintf(
            /* translators: 1: the amount, with its currency, 2: session id */
            __('Paid %1$s through Orboni, session %2$s.', 'orboni-for-woocommerce'),
            self::money($session, 'amount_received'),
            (string) $session['id']
        ).$test.$late;
    }

    /**
     * One of the session's decimal amounts with its currency: "25.00 EUR", "1500 JPY".
     *
     * @param  array<string, mixed>  $session
     */
    private static function money(array $session, $field)
    {
        $currency = strtoupper((string) ($session['currency'] ?? 'usd'));

        return number_format((float) ($session[$field] ?? 0), Orboni_WC_Gateway::decimals($currency), '.', '').' '.$currency;
    }
}
