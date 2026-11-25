<?php
/**
 * Stripe settings for the ordering site.
 *
 * The keys are set in Billing > Settings and stored in billing_config: the publishable key as
 * plain text, the secret key and the webhook signing secret encrypted with the same key file as
 * the PayPal secret. When those are empty, an env file is used: BILLING_STRIPE_ENV_FILE, the site
 * config 'stripe_env_file', or /etc/gse-billing/stripe.env, holding STRIPE_PUBLISHABLE_KEY,
 * STRIPE_SECRET_KEY and STRIPE_WEBHOOK_SECRET.
 */
require_once __DIR__ . '/paypal_credentials.php';

if (!defined('BILLING_STRIPE_DEFAULT_ENV_FILE')) {
    define('BILLING_STRIPE_DEFAULT_ENV_FILE', '/etc/gse-billing/stripe.env');
}

if (!function_exists('billing_stripe_parse_env_file')) {
    function billing_stripe_parse_env_file(string $path): array
    {
        $out = array();
        if ($path === '' || !is_readable($path)) {
            return $out;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            list($key, $value) = explode('=', $line, 2);
            $out[trim($key)] = trim(trim($value), "\"'");
        }
        return $out;
    }
}

if (!function_exists('billing_stripe_config')) {
    /**
     * Returns: mode (test|live), publishable_key, secret_key, webhook_secret, automatic_tax (bool),
     * configured (bool: both keys present), source (settings|env file|none), problem (text, '' when fine).
     */
    function billing_stripe_config(mysqli $db, string $prefix): array
    {
        global $billing_runtime_site_config;
        $cfg = array('mode' => 'test', 'publishable_key' => '', 'secret_key' => '', 'webhook_secret' => '',
            'automatic_tax' => false, 'configured' => false, 'source' => 'none', 'problem' => '');
        try {
            $res = $db->query(
                "SELECT stripe_mode, stripe_publishable_key, stripe_secret_key_enc, stripe_webhook_secret_enc, stripe_automatic_tax
                 FROM `{$prefix}billing_config` WHERE game_key IS NULL ORDER BY config_id ASC LIMIT 1"
            );
            $row = $res ? $res->fetch_assoc() : null;
            if ($row) {
                $key = billing_paypal_read_key();
                $cfg['mode'] = ((string)($row['stripe_mode'] ?? '') === 'live') ? 'live' : 'test';
                $cfg['publishable_key'] = trim((string)($row['stripe_publishable_key'] ?? ''));
                $cfg['automatic_tax'] = (int)($row['stripe_automatic_tax'] ?? 0) === 1;
                foreach (array('stripe_secret_key_enc' => 'secret_key', 'stripe_webhook_secret_enc' => 'webhook_secret') as $col => $field) {
                    $enc = trim((string)($row[$col] ?? ''));
                    if ($enc !== '' && $key !== '') {
                        try {
                            $cfg[$field] = billing_paypal_decrypt_secret($enc, $key);
                        } catch (Throwable $e) {
                            $cfg['problem'] = 'The saved Stripe secret could not be decrypted. Check the billing key file.';
                        }
                    } elseif ($enc !== '' && $key === '') {
                        $cfg['problem'] = 'The billing key file is missing, so the saved Stripe secret cannot be read.';
                    }
                }
                if ($cfg['secret_key'] !== '') {
                    $cfg['source'] = 'settings';
                }
            }
        } catch (Throwable $e) {
            // columns not there yet (migration 16 not applied): fall back to the env file
        }

        if ($cfg['secret_key'] === '') {
            $path = (string)(getenv('BILLING_STRIPE_ENV_FILE') ?: ($billing_runtime_site_config['stripe_env_file'] ?? ''));
            if ($path === '') {
                $path = BILLING_STRIPE_DEFAULT_ENV_FILE;
            }
            $env = billing_stripe_parse_env_file($path);
            if (!empty($env['STRIPE_SECRET_KEY'])) {
                $cfg['secret_key'] = $env['STRIPE_SECRET_KEY'];
                $cfg['publishable_key'] = $cfg['publishable_key'] !== '' ? $cfg['publishable_key'] : (string)($env['STRIPE_PUBLISHABLE_KEY'] ?? '');
                $cfg['webhook_secret'] = $cfg['webhook_secret'] !== '' ? $cfg['webhook_secret'] : (string)($env['STRIPE_WEBHOOK_SECRET'] ?? '');
                $cfg['mode'] = (strpos($cfg['secret_key'], 'sk_live_') === 0) ? 'live' : 'test';
                $cfg['source'] = 'env file';
            }
        }

        $cfg['configured'] = $cfg['secret_key'] !== '' && $cfg['publishable_key'] !== '';
        if ($cfg['configured']) {
            $keyIsLive = strpos($cfg['secret_key'], 'sk_live_') === 0 || strpos($cfg['secret_key'], 'rk_live_') === 0;
            $pubIsLive = strpos($cfg['publishable_key'], 'pk_live_') === 0;
            if ($keyIsLive !== $pubIsLive) {
                $cfg['problem'] = 'The Stripe publishable key and secret key are from different modes (test and live).';
                $cfg['configured'] = false;
            } elseif ($keyIsLive !== ($cfg['mode'] === 'live')) {
                $cfg['problem'] = 'The Stripe mode setting does not match the keys. Switch the mode or change the keys.';
                $cfg['configured'] = false;
            }
        }
        return $cfg;
    }
}

