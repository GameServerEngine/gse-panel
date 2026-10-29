<?php
/**
 * Central billing lifecycle transitions.
 *
 * The repository passed to this class must provide the BillingRepository methods
 * used below. Tests may pass an in-memory fake with the same method names.
 */
class BillingLifecycleService
{
    private $repo;

    public function __construct($repo)
    {
        $this->repo = $repo;
    }

    public static function durationMeta(array $row): array
    {
        $duration = strtolower((string)($row['invoice_duration'] ?? $row['rate_type'] ?? 'month'));
        $qty = max(1, (int)($row['qty'] ?? 1));

        if ($duration === 'daily') {
            $duration = 'day';
        } elseif ($duration === 'monthly') {
            $duration = 'month';
        } elseif ($duration === 'yearly') {
            $duration = 'year';
        }

        if (strpos($duration, 'day') !== false) {
            return ['invoice_duration' => 'day', 'rate_type' => 'daily', 'days' => $qty];
        }
        if (strpos($duration, 'year') !== false) {
            return ['invoice_duration' => 'year', 'rate_type' => 'yearly', 'days' => 365 * $qty];
        }
        return ['invoice_duration' => 'month', 'rate_type' => 'monthly', 'days' => 31 * $qty];
    }

    public static function addPeriod(string $baseline, array $row): string
    {
        $baseTs = strtotime($baseline);
        if ($baseTs === false || $baseTs <= 0) {
            $baseTs = time();
        }
        $meta = self::durationMeta($row);
        return date('Y-m-d H:i:s', $baseTs + ($meta['days'] * 86400));
    }

