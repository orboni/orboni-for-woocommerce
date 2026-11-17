<?php

defined('ABSPATH') || exit;

/**
 * Orboni's API, as much of it as a store needs: make a checkout session,
 * read one back. `Authorization: Bearer sk_…`, JSON both ways.
 */
class Orboni_WC_Api
{
    public const SESSION_ID = '/^cs_(live|test)_[A-Za-z0-9]+$/';

    /** Seconds Orboni's own "updating, retry" answer may make a buyer wait, once. */
    private const MAX_RETRY_AFTER = 5;

    /** @var string */
    private $secret;

    /** @var string */
    private $base;

    public function __construct($secret)
    {
        $this->secret = (string) $secret;
        // A filter, not a setting: only for trying the plugin against another Orboni.
        $this->base = untrailingslashit((string) apply_filters('orboni_wc_api_url', 'https://orboni.com'));
    }

    /**
     * A new checkout session. The key makes a retry return the session the
     * first try made instead of a second one.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function create_session(array $body, $idempotency_key)
    {
        return $this->request('POST', '/v1/checkout/sessions', $body, ['Idempotency-Key' => (string) $idempotency_key]);
    }

    /** @return array<string, mixed>|WP_Error */
    public function get_session($id)
    {
        if (! is_string($id) || preg_match(self::SESSION_ID, $id) !== 1) {
            return new WP_Error('orboni_bad_session_id', 'Not an Orboni checkout session id.');
        }

        return $this->request('GET', '/v1/checkout/sessions/'.rawurlencode($id));
    }

    /** @return array<string, mixed>|WP_Error */
    private function request($method, $path, $body = null, array $headers = [])
    {
        $args = [
            'method' => $method,
            'timeout' => 20,
            'redirection' => 0,
            'headers' => array_merge([
                'Authorization' => 'Bearer '.$this->secret,
                'Accept' => 'application/json',
                'User-Agent' => 'orboni-for-woocommerce/'.ORBONI_WC_VERSION.'; WordPress/'.get_bloginfo('version'),
            ], $headers),
        ];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        // A GET, or a POST under an idempotency key, can be sent twice safely.
        $retryable = $method === 'GET' || isset($headers['Idempotency-Key']);
        $response = null;
        for ($try = 0; $try < 2; $try++) {
            $response = wp_remote_request($this->base.$path, $args);
            if (is_wp_error($response)) {
                if ($retryable && $try === 0) {
                    continue;
                }

                return $response;
            }
            // Orboni's own deploy: a few seconds' "503, retry after".
            if ((int) wp_remote_retrieve_response_code($response) === 503 && $retryable && $try === 0) {
                $wait = (int) wp_remote_retrieve_header($response, 'retry-after');
                sleep(min(max($wait, 1), self::MAX_RETRY_AFTER));

                continue;
            }
            break;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code >= 200 && $code < 300 && is_array($data)) {
            return $data;
        }

        $type = is_array($data) && isset($data['error']['type']) ? (string) $data['error']['type'] : 'http_'.$code;
        $message = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : 'Orboni answered HTTP '.$code.'.';

        return new WP_Error('orboni_'.$type, $message, ['status' => $code, 'type' => $type]);
    }
}
