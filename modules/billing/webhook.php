<?php
/**
 * Legacy PayPal webhook entrypoint.
 *
 * Keep this file for deployments configured to call /webhook.php, but route all
 * runtime behavior through the canonical idempotent PayPal handler.
 */
require __DIR__ . '/paypal/webhook.php';
