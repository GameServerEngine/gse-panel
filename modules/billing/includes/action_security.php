<?php

if (!function_exists('billing_action_csrf_token')) {
    function billing_action_csrf_token(string $key = 'billing_action_csrf'): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('gameserverpanel_web');
            session_start();
        }
        if (empty($_SESSION[$key])) {
            try {
                $_SESSION[$key] = function_exists('random_bytes')
                    ? bin2hex(random_bytes(16))
                    : bin2hex(openssl_random_pseudo_bytes(16));
            } catch (Throwable $e) {
                $_SESSION[$key] = sha1(uniqid((string)mt_rand(), true));
            }
        }
        return (string)$_SESSION[$key];
    }
}

if (!function_exists('billing_action_validate_csrf')) {
    function billing_action_validate_csrf($token, string $key = 'billing_action_csrf'): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('gameserverpanel_web');
            session_start();
        }
        if (empty($_SESSION[$key])) {
            return false;
        }
        return hash_equals((string)$_SESSION[$key], (string)$token);
    }
}

if (!function_exists('billing_action_safe_redirect')) {
    function billing_action_safe_redirect(string $fallback, ?string $target = null): string
    {
        $target = trim((string)$target);
        if ($target === '') {
            return $fallback;
        }
        if (preg_match('#^https?://#i', $target) || str_starts_with($target, '//')) {
            return $fallback;
        }
        if (preg_match('/[\r\n]/', $target)) {
            return $fallback;
        }
        return $target;
    }
}

