<?php
/**
 * Small Stripe API client without the SDK: form-encoded requests, a pinned API version,
 * idempotency keys, and webhook signature checks.
 */
class StripeClient
{
    const API_BASE = 'https://api.stripe.com/v1/';
    const API_VERSION = '2024-06-20';
    /** Needed only to create embedded-form Checkout Sessions (ui_mode form). Webhooks stay on API_VERSION. */
    const FORM_API_VERSION = '2026-03-25.dahlia';

    private $secretKey;
    private $timeout;

    public function __construct(string $secretKey, int $timeout = 30)
    {
        $this->secretKey = $secretKey;
        $this->timeout = $timeout;
    }

    /**
     * One API call. Returns array(ok, status, error, code, data).
     * $idempotencyKey makes a POST safe to repeat (Stripe returns the first result again).
     * $apiVersion overrides the pinned version for this one call.
     */
    public function request(string $method, string $path, array $params = array(), ?string $idempotencyKey = null, ?string $apiVersion = null): array
    {
        $method = strtoupper($method);
        $url = self::API_BASE . ltrim($path, '/');
        $body = self::encode($params);
        if ($method === 'GET' && $body !== '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . $body;
        }
        $headers = array('Stripe-Version: ' . ($apiVersion !== null && $apiVersion !== '' ? $apiVersion : self::API_VERSION));
        if ($idempotencyKey !== null && $idempotencyKey !== '' && $method === 'POST') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ));
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $netError = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return array('ok' => false, 'status' => 0, 'error' => 'Could not reach Stripe: ' . $netError, 'code' => 'network', 'data' => array());
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return array('ok' => false, 'status' => $status, 'error' => 'Stripe sent an unreadable reply.', 'code' => 'bad_response', 'data' => array());
        }
        if ($status >= 400) {
            return array(
                'ok' => false,
                'status' => $status,
                'error' => (string)($data['error']['message'] ?? 'Stripe returned an error.'),
                'code' => (string)($data['error']['code'] ?? ($data['error']['type'] ?? 'stripe_error')),
                'data' => $data,
            );
        }
        return array('ok' => true, 'status' => $status, 'error' => '', 'code' => '', 'data' => $data);
    }

    /** Form-encodes nested parameters the way Stripe expects (a[b][0][c]=...); booleans become true/false. */
    public static function encode(array $params): string
    {
        return http_build_query(self::normalise($params), '', '&');
    }

    private static function normalise(array $params): array
    {
        $out = array();
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $out[$key] = self::normalise($value);
            } else {
                $out[$key] = (string)$value;
            }
        }
        return $out;
    }

    /**
     * Checks a webhook's Stripe-Signature header (t=...,v1=...) against the endpoint's signing secret.
     * Rejects signatures older than $tolerance seconds to stop replays.
     */
    public static function verifySignature(string $payload, string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        if ($payload === '' || $header === '' || $secret === '') {
            return false;
        }
        $timestamp = 0;
        $signatures = array();
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't') {
                $timestamp = (int)$pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }
        if ($timestamp <= 0 || !$signatures) {
            return false;
        }
        $now = $now === null ? time() : $now;
        if ($tolerance > 0 && abs($now - $timestamp) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }
        return false;
    }
}
