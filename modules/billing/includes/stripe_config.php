<?php
/**
 * Stripe settings for the ordering site, set only in Billing > Settings > Stripe (no files to edit).
 *
 * Stored on the global billing_config row: mode, publishable key, the secret key and the webhook
 * signing secret (both encrypted, see billing_paypal_read_key()), Stripe Tax on/off and the card
 * page style (Stripe's hosted page or the embedded form). Saving a secret key registers the webhook
 * with Stripe automatically and stores its signing secret.
 */
require_once __DIR__ . '/paypal_credentials.php';
require_once __DIR__ . '/../classes/StripeClient.php';
require_once __DIR__ . '/billing_autopay.php';

if (!defined('BILLING_STRIPE_WEBHOOK_EVENTS')) {
    define('BILLING_STRIPE_WEBHOOK_EVENTS', 'checkout.session.completed,checkout.session.async_payment_succeeded,payment_intent.succeeded,payment_intent.payment_failed,charge.refunded');
}

if (!function_exists('billing_stripe_config')) {
    /**
     * Returns: mode (test|live), publishable_key, secret_key, webhook_secret, automatic_tax (bool),
     * checkout_ui (hosted|form), configured (bool), problem (text, '' when fine).
     */
    function billing_stripe_config(mysqli $db, string $prefix): array
    {
        $cfg = array('mode' => 'test', 'publishable_key' => '', 'secret_key' => '', 'webhook_secret' => '',
            'automatic_tax' => false, 'checkout_ui' => 'hosted', 'configured' => false, 'problem' => '');
        try {
            $res = $db->query("SELECT * FROM `{$prefix}billing_config` WHERE game_key IS NULL ORDER BY config_id ASC LIMIT 1");
            $row = $res ? $res->fetch_assoc() : null;
        } catch (Throwable $e) {
            $row = null;
        }
        if (!$row || !array_key_exists('stripe_secret_key_enc', $row)) {
            return $cfg;
        }
        $key = billing_paypal_read_key();
        $cfg['mode'] = ((string)($row['stripe_mode'] ?? '') === 'live') ? 'live' : 'test';
        $cfg['publishable_key'] = trim((string)($row['stripe_publishable_key'] ?? ''));
        $cfg['automatic_tax'] = (int)($row['stripe_automatic_tax'] ?? 0) === 1;
        $cfg['checkout_ui'] = ((string)($row['stripe_checkout_ui'] ?? '') === 'form') ? 'form' : 'hosted';
        foreach (array('stripe_secret_key_enc' => 'secret_key', 'stripe_webhook_secret_enc' => 'webhook_secret') as $col => $field) {
            $enc = trim((string)($row[$col] ?? ''));
            if ($enc === '') {
                continue;
            }
            try {
                $cfg[$field] = $key !== '' ? billing_paypal_decrypt_secret($enc, $key) : '';
            } catch (Throwable $e) {
                $cfg[$field] = '';
            }
            if ($cfg[$field] === '') {
                $cfg['problem'] = 'The saved Stripe keys can no longer be read (the database login may have changed). Enter the secret key again.';
            }
        }
        $cfg['configured'] = $cfg['secret_key'] !== '' && $cfg['publishable_key'] !== '';
        if ($cfg['configured']) {
            $keyIsLive = strpos($cfg['secret_key'], 'sk_live_') === 0 || strpos($cfg['secret_key'], 'rk_live_') === 0;
            $pubIsLive = strpos($cfg['publishable_key'], 'pk_live_') === 0;
            if ($keyIsLive !== $pubIsLive) {
                $cfg['problem'] = 'The publishable key and the secret key are from different modes (test and live).';
                $cfg['configured'] = false;
            }
            $cfg['mode'] = $keyIsLive ? 'live' : 'test';
        }
        return $cfg;
    }
}

if (!function_exists('billing_stripe_client')) {
    /** A StripeClient for the saved secret key, or null when Stripe is not set up. */
    function billing_stripe_client(mysqli $db, string $prefix): ?StripeClient
    {
        $cfg = billing_stripe_config($db, $prefix);
        return $cfg['configured'] ? new StripeClient($cfg['secret_key']) : null;
    }
}

if (!function_exists('billing_stripe_site_base')) {
    /** The ordering site's address (Site base URL in Billing > Settings, else the current request). */
    function billing_stripe_site_base(): string
    {
        $base = rtrim(trim((string)($GLOBALS['SITE_BASE_URL'] ?? '')), '/');
        if ($base !== '') {
            return $base;
        }
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return $host !== '' ? ($https ? 'https://' : 'http://') . $host : '';
    }
}

