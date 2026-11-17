<?php

defined('ABSPATH') || exit;

/**
 * `Webhook-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<body>">` — the
 * scheme Stripe uses. After a secret is rolled Orboni signs with both for a
 * while, so any `v1` may match.
 */
class Orboni_WC_Signature
{
    /** Seconds a signature stays good: an old event replayed later is refused. */
    public const TOLERANCE = 300;

    public static function verify($secret, $body, $header, $now = null)
    {
        $secret = (string) $secret;
        if ($secret === '' || ! is_string($header) || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            $kv = explode('=', trim($pair, " \t"), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]][] = $kv[1];
            }
        }

        $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
        $now = $now === null ? time() : (int) $now;
        if ($timestamp <= 0 || abs($now - $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        foreach ($parts['v1'] ?? [] as $candidate) {
            if (hash_equals($expected, (string) $candidate)) {
                return true;
            }
        }

        return false;
    }
}
