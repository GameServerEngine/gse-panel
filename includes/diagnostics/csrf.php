<?php
/*
 * CSRF token for diagnostic requests, same idea as billing_action_csrf_token():
 * one random token per session, compared in constant time. The page sends it in a header
 * (X-CSRF-Token) or a form field named csrf.
 */

function diag_csrf_token()
{
	if (empty($_SESSION['diag_csrf'])) {
		$_SESSION['diag_csrf'] = bin2hex(random_bytes(16));
	}
	return (string)$_SESSION['diag_csrf'];
}

function diag_csrf_valid($sent)
{
	if (empty($_SESSION['diag_csrf']) || !is_string($sent) || $sent === '') {
		return false;
	}
	return hash_equals((string)$_SESSION['diag_csrf'], $sent);
}

// The token from the request: header first, then POST field.
function diag_csrf_from_request()
{
	if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
		return (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
	}
	return isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
}
