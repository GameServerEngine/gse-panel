<?php

if (!function_exists('billing_paypal_key_path')) {
    function billing_paypal_key_path(): string
    {
        global $billing_runtime_site_config;
        $path = getenv('BILLING_PAYPAL_KEY_FILE');
        if ($path === false || $path === '') {
            $path = $billing_runtime_site_config['paypal_key_file'] ?? '';
        }
        return (string)$path;
    }
}

if (!function_exists('billing_paypal_encrypt_secret')) {
    function billing_paypal_encrypt_secret(string $secret, string $key): string
    {
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($secret, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt PayPal client secret.');
        }
        return base64_encode($iv . $ciphertext);
    }
}

if (!function_exists('billing_paypal_decrypt_secret')) {
    function billing_paypal_decrypt_secret(string $encoded, string $key): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= 16) return '';
        $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $plain === false ? '' : $plain;
    }
}

function billing_paypal_read_key(): string
{
    $path = billing_paypal_key_path();
    return ($path !== '' && is_readable($path)) ? trim((string)file_get_contents($path)) : '';
}

function billing_paypal_config(mysqli $db, string $prefix): array
{
    $table = $prefix . 'billing_config';
    $result = @mysqli_query($db, "SELECT config_id, paypal_mode, paypal_client_id, paypal_client_secret_enc, paypal_webhook_id FROM `{$table}` ORDER BY config_id ASC LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    if (!is_array($row)) return [];
    $key = billing_paypal_read_key();
    $row['paypal_client_secret'] = ($key !== '') ? billing_paypal_decrypt_secret((string)($row['paypal_client_secret_enc'] ?? ''), $key) : '';
    return $row;
}

function billing_paypal_secret_input_placeholder(bool $hasSecret): string
{
    return $hasSecret ? 'A secret is saved' : 'Not set';
}

function billing_get_paypal_db_config(): array
{
    global $db_host, $db_user, $db_pass, $db_name, $db_port, $table_prefix;
    static $config;
    if ($config !== null) return $config;
    try {
        $db = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : 0);
    } catch (Throwable $e) {
        return $config = [];
    }
    if (!$db) return $config = [];
    $config = billing_paypal_config($db, (string)$table_prefix);
    mysqli_close($db);
    return $config;
}

function billing_paypal_test_connection(string $mode, string $clientId, string $secret): int
{
    $base = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    $ch = curl_init($base . '/v1/oauth2/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'grant_type=client_credentials', CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERPWD => $clientId . ':' . $secret, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status;
}