if (!function_exists('billing_stripe_client')) {
    /** A StripeClient for the configured secret key, or null when Stripe is not set up. */
    function billing_stripe_client(mysqli $db, string $prefix): ?StripeClient
    {
        require_once __DIR__ . '/../classes/StripeClient.php';
        $cfg = billing_stripe_config($db, $prefix);
        return $cfg['configured'] ? new StripeClient($cfg['secret_key']) : null;
    }
}

if (!function_exists('billing_stripe_site_base')) {
    /** The ordering site's address for Stripe's return links, from SITE_BASE_URL or the current request. */
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

if (!function_exists('billing_stripe_save_settings')) {
    /**
     * Saves the Stripe settings from the admin form. Blank secret fields keep the saved value.
     * Secrets are stored encrypted, so they need the billing key file; without it the keys go in
     * the env file instead. Returns array(ok, message).
     */
    function billing_stripe_save_settings(mysqli $db, string $prefix, array $in): array
    {
        $mode = (($in['stripe_mode'] ?? 'test') === 'live') ? 'live' : 'test';
        $pub = trim((string)($in['stripe_publishable_key'] ?? ''));
        $secret = trim((string)($in['stripe_secret_key'] ?? ''));
        $whsec = trim((string)($in['stripe_webhook_secret'] ?? ''));
        $tax = !empty($in['stripe_automatic_tax']) ? 1 : 0;
        if ($pub !== '' && !preg_match('/^pk_(test|live)_[A-Za-z0-9]+$/', $pub)) {
            return array(false, 'The publishable key should start with pk_test_ or pk_live_.');
        }
        if ($secret !== '' && !preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $secret)) {
            return array(false, 'The secret key should start with sk_test_ or sk_live_ (or rk_ for a restricted key).');
        }
        if ($whsec !== '' && !preg_match('/^whsec_[A-Za-z0-9]+$/', $whsec)) {
            return array(false, 'The webhook signing secret should start with whsec_.');
        }
        $key = billing_paypal_read_key();
        if (($secret !== '' || $whsec !== '') && $key === '') {
            return array(false, 'Secrets cannot be saved here because the billing key file is not set up. Put the keys in ' . BILLING_STRIPE_DEFAULT_ENV_FILE . ' instead.');
        }
        $res = $db->query("SELECT config_id FROM `{$prefix}billing_config` WHERE game_key IS NULL ORDER BY config_id ASC LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) {
            $id = (int)$row['config_id'];
        } else {
            // The global row also holds the lifecycle timings: write today's effective values explicitly.
            if (!$db->query("INSERT INTO `{$prefix}billing_config` (game_key, enabled, grace_days, delete_after_expired_days) VALUES (NULL, 1, 0, 7)")) {
                return array(false, 'Could not create the billing settings row: ' . $db->error);
            }
            $id = (int)$db->insert_id;
        }
        $stmt = $db->prepare("UPDATE `{$prefix}billing_config` SET stripe_mode = ?, stripe_publishable_key = ?, stripe_automatic_tax = ? WHERE config_id = ?");
        if (!$stmt) {
            return array(false, 'The Stripe columns are missing. Run the billing module update (database version 16).');
        }
        $stmt->bind_param('ssii', $mode, $pub, $tax, $id);
        $stmt->execute();
        $stmt->close();
        foreach (array('stripe_secret_key_enc' => $secret, 'stripe_webhook_secret_enc' => $whsec) as $col => $value) {
            if ($value === '') {
                continue;
            }
            $enc = billing_paypal_encrypt_secret($value, $key);
            $stmt = $db->prepare("UPDATE `{$prefix}billing_config` SET {$col} = ? WHERE config_id = ?");
            $stmt->bind_param('si', $enc, $id);
            $stmt->execute();
            $stmt->close();
        }
        return array(true, 'Stripe settings saved.');
    }
}

if (!function_exists('billing_stripe_check_account')) {
    /** Asks Stripe which account the configured key belongs to. Returns array(ok, text). */
    function billing_stripe_check_account(mysqli $db, string $prefix): array
    {
        $client = billing_stripe_client($db, $prefix);
        if (!$client) {
            return array(false, 'Stripe is not configured.');
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