    public static function normalizeDateTime($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    public static function resolvePaidThroughDate(array $invoice, ?array $order = null, ?array $home = null, ?string $paidAt = null): string
    {
        if (!empty($invoice['period_end']) && strtotime((string)$invoice['period_end']) !== false) {
            return date('Y-m-d H:i:s', strtotime((string)$invoice['period_end']));
        }

        $baseline = $paidAt ?: date('Y-m-d H:i:s');
        foreach ([
            $home['next_invoice_date'] ?? null,
            $home['billing_expires_at'] ?? null,
            $order['end_date'] ?? null,
            $invoice['period_start'] ?? null,
        ] as $candidate) {
            if (!empty($candidate) && strtotime((string)$candidate) !== false && strtotime((string)$candidate) > time()) {
                $baseline = (string)$candidate;
                break;
            }
        }

        return self::addPeriod($baseline, $invoice);
    }

    public function processPaidInvoice(int $invoiceId, array $payment, array $context = []): array
    {
        $this->begin();
        try {
            $invoice = $this->repo->getInvoiceForUpdate($invoiceId);
            if (!$invoice) {
                $this->rollback();
                return ['success' => false, 'error' => 'invoice_not_found'];
            }

            $paymentStatus = strtolower((string)($invoice['payment_status'] ?? 'unpaid'));
            if (in_array($paymentStatus, ['cancelled', 'refunded'], true)) {
                $this->rollback();
                return ['success' => false, 'error' => 'invoice_terminal'];
            }

            $alreadyPaid = ($paymentStatus === 'paid');
            $paidAt = (string)($payment['paid_at'] ?? date('Y-m-d H:i:s'));
            $txid = (string)($payment['transaction_external_id'] ?? $payment['txid'] ?? '');
            $method = (string)($payment['payment_method'] ?? 'paypal');
            $currency = (string)($payment['currency'] ?? $invoice['currency'] ?? 'USD');
            $amount = array_key_exists('amount', $payment)
                ? (float)$payment['amount']
                : (float)($invoice['total_due'] ?? $invoice['amount'] ?? 0);
            if ($alreadyPaid) {
                $amount = (float)($invoice['total_due'] ?? $invoice['amount'] ?? $amount);
            }
            $meta = self::durationMeta(array_merge($invoice, $context));

            $orderId = (int)($invoice['order_id'] ?? 0);
            $order = $orderId > 0 ? $this->repo->getOrder($orderId) : null;
            $homeId = (int)($invoice['home_id'] ?? 0);
            if ($order && (int)($order['home_id'] ?? 0) > 0) {
                $homeId = (int)$order['home_id'];
            }
            $home = $homeId > 0 ? $this->repo->getServerHomeBilling($homeId) : null;
            $paidThrough = self::resolvePaidThroughDate(array_merge($invoice, $context), $order, $home, $paidAt);

            $invoiceUpdate = [
                'payment_status' => 'paid',
                'status' => 'paid',
                'billing_status' => 'Active',
                'payment_txid' => $alreadyPaid && !empty($invoice['payment_txid']) ? $invoice['payment_txid'] : $txid,
                'payment_method' => $alreadyPaid && !empty($invoice['payment_method']) ? $invoice['payment_method'] : $method,
                'paid_date' => $alreadyPaid && !empty($invoice['paid_date']) ? $invoice['paid_date'] : $paidAt,
                'invoice_duration' => $meta['invoice_duration'],
                'rate_type' => $meta['rate_type'],
            ];
            foreach (['coupon_id', 'discount_amount', 'subtotal', 'amount', 'total_due'] as $field) {
                if (!$alreadyPaid && array_key_exists($field, $context)) {
                    $invoiceUpdate[$field] = $context[$field];
                }
            }
            if (empty($invoice['period_start']) && !empty($context['period_start'])) {
                $invoiceUpdate['period_start'] = $context['period_start'];
            }
            if (empty($invoice['period_end'])) {
                $invoiceUpdate['period_end'] = $paidThrough;
            }

            if (!$this->repo->updateInvoiceFields($invoiceId, $invoiceUpdate)) {
                throw new RuntimeException('invoice_update_failed');
            }

            $createdOrder = false;
            if ($orderId > 0 && $order) {
                $orderUpdate = [
                    'status' => 'Active',
                    'end_date' => $paidThrough,
                    'payment_txid' => $alreadyPaid && !empty($order['payment_txid']) ? $order['payment_txid'] : $txid,
                    'paid_ts' => $alreadyPaid && !empty($order['paid_ts']) ? $order['paid_ts'] : $paidAt,
                ];
                if (!$alreadyPaid) {
                    $orderUpdate['price'] = $amount;
                    $orderUpdate['discount_amount'] = (float)($context['discount_amount'] ?? $invoice['discount_amount'] ?? 0);
                    $orderUpdate['coupon_id'] = (int)($context['coupon_id'] ?? $invoice['coupon_id'] ?? 0);
                }
                if (!$this->repo->updateOrderFields($orderId, $orderUpdate)) {
                    throw new RuntimeException('order_update_failed');
                }
            } else {
                $orderId = $this->repo->createOrder([
                    'user_id' => (int)$invoice['user_id'],
                    'service_id' => (int)($invoice['service_id'] ?? 0),
                    'home_name' => $invoice['home_name'] ?? '',
                    'ip' => (string)($invoice['ip'] ?? '0'),
                    'qty' => (int)($invoice['qty'] ?? 1),
                    'invoice_duration' => $meta['invoice_duration'],
                    'max_players' => (int)($invoice['max_players'] ?? 0),
                    'price' => $amount,
                    'discount_amount' => (float)($context['discount_amount'] ?? $invoice['discount_amount'] ?? 0),
                    'remote_control_password' => $invoice['remote_control_password'] ?? '',
                    'ftp_password' => $invoice['ftp_password'] ?? '',
                    'status' => 'Active',
                    'end_date' => $paidThrough,
                    'payment_txid' => $txid,
                    'paid_ts' => $paidAt,
                    'coupon_id' => (int)($context['coupon_id'] ?? $invoice['coupon_id'] ?? 0),
                    'paypal_data' => $payment['raw_response'] ?? null,
                ]);
                if ($orderId <= 0) {
                    throw new RuntimeException('order_create_failed');
                }
                $createdOrder = true;
                if (!$this->repo->updateInvoiceOrderId($invoiceId, $orderId)) {
                    throw new RuntimeException('invoice_order_link_failed');
                }
                // Best-effort: record the catalog pricing snapshot that produced this
                // order's locked price. Missing on older invoices/schemas; never fatal.
                $this->repo->lockCatalogOrderPrice(
                    $orderId,
                    $invoice,
                    (float)($invoice['rate_per_player'] ?? 0),
                    $amount,
                    (int)($invoice['qty'] ?? 1),
                    $currency
                );
            }

            if ($homeId > 0) {
                if (!$this->repo->updateInvoiceFields($invoiceId, ['home_id' => $homeId])) {
                    throw new RuntimeException('invoice_home_link_failed');
                }
                if (!$this->repo->updateServerHomeBilling($homeId, [
                    'billing_status' => 'Active',
                    'billing_enabled' => 1,
                    'next_invoice_date' => $paidThrough,
                    'server_expiration_date' => $paidThrough,
                    'billing_expires_at' => $paidThrough,
                    'last_invoice_id' => $invoiceId,
                    'billing_invoice_sent_at' => null,
                ])) {
                    throw new RuntimeException('home_billing_update_failed');
                }
            }

            $transactionId = $this->repo->logTransaction([
                'invoice_id' => $invoiceId,
                'user_id' => (int)$invoice['user_id'],
                'home_id' => $homeId,
                'payment_method' => $method,
                'transaction_external_id' => $txid,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'completed',
                'raw_response' => $payment['raw_response'] ?? [],
            ]);

            $this->commit();
            return [
                'success' => true,
                'invoice_id' => $invoiceId,
                'order_id' => $orderId,
                'home_id' => $homeId,
                'paid_through' => $paidThrough,
                'transaction_id' => $transactionId,
                'created_order' => $createdOrder,
                'became_paid' => !$alreadyPaid,
                'idempotent' => $alreadyPaid,
            ];
        } catch (Throwable $e) {
            $this->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function recordGatewayAttempt(int $invoiceId, array $payment, string $status): array
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['pending', 'failed'], true)) {
            return ['success' => false, 'error' => 'invalid_gateway_attempt_status'];
        }

        $this->begin();
        try {
            $invoice = $this->repo->getInvoiceForUpdate($invoiceId);
            if (!$invoice) {
                $this->rollback();
                return ['success' => false, 'error' => 'invoice_not_found'];
            }

            $paymentStatus = strtolower((string)($invoice['payment_status'] ?? 'unpaid'));
            if (in_array($paymentStatus, ['cancelled', 'refunded'], true)) {
                $this->rollback();
                return ['success' => false, 'error' => 'invoice_terminal'];
            }
            if ($paymentStatus === 'paid') {
                $this->commit();
                return [
                    'success' => true,
                    'invoice_id' => $invoiceId,
                    'payment_status' => 'paid',
                    'gateway_status' => $status,
                    'idempotent' => true,
                ];
            }

            $transactionId = $this->repo->logTransaction([
                'invoice_id' => $invoiceId,
                'user_id' => (int)($invoice['user_id'] ?? 0),
                'home_id' => (int)($invoice['home_id'] ?? 0),
                'payment_method' => (string)($payment['payment_method'] ?? 'paypal'),
                'transaction_external_id' => (string)($payment['transaction_external_id'] ?? $payment['txid'] ?? ''),
                'amount' => array_key_exists('amount', $payment) ? (float)$payment['amount'] : (float)($invoice['total_due'] ?? $invoice['amount'] ?? 0),
                'currency' => (string)($payment['currency'] ?? $invoice['currency'] ?? 'USD'),
                'status' => $status,
                'raw_response' => $payment['raw_response'] ?? [],
            ]);

            $this->commit();
            return [
                'success' => true,
                'invoice_id' => $invoiceId,
                'payment_status' => $paymentStatus ?: 'unpaid',
                'gateway_status' => $status,
                'transaction_id' => $transactionId,
                'idempotent' => false,
            ];
        } catch (Throwable $e) {
            $this->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function createRenewalInvoice(array $server, array $context): array
    {
        $homeId = (int)($server['home_id'] ?? 0);
        if ($homeId <= 0) {
            return ['success' => false, 'error' => 'missing_home_id'];
        }

        $this->begin();
        try {
            $existing = $this->repo->findOpenRenewalInvoiceForHome($homeId);
            if ($existing) {
                $this->commit();
                return ['success' => true, 'invoice_id' => (int)$existing['invoice_id'], 'idempotent' => true];
            }

            $periodStart = (string)($server['next_invoice_date'] ?? date('Y-m-d H:i:s'));
            if (strtotime($periodStart) === false) {
                $periodStart = date('Y-m-d H:i:s');
            }
            $periodEnd = self::addPeriod($periodStart, $context);
            $totalDue = (float)($context['total_due'] ?? $context['subtotal'] ?? 0);
            $invoiceId = $this->repo->createRenewalInvoice([
                'order_id' => (int)($context['order_id'] ?? 0),
                'user_id' => (int)($server['user_id'] ?? $server['user_id_main'] ?? 0),
                'service_id' => (int)($context['service_id'] ?? 0),
                'home_id' => $homeId,
                'home_name' => (string)($server['home_name'] ?? ''),
                'amount' => $totalDue,
                'currency' => (string)($context['currency'] ?? 'USD'),
                'payment_status' => 'unpaid',
                'status' => 'due',
                'billing_status' => 'Invoiced',
                'due_date' => $periodEnd,
                'description' => (string)($context['description'] ?? 'Server renewal'),
                'invoice_duration' => (string)($context['invoice_duration'] ?? 'month'),
                'rate_type' => (string)($context['rate_type'] ?? 'monthly'),
                'rate_per_player' => (float)($context['rate_per_player'] ?? 0),
                'players' => (int)($context['players'] ?? 0),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'subtotal' => (float)($context['subtotal'] ?? $totalDue),
                'total_due' => $totalDue,
                'qty' => (int)($context['qty'] ?? 1),
            ]);
            if ($invoiceId <= 0) {
                throw new RuntimeException('renewal_invoice_create_failed');
            }

            if (!$this->repo->updateServerHomeBilling($homeId, [
                'billing_status' => 'Invoiced',
                'server_expiration_date' => $periodEnd,
                'last_invoice_id' => $invoiceId,
                'billing_invoice_sent_at' => date('Y-m-d H:i:s'),
            ])) {
                throw new RuntimeException('home_invoice_state_update_failed');
            }
            $this->commit();
            return ['success' => true, 'invoice_id' => $invoiceId, 'period_end' => $periodEnd, 'idempotent' => false];
        } catch (Throwable $e) {
            $this->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function activateOrderProvisioning(int $orderId, int $homeId, array $context = []): array
    {
        if ($orderId <= 0 || $homeId <= 0) {
            return ['success' => false, 'error' => 'missing_order_or_home'];
        }

        $this->begin();
        try {
            $order = $this->repo->getOrder($orderId);
            if (!$order) {
                throw new RuntimeException('order_not_found');
            }
            $endDate = (string)($context['end_date'] ?? $order['end_date'] ?? date('Y-m-d H:i:s'));
            if (strtotime($endDate) === false) {
                $endDate = self::addPeriod(date('Y-m-d H:i:s'), $order);
            }

            $orderUpdate = [
                'status' => 'Active',
                'end_date' => $endDate,
                'home_id' => (string)$homeId,
            ];
            foreach (['remote_control_password', 'ftp_password'] as $field) {
                if (array_key_exists($field, $context)) {
                    $orderUpdate[$field] = $context[$field];
                }
            }
            if (!$this->repo->updateOrderFields($orderId, $orderUpdate)) {
                throw new RuntimeException('order_update_failed');
            }
            if (!$this->repo->updateInvoicesForOrderProvisioning($orderId, $homeId)) {
                throw new RuntimeException('invoice_provisioning_link_failed');
            }
            if (!$this->repo->updateTransactionsHomeForOrder($orderId, $homeId)) {
                throw new RuntimeException('transaction_home_link_failed');
            }
            if (!$this->repo->updateServerHomeBilling($homeId, [
                'billing_status' => 'Active',
                'billing_enabled' => 1,
                'next_invoice_date' => $endDate,
                'server_expiration_date' => $endDate,
                'billing_expires_at' => $endDate,
            ])) {
                throw new RuntimeException('home_billing_update_failed');
            }

            $this->commit();
            return ['success' => true, 'order_id' => $orderId, 'home_id' => $homeId, 'end_date' => $endDate];
        } catch (Throwable $e) {
            $this->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function cancelInvoice(int $invoiceId, string $status = 'cancelled'): array
    {
        if (!in_array($status, ['cancelled', 'refunded'], true)) {
            return ['success' => false, 'error' => 'invalid_terminal_status'];
        }
        $legacyStatus = $status;
        $this->begin();
        try {
            $invoice = $this->repo->getInvoiceForUpdate($invoiceId);
            if (!$invoice) {
                throw new RuntimeException('invoice_not_found');
            }
            if (!$this->repo->updateInvoiceFields($invoiceId, [
                'payment_status' => $status,
                'status' => $legacyStatus,
            ])) {
                throw new RuntimeException('invoice_cancel_update_failed');
            }
            $this->commit();
            return ['success' => true, 'invoice_id' => $invoiceId, 'payment_status' => $status];
        } catch (Throwable $e) {
            $this->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function adminUpdateInvoicePayment(int $invoiceId, string $paymentStatus, ?string $paidDate = null, array $context = []): array
    {
        $paymentStatus = strtolower(trim($paymentStatus));
        if (!in_array($paymentStatus, ['unpaid', 'paid', 'cancelled', 'refunded'], true)) {
            return ['success' => false, 'error' => 'invalid_payment_status'];
        }

        $normalizedPaidDate = self::normalizeDateTime($paidDate ?? '');
        if ($paymentStatus === 'paid' && $paidDate !== null && trim((string)$paidDate) !== '' && $normalizedPaidDate === null) {
            return ['success' => false, 'error' => 'invalid_paid_date'];
        }
        if ($paymentStatus !== 'paid' && $paidDate !== null && trim((string)$paidDate) !== '') {
            return ['success' => false, 'error' => 'paid_date_requires_paid_status'];
        }

        $invoice = $this->repo->getInvoiceForUpdate($invoiceId);
        if (!$invoice) {
            return ['success' => false, 'error' => 'invoice_not_found'];
        }

        $currentStatus = strtolower((string)($invoice['payment_status'] ?? 'unpaid'));
        if ($currentStatus === '') {
            $currentStatus = 'unpaid';
        }

        if ($paymentStatus === 'unpaid') {
            if ($currentStatus !== 'unpaid') {
                return ['success' => false, 'error' => 'disallowed_transition'];
            }
            return ['success' => true, 'invoice_id' => $invoiceId, 'payment_status' => 'unpaid', 'idempotent' => true];
        }

        if ($paymentStatus === 'cancelled') {
            if ($currentStatus === 'paid' || $currentStatus === 'refunded') {
                return ['success' => false, 'error' => 'disallowed_transition'];
            }
            if ($currentStatus === 'cancelled') {
                return ['success' => true, 'invoice_id' => $invoiceId, 'payment_status' => 'cancelled', 'idempotent' => true];
            }
            return $this->cancelInvoice($invoiceId, 'cancelled');
        }

        if ($paymentStatus === 'refunded') {
            if ($currentStatus === 'unpaid' || $currentStatus === 'cancelled') {
                return ['success' => false, 'error' => 'disallowed_transition'];
            }
            if ($currentStatus === 'refunded') {
                return ['success' => true, 'invoice_id' => $invoiceId, 'payment_status' => 'refunded', 'idempotent' => true];
            }
            return $this->cancelInvoice($invoiceId, 'refunded');
        }

        if (in_array($currentStatus, ['cancelled', 'refunded'], true)) {
            return ['success' => false, 'error' => 'invoice_terminal'];
        }

        $paidAt = $normalizedPaidDate ?: self::normalizeDateTime($invoice['paid_date'] ?? '') ?: date('Y-m-d H:i:s');
        if ($currentStatus !== 'paid') {
            $txid = trim((string)($context['transaction_external_id'] ?? $invoice['payment_txid'] ?? ''));
            if ($txid === '') {
                $txid = 'manual-admin-invoice-' . $invoiceId;
            }
            return $this->processPaidInvoice($invoiceId, [
                'transaction_external_id' => $txid,
                'payment_method' => (string)($context['payment_method'] ?? 'manual'),
                'amount' => (float)($context['amount'] ?? $invoice['total_due'] ?? $invoice['amount'] ?? 0),
                'currency' => (string)($context['currency'] ?? $invoice['currency'] ?? 'USD'),
                'paid_at' => $paidAt,
                'raw_response' => [
                    'source' => 'admin_invoice_payment',
                    'admin_user_id' => (int)($context['admin_user_id'] ?? 0),
                ],
            ], $context);
        }

        $this->begin();
        try {
            $invoiceUpdate = [
                'payment_status' => 'paid',
                'status' => 'paid',
                'billing_status' => 'Active',
                'paid_date' => $paidAt,
            ];
            if (empty($invoice['payment_method'])) {
                $invoiceUpdate['payment_method'] = (string)($context['payment_method'] ?? 'manual');
            }
            if (!$this->repo->updateInvoiceFields($invoiceId, $invoiceUpdate)) {
                throw new RuntimeException('invoice_update_failed');
            }
            $orderId = (int)($invoice['order_id'] ?? 0);
            if ($orderId > 0) {
                if (!$this->repo->updateOrderFields($orderId, ['paid_ts' => $paidAt])) {
                    throw new RuntimeException('order_update_failed');
                }
            }
            $this->commit();
            return [
                'success' => true,
                'invoice_id' => $invoiceId,
                'order_id' => $orderId,
                'payment_status' => 'paid',
                'paid_date' => $paidAt,
                'idempotent' => true,
            ];
        } catch (Throwable $e) {
            $this->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function adminChangeServiceTerm(int $orderId, string $newEndDate, array $context = []): array
    {
        $newEndDate = self::normalizeDateTime($newEndDate);
        if ($newEndDate === null) {
            return ['success' => false, 'error' => 'invalid_end_date'];
        }
        if (strtotime($newEndDate) <= time()) {
            return ['success' => false, 'error' => 'end_date_must_be_future'];
        }

        $this->begin();
        try {
            $order = $this->repo->getOrder($orderId);
            if (!$order) {
                throw new RuntimeException('order_not_found');
            }

            $homeId = (int)($order['home_id'] ?? 0);
            if ($homeId <= 0) {
                throw new RuntimeException('order_not_linked_to_home');
            }

            $home = $this->repo->getServerHomeBilling($homeId);
            if (!$home) {
                throw new RuntimeException('home_not_found');
            }
            if (($home['billing_status'] ?? '') !== 'Active') {
                throw new RuntimeException('home_not_active');
            }
            if ($this->repo->findOpenRenewalInvoiceForHome($homeId)) {
                throw new RuntimeException('open_invoice_exists');
            }

            $currentEnd = null;
            foreach ([
                $home['next_invoice_date'] ?? null,
                $home['billing_expires_at'] ?? null,
                $home['server_expiration_date'] ?? null,
                $order['end_date'] ?? null,
            ] as $candidate) {
                $candidate = self::normalizeDateTime($candidate);
                if ($candidate !== null) {
                    $currentEnd = $candidate;
                    break;
                }
            }

            if ($currentEnd !== null && $newEndDate === $currentEnd) {
                $this->commit();
                return [
                    'success' => true,
                    'order_id' => $orderId,
                    'home_id' => $homeId,
                    'end_date' => $newEndDate,
                    'idempotent' => true,
                ];
            }

            $allowShortening = !empty($context['allow_shortening']);
            if ($currentEnd !== null && strtotime($newEndDate) < strtotime($currentEnd) && !$allowShortening) {
                throw new RuntimeException('shortening_requires_confirmation');
            }

            $invoice = $this->repo->getLatestInvoiceForOrder($orderId, true);
            if (!$invoice) {
                $invoice = $this->repo->getLatestInvoiceForHome($homeId, true);
            }
            if ($invoice) {
                $periodStart = self::normalizeDateTime($invoice['period_start'] ?? '');
                if ($periodStart !== null && strtotime($newEndDate) <= strtotime($periodStart)) {
                    throw new RuntimeException('end_date_before_invoice_period_start');
                }
                if (!$this->repo->updateInvoiceFields((int)$invoice['invoice_id'], [
                    'period_end' => $newEndDate,
                    'billing_status' => 'Active',
                ])) {
                    throw new RuntimeException('invoice_term_update_failed');
                }
            }

            if (!$this->repo->updateOrderFields($orderId, [
                'status' => 'Active',
                'end_date' => $newEndDate,
            ])) {
                throw new RuntimeException('order_term_update_failed');
            }
            if (!$this->repo->updateServerHomeBilling($homeId, [
                'billing_status' => 'Active',
                'billing_enabled' => 1,
                'next_invoice_date' => $newEndDate,
                'server_expiration_date' => $newEndDate,
                'billing_expires_at' => $newEndDate,
                'billing_invoice_sent_at' => null,
            ])) {
                throw new RuntimeException('home_term_update_failed');
            }

            $this->commit();
            return [
                'success' => true,
                'order_id' => $orderId,
                'home_id' => $homeId,
                'invoice_id' => (int)($invoice['invoice_id'] ?? 0),
                'previous_end_date' => $currentEnd,
                'end_date' => $newEndDate,
                'shortened' => $currentEnd !== null && strtotime($newEndDate) < strtotime($currentEnd),
                'extended' => $currentEnd !== null && strtotime($newEndDate) > strtotime($currentEnd),
            ];
        } catch (Throwable $e) {
            $this->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function expireServer(int $homeId, int $invoiceId = 0): array
    {
        $this->begin();
        try {
            if (!$this->repo->updateServerHomeBilling($homeId, ['billing_status' => 'Expired'])) {
                throw new RuntimeException('home_expire_update_failed');
            }
            if ($invoiceId > 0) {
                if (!$this->repo->updateInvoiceFields($invoiceId, ['billing_status' => 'Expired'])) {
                    throw new RuntimeException('invoice_expire_update_failed');
                }
            }
            $this->commit();
            return ['success' => true, 'home_id' => $homeId, 'invoice_id' => $invoiceId];
        } catch (Throwable $e) {
            $this->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function prepareDeletion(int $homeId): array
    {
        $home = $this->repo->getServerHomeBilling($homeId);
        if (!$home || ($home['billing_status'] ?? '') !== 'Expired') {
            return ['success' => false, 'error' => 'home_not_expired'];
        }
        $lastInvoiceId = (int)($home['last_invoice_id'] ?? 0);
        if ($lastInvoiceId <= 0 || $this->repo->hasPaidInvoiceAtOrAfter($homeId, $lastInvoiceId)) {
            return ['success' => false, 'error' => 'ambiguous_paid_service'];
        }

        $this->begin();
        try {
            if (!$this->repo->markOrdersExpiredForHome($homeId)) {
                throw new RuntimeException('orders_expire_update_failed');
            }
            if (!$this->repo->markOpenInvoicesExpiredForHome($homeId)) {
                throw new RuntimeException('invoices_expire_update_failed');
            }
            $this->commit();
            return ['success' => true, 'home_id' => $homeId];
        } catch (Throwable $e) {
            $this->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function begin(): void
    {
        if (method_exists($this->repo, 'beginLifecycleTransaction')) {
            $this->repo->beginLifecycleTransaction();
        }
    }

    private function commit(): void
    {
        if (method_exists($this->repo, 'commitLifecycleTransaction')) {
            $this->repo->commitLifecycleTransaction();
        }
    }

    private function rollback(): void
    {
        if (method_exists($this->repo, 'rollbackLifecycleTransaction')) {
            $this->repo->rollbackLifecycleTransaction();
        }
    }
}
