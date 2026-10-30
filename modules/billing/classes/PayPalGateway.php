<?php
require_once __DIR__ . '/../classes/PaymentGatewayInterface.php';

class PayPalGateway implements PaymentGatewayInterface
{
    private string $clientId;
    private string $clientSecret;
    private bool   $sandbox;
    private string $apiBase;

    public function __construct(string $clientId, string $clientSecret, bool $sandbox = true)
    {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->sandbox      = $sandbox;
        $this->apiBase      = $sandbox
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    /**
     * Build a PayPalGateway instance from global config variables.
     * Prefers the new gsp_paypal_* helper functions; falls back to legacy globals.
     */
    public static function fromConfig(): self
    {
        if (function_exists('billing_get_paypal_db_config')) {
            $db = billing_get_paypal_db_config();
            if (!empty($db['paypal_client_id']) && !empty($db['paypal_client_secret'])) {
                return new self((string)$db['paypal_client_id'], (string)$db['paypal_client_secret'], ($db['paypal_mode'] ?? 'sandbox') !== 'live');
            }
        }
        if (function_exists('gsp_paypal_get_client_id')) {
            $clientId     = gsp_paypal_get_client_id();
            $clientSecret = gsp_paypal_get_client_secret();
            $sandbox      = gsp_paypal_is_sandbox();
        } else {
            $clientId     = $GLOBALS['paypal_client_id']     ?? '';
            $clientSecret = $GLOBALS['paypal_client_secret'] ?? '';
            $sandbox      = (bool)($GLOBALS['paypal_sandbox'] ?? true);
        }
        return new self($clientId, $clientSecret, $sandbox);
    }

    public function getName(): string { return 'paypal'; }

    /** Exchange client credentials for a Bearer token. Returns token or null. */
    private function getAccessToken(): ?string
    {
        $ch = curl_init("{$this->apiBase}/v1/oauth2/token");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERPWD        => "{$this->clientId}:{$this->clientSecret}",
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$body) return null;
        $data = json_decode($body, true);
        return $data['access_token'] ?? null;
    }

    private function requestId(array $params, string $prefix): string
    {
        $provided = (string)($params['idempotency_key'] ?? $params['paypal_request_id'] ?? '');
        if ($provided !== '') {
            return substr(preg_replace('/[^A-Za-z0-9_.-]/', '-', $provided), 0, 108);
        }
        return substr($prefix . '-' . hash('sha256', json_encode($params)), 0, 108);
    }

    public function createPayment(array $params): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'error' => 'paypal_oauth_failed'];
        }

        $amount      = number_format((float)($params['amount'] ?? 0), 2, '.', '');
        $currency    = $params['currency'] ?? 'USD';
        $invoiceId   = $params['invoice_id'] ?? null;
        $description = $params['description'] ?? 'Game Server Order';
        $returnUrl   = $params['return_url'] ?? '';
        $cancelUrl   = $params['cancel_url'] ?? '';
        $items       = $params['items'] ?? null;

        $purchaseUnit = [
            'amount'      => ['currency_code' => $currency, 'value' => $amount],
            'description' => $description,
            'custom_id'   => (string)($params['custom_id'] ?? $invoiceId ?? ''),
        ];
        if ($invoiceId) {
            $purchaseUnit['invoice_id'] = (string)$invoiceId;
        }
        if ($items) {
            $purchaseUnit['items'] = $items;
            $purchaseUnit['amount']['breakdown'] = [
                'item_total' => ['currency_code' => $currency, 'value' => $amount],
            ];
        }

        $body = [
            'intent'              => 'CAPTURE',
            'purchase_units'      => [$purchaseUnit],
            'application_context' => [
                'return_url'  => $returnUrl,
                'cancel_url'  => $cancelUrl,
                'user_action' => 'PAY_NOW',
            ],
        ];

        $ch = curl_init("{$this->apiBase}/v2/checkout/orders");
        $requestId = $this->requestId($params, 'create');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "PayPal-Request-Id: {$requestId}",
                "Authorization: Bearer {$token}",
            ],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 201 || !$res) {
            return ['success' => false, 'error' => 'paypal_create_order_failed', 'http_code' => $code];
        }
        $data = json_decode($res, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'paypal_invalid_response'];
        }
        return [
            'success'           => true,
            'provider_order_id' => $data['id'] ?? '',
            'raw_response'      => $data,
        ];
    }

    public function handleCallback(array $params): array
    {
        $providerOrderId = $params['order_id'] ?? null;
        if (!$providerOrderId) {
            return ['success' => false, 'error' => 'missing_order_id'];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'error' => 'paypal_oauth_failed'];
        }

        $requestId = $this->requestId($params, 'capture-' . preg_replace('/[^A-Za-z0-9_.-]/', '-', (string)$providerOrderId));
        $ch = curl_init("{$this->apiBase}/v2/checkout/orders/{$providerOrderId}/capture");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "PayPal-Request-Id: {$requestId}",
                "Authorization: Bearer {$token}",
            ],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (($code !== 200 && $code !== 201) || !$res) {
            return ['success' => false, 'error' => 'paypal_capture_failed', 'http_code' => $code];
        }
        $data = json_decode($res, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'paypal_invalid_capture_response'];
        }

        $status = $data['status'] ?? '';
        if ($status !== 'COMPLETED') {
            return ['success' => false, 'error' => 'payment_not_completed', 'status' => $status];
        }

        $capture  = $data['purchase_units'][0]['payments']['captures'][0] ?? [];
        $txid     = $capture['id'] ?? null;
        $amount   = (float)($capture['amount']['value'] ?? 0);
        $currency = $capture['amount']['currency_code'] ?? 'USD';
        $customId = $data['purchase_units'][0]['custom_id'] ?? null;

        return [
            'success'        => true,
            'transaction_id' => $txid,
            'amount'         => $amount,
            'currency'       => $currency,
            'status'         => 'completed',
            'custom_id'      => $customId,
            'raw_response'   => $data,
        ];
    }

    public function refund(array $params): array
    {
        $captureId = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($params['capture_id'] ?? $params['transaction_id'] ?? ''));
        if ($captureId === '') return ['success'=>false, 'error'=>'missing_capture_id'];
        $token=$this->getAccessToken(); if (!$token) return ['success'=>false,'error'=>'paypal_oauth_failed'];
        $body=['amount'=>['value'=>number_format(max(0,(float)($params['amount']??0)),2,'.',''),'currency_code'=>(string)($params['currency']??'USD')]];
        $ch=curl_init("{$this->apiBase}/v2/payments/captures/{$captureId}/refund");
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'PayPal-Request-Id: '.$this->requestId($params,'refund-'.$captureId), "Authorization: Bearer {$token}"]]);
        $res=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); $data=json_decode((string)$res,true);
        if (($code!==200 && $code!==201) || !is_array($data)) return ['success'=>false,'status'=>'failed','error'=>'paypal_refund_failed','http_code'=>$code];
        return ['success'=>true,'status'=>'refunded','transaction_id'=>$data['id']??null,'raw_response'=>$data];
    }

    public function verifyPayment(array $payload): bool
    {
        // For REST API flow (JS SDK capture), verification is done by the capture response itself.
        // Webhook signature verification would be implemented here for webhook events.
        return true;
    }

    public function getTransactionId(array $captureResult): ?string
    {
        return $captureResult['transaction_id'] ?? null;
    }
}
