<?php
require_once __DIR__ . '/PaymentGatewayInterface.php';
require_once __DIR__ . '/StripeClient.php';

/**
 * Stripe gateway. Payments start in Stripe Checkout (api/stripe_checkout.php) and are confirmed by
 * the webhook (stripe/webhook.php), so this class only handles refunds.
 */
class StripeGateway implements PaymentGatewayInterface
{
    private $client;

    public function __construct(?StripeClient $client = null)
    {
        $this->client = $client;
    }

    /** A gateway using the Stripe keys from the billing settings (or none when Stripe is not set up). */
    public static function fromConfig(): self
    {
        global $db_host, $db_user, $db_pass, $db_name, $db_port, $table_prefix;
        require_once __DIR__ . '/../includes/stripe_config.php';
        $client = null;
        try {
            $db = @mysqli_connect($db_host ?? 'localhost', $db_user ?? '', $db_pass ?? '', $db_name ?? '', intval($db_port ?? 3306) ?: 3306);
            if ($db) {
                $client = billing_stripe_client($db, (string)($table_prefix ?? ''));
                mysqli_close($db);
            }
        } catch (Throwable $e) {
            $client = null;
        }
        return new self($client);
    }

    public function getName(): string { return 'stripe'; }

    public function createPayment(array $params): array
    {
        return ['success' => false, 'error' => 'use_stripe_checkout'];
    }

    public function handleCallback(array $params): array
    {
        return ['success' => false, 'error' => 'use_stripe_webhook'];
    }

    /**
     * Refunds a Stripe payment. capture_id is the transaction id stored on the invoice: a Stripe
     * invoice (in_...), payment intent (pi_...) or charge (ch_...). amount in currency units; 0 or
     * missing refunds the whole payment.
     */
    public function refund(array $params): array
    {
        if (!$this->client) {
            return ['success' => false, 'status' => 'failed', 'error' => 'stripe_not_configured'];
        }
        $txid = preg_replace('/[^A-Za-z0-9_]/', '', (string)($params['capture_id'] ?? $params['transaction_id'] ?? ''));
        if ($txid === '') {
            return ['success' => false, 'status' => 'failed', 'error' => 'missing_transaction_id'];
        }
        $target = [];
        if (strpos($txid, 'in_') === 0) {
            $inv = $this->client->request('GET', 'invoices/' . $txid);
            if (!$inv['ok'] || empty($inv['data']['payment_intent'])) {
                return ['success' => false, 'status' => 'failed', 'error' => 'stripe_invoice_without_payment'];
            }
            $target['payment_intent'] = (string)$inv['data']['payment_intent'];
        } elseif (strpos($txid, 'pi_') === 0) {
            $target['payment_intent'] = $txid;
        } elseif (strpos($txid, 'ch_') === 0 || strpos($txid, 'py_') === 0) {
            $target['charge'] = $txid;
        } else {
            return ['success' => false, 'status' => 'failed', 'error' => 'not_a_stripe_transaction'];
        }
        $cents = (int)round(max(0, (float)($params['amount'] ?? 0)) * 100);
        if ($cents > 0) {
            $target['amount'] = $cents;
        }
        $target['metadata'] = ['source' => 'gse-billing', 'reason' => (string)($params['reason'] ?? 'refund')];
        $key = (string)($params['idempotency_key'] ?? '');
        $res = $this->client->request('POST', 'refunds', $target, $key !== '' ? 'gse-refund-' . $key : null);
        if (!$res['ok']) {
            return ['success' => false, 'status' => 'failed', 'error' => $res['error']];
        }
        $status = (string)($res['data']['status'] ?? '');
        return [
            'success' => in_array($status, ['succeeded', 'pending'], true),
            'status' => $status === 'succeeded' ? 'refunded' : ($status === 'pending' ? 'pending' : 'failed'),
            'transaction_id' => (string)($res['data']['id'] ?? ''),
            'raw_response' => $res['data'],
        ];
    }

    public function verifyPayment(array $payload): bool
    {
        return false; // webhooks are checked with StripeClient::verifySignature()
    }

    public function getTransactionId(array $captureResult): ?string
    {
        return $captureResult['transaction_id'] ?? null;
    }
}