if (!function_exists('billing_stripe_store_secret')) {
    function billing_stripe_store_secret(mysqli $db, string $prefix, int $configId, string $column, string $value): bool
    {
        if (!in_array($column, array('stripe_secret_key_enc', 'stripe_webhook_secret_enc'), true)) {
            return false;
        }
        $key = billing_paypal_read_key();
        if ($key === '') {
            return false;
        }
        $enc = $value === '' ? null : billing_paypal_encrypt_secret($value, $key);
        $stmt = $db->prepare("UPDATE `{$prefix}billing_config` SET {$column} = ? WHERE config_id = ?");
        $stmt->bind_param('si', $enc, $configId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('billing_stripe_register_webhook')) {
    /**
     * Creates (or recreates) this site's webhook at Stripe and stores its signing secret.
     * $url is the full webhook address. Returns array(ok, message).
     */
    function billing_stripe_register_webhook(mysqli $db, string $prefix, string $url): array
    {
        if (!preg_match('#^https://#', $url)) {
            return array(false, 'The webhook needs the site address to start with https:// (set the Site base URL first).');
        }
        $client = billing_stripe_client($db, $prefix);
        if (!$client) {
            return array(false, 'Save the Stripe keys first.');
        }
        $list = $client->request('GET', 'webhook_endpoints', array('limit' => 100));
        if (!$list['ok']) {
            return array(false, 'Stripe would not list webhooks: ' . $list['error']);
        }
        foreach ((array)($list['data']['data'] ?? array()) as $endpoint) {
            if ((string)($endpoint['url'] ?? '') === $url) {
                // Stripe shows a signing secret only when the endpoint is created, so replace it.
                $client->request('DELETE', 'webhook_endpoints/' . rawurlencode((string)$endpoint['id']));
            }
        }
        $res = $client->request('POST', 'webhook_endpoints', array(
            'url' => $url,
            'enabled_events' => explode(',', BILLING_STRIPE_WEBHOOK_EVENTS),
            'api_version' => StripeClient::API_VERSION,
            'description' => 'GameServer Engine billing',
        ));
        if (!$res['ok'] || empty($res['data']['secret'])) {
            return array(false, 'Stripe would not create the webhook: ' . $res['error']);
        }
        $id = billing_config_global_row_id($db, $prefix);
        if (!billing_stripe_store_secret($db, $prefix, $id, 'stripe_webhook_secret_enc', (string)$res['data']['secret'])) {
            return array(false, 'The webhook was created but its signing secret could not be saved.');
        }
        return array(true, 'Webhook registered with Stripe for ' . $url . '.');
    }
}

if (!function_exists('billing_stripe_save_settings')) {
    /**
     * Saves the Stripe settings from the admin form. A blank secret key keeps the saved one. When a new
     * secret key is saved, the webhook is registered automatically. Returns array(ok, message).
     */
    function billing_stripe_save_settings(mysqli $db, string $prefix, array $in, string $webhookUrl = ''): array
    {
        $pub = trim((string)($in['stripe_publishable_key'] ?? ''));
        $secret = trim((string)($in['stripe_secret_key'] ?? ''));
        $tax = !empty($in['stripe_automatic_tax']) ? 1 : 0;
        $ui = (($in['stripe_checkout_ui'] ?? 'hosted') === 'form') ? 'form' : 'hosted';
        if ($pub !== '' && !preg_match('/^pk_(test|live)_[A-Za-z0-9]+$/', $pub)) {
            return array(false, 'The publishable key should start with pk_test_ or pk_live_.');
        }
        if ($secret !== '' && !preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $secret)) {
            return array(false, 'The secret key should start with sk_test_ or sk_live_ (or rk_ for a restricted key).');
        }
        if ($secret !== '') {
            $check = (new StripeClient($secret))->request('GET', 'balance');
            if (!$check['ok']) {
                return array(false, 'Stripe did not accept that secret key: ' . $check['error']);
            }
        }
        $id = billing_config_global_row_id($db, $prefix);
        if ($id <= 0) {
            return array(false, 'Could not create the billing settings row.');
        }
        $mode = (strpos($secret ?: $pub, '_live_') !== false) ? 'live' : 'test';
        $stmt = $db->prepare("UPDATE `{$prefix}billing_config` SET stripe_mode = ?, stripe_publishable_key = ?, stripe_automatic_tax = ?, stripe_checkout_ui = ? WHERE config_id = ?");
        if (!$stmt) {
            return array(false, 'The Stripe columns are missing. Run the billing module update.');
        }
        $stmt->bind_param('ssisi', $mode, $pub, $tax, $ui, $id);
        $stmt->execute();
        $stmt->close();
        $message = 'Stripe settings saved.';
        if ($secret !== '') {
            if (!billing_stripe_store_secret($db, $prefix, $id, 'stripe_secret_key_enc', $secret)) {
                return array(false, 'The secret key could not be saved.');
            }
            if ($webhookUrl !== '') {
                list($ok, $text) = billing_stripe_register_webhook($db, $prefix, $webhookUrl);
                $message .= ' ' . $text;
                if (!$ok) {
                    return array(false, $message);
                }
            }
        }
        return array(true, $message);
    }
}

if (!function_exists('billing_stripe_check_account')) {
    /** Asks Stripe which account the saved key belongs to. Returns array(ok, text). */
    function billing_stripe_check_account(mysqli $db, string $prefix): array
    {
        $client = billing_stripe_client($db, $prefix);
        if (!$client) {
            return array(false, 'Stripe is not set up yet.');
        }
        $res = $client->request('GET', 'account');
        if (!$res['ok']) {
            return array(false, 'Stripe rejected the key: ' . $res['error']);
        }
        $d = $res['data'];
        $name = (string)($d['settings']['dashboard']['display_name'] ?? ($d['business_profile']['name'] ?? $d['id']));
        return array(true, 'Connected to Stripe account "' . $name . '" (' . $d['id'] . ').');
    }
}
