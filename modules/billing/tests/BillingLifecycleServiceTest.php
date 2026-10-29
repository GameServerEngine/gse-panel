<?php
require_once __DIR__ . '/../classes/BillingLifecycleService.php';

final class FakeLifecycleRepository
{
    public array $invoices = [];
    public array $orders = [];
    public array $homes = [];
    public array $transactions = [];
    public array $catalogLocks = [];
    public array $failMethods = [];
    private int $nextOrderId = 1000;
    private int $nextInvoiceId = 2000;
    private int $nextTransactionId = 3000;

    public function beginLifecycleTransaction(): bool { return true; }
    public function commitLifecycleTransaction(): bool { return true; }
    public function rollbackLifecycleTransaction(): bool { return true; }
    public function getInvoiceForUpdate(int $invoiceId): ?array { return $this->invoices[$invoiceId] ?? null; }
    public function getInvoice(int $invoiceId): ?array { return $this->invoices[$invoiceId] ?? null; }
    public function getOrder(int $orderId): ?array { return $this->orders[$orderId] ?? null; }
    public function getServerHomeBilling(int $homeId): ?array { return $this->homes[$homeId] ?? null; }
    public function getLatestInvoiceForOrder(int $orderId, bool $paidOnly = false): ?array
    {
        $matched = null;
        foreach ($this->invoices as $invoice) {
            if ((int)($invoice['order_id'] ?? 0) !== $orderId) {
                continue;
            }
            if ($paidOnly && ($invoice['payment_status'] ?? '') !== 'paid') {
                continue;
            }
            if ($matched === null || (int)$invoice['invoice_id'] > (int)$matched['invoice_id']) {
                $matched = $invoice;
            }
        }
        return $matched;
    }
    public function getLatestInvoiceForHome(int $homeId, bool $paidOnly = false): ?array
    {
        $matched = null;
        foreach ($this->invoices as $invoice) {
            if ((int)($invoice['home_id'] ?? 0) !== $homeId) {
                continue;
            }
            if ($paidOnly && ($invoice['payment_status'] ?? '') !== 'paid') {
                continue;
            }
            if ($matched === null || (int)$invoice['invoice_id'] > (int)$matched['invoice_id']) {
                $matched = $invoice;
            }
        }
        return $matched;
    }
    private function shouldFail(string $method): bool { return !empty($this->failMethods[$method]); }
    public function updateInvoiceOrderId(int $invoiceId, int $orderId): bool { if ($this->shouldFail(__FUNCTION__)) return false; $this->invoices[$invoiceId]['order_id'] = $orderId; return true; }
    public function updateInvoiceFields(int $invoiceId, array $data): bool { if ($this->shouldFail(__FUNCTION__)) return false; $this->invoices[$invoiceId] = array_merge($this->invoices[$invoiceId], $data); return true; }
    public function updateOrderFields(int $orderId, array $data): bool { if ($this->shouldFail(__FUNCTION__)) return false; $this->orders[$orderId] = array_merge($this->orders[$orderId], $data); return true; }
    public function updateServerHomeBilling(int $homeId, array $data): bool { if ($this->shouldFail(__FUNCTION__)) return false; $this->homes[$homeId] = array_merge($this->homes[$homeId] ?? ['home_id' => $homeId], $data); return true; }
    public function updateInvoicesForOrderProvisioning(int $orderId, int $homeId): bool
    {
        if ($this->shouldFail(__FUNCTION__)) return false;
        foreach ($this->invoices as $id => $invoice) {
            if ((int)($invoice['order_id'] ?? 0) === $orderId) {
                $this->invoices[$id] = array_merge($invoice, [
                    'home_id' => $homeId,
                    'billing_status' => 'Active',
                    'status' => 'paid',
                    'payment_status' => 'paid',
                ]);
            }
        }
        return true;
    }
    public function updateTransactionsHomeForOrder(int $orderId, int $homeId): bool
    {
        if ($this->shouldFail(__FUNCTION__)) return false;
        foreach ($this->transactions as $id => $transaction) {
            $invoiceId = (int)($transaction['invoice_id'] ?? 0);
            if (!empty($this->invoices[$invoiceId]) && (int)($this->invoices[$invoiceId]['order_id'] ?? 0) === $orderId) {
                $this->transactions[$id]['home_id'] = $homeId;
            }
        }
        return true;
    }
    public function createOrder(array $data): int
    {
        $id = $this->nextOrderId++;
        $this->orders[$id] = array_merge(['order_id' => $id, 'home_id' => '0'], $data);
        return $id;
    }
    public function lockCatalogOrderPrice(int $orderId, array $invoice, float $unitPrice, float $totalPrice, int $quantity, string $currency = 'USD'): bool
    {
        if ($this->shouldFail(__FUNCTION__)) return false;
        $this->catalogLocks[$orderId] = compact('unitPrice', 'totalPrice', 'quantity', 'currency');
        return true;
    }
    public function createRenewalInvoice(array $data): int
    {
        $id = $this->nextInvoiceId++;
        $this->invoices[$id] = array_merge(['invoice_id' => $id], $data);
        return $id;
    }
    public function findOpenRenewalInvoiceForHome(int $homeId): ?array
    {
        foreach ($this->invoices as $invoice) {
            if ((int)($invoice['home_id'] ?? 0) === $homeId && ($invoice['billing_status'] ?? '') === 'Invoiced' && ($invoice['payment_status'] ?? '') !== 'paid') {
                return $invoice;
            }
        }
        return null;
    }
    public function logTransaction(array $data): int
    {
        foreach ($this->transactions as $id => $transaction) {
            if ((int)$transaction['invoice_id'] === (int)$data['invoice_id']
                && (string)$transaction['transaction_external_id'] === (string)$data['transaction_external_id']) {
                return $id;
            }
        }
        $id = $this->nextTransactionId++;
        $this->transactions[$id] = $data;
        return $id;
    }
    public function hasPaidInvoiceAtOrAfter(int $homeId, int $invoiceId): bool
    {
        foreach ($this->invoices as $invoice) {
            if ((int)($invoice['home_id'] ?? 0) === $homeId
                && (int)($invoice['invoice_id'] ?? 0) >= $invoiceId
                && ($invoice['payment_status'] ?? '') === 'paid') {
                return true;
            }
        }
        return false;
    }
    public function markOrdersExpiredForHome(int $homeId): bool
    {
        if ($this->shouldFail(__FUNCTION__)) return false;
        foreach ($this->orders as $id => $order) {
            if ((int)($order['home_id'] ?? 0) === $homeId) {
                $this->orders[$id]['status'] = 'Expired';
                $this->orders[$id]['home_id'] = '0';
            }
        }
        return true;
    }
    public function markOpenInvoicesExpiredForHome(int $homeId): bool
    {
        if ($this->shouldFail(__FUNCTION__)) return false;
        foreach ($this->invoices as $id => $invoice) {
            if ((int)($invoice['home_id'] ?? 0) === $homeId && ($invoice['billing_status'] ?? '') === 'Invoiced') {
                $this->invoices[$id]['billing_status'] = 'Expired';
            }
        }
        return true;
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function assertTrueValue($actual, string $message): void
{
    if (!$actual) {
        throw new RuntimeException($message);
    }
}

$repo = new FakeLifecycleRepository();
$svc = new BillingLifecycleService($repo);

// Initial paid checkout to Active order.
$repo->invoices[1] = [
    'invoice_id' => 1, 'order_id' => 0, 'user_id' => 10, 'service_id' => 5, 'home_id' => 0,
    'home_name' => 'New Server', 'ip' => 1, 'qty' => 1, 'invoice_duration' => 'month',
    'max_players' => 12, 'amount' => 12.00, 'total_due' => 12.00, 'currency' => 'USD',
    'payment_status' => 'unpaid', 'status' => 'due', 'billing_status' => 'due',
    'period_start' => '2026-07-01 00:00:00', 'period_end' => '2026-08-01 00:00:00',
];
$paid = $svc->processPaidInvoice(1, ['transaction_external_id' => 'tx-new', 'amount' => 12.00, 'paid_at' => '2026-07-01 01:00:00']);
assertTrueValue($paid['success'], 'initial checkout succeeds');
assertSameValue('paid', $repo->invoices[1]['payment_status'], 'invoice payment truth is paid');
assertSameValue('Active', $repo->orders[$paid['order_id']]['status'], 'new order is Active for provisioning');

// Provisioning linkage.
$repo->homes[55] = ['home_id' => 55, 'billing_status' => 'Active', 'last_invoice_id' => 0];
$linked = $svc->activateOrderProvisioning((int)$paid['order_id'], 55, ['end_date' => '2026-08-01 00:00:00']);
assertTrueValue($linked['success'], 'provisioning linkage succeeds');
assertSameValue(55, (int)$repo->invoices[1]['home_id'], 'invoice home_id linked');
assertSameValue('2026-08-01 00:00:00', $repo->homes[55]['next_invoice_date'], 'server next invoice date synchronized');

// Renewal invoice creation and duplicate guard.
$renewal = $svc->createRenewalInvoice(['home_id' => 55, 'home_name' => 'New Server', 'user_id' => 10, 'next_invoice_date' => '2026-08-01 00:00:00'], [
    'order_id' => (int)$paid['order_id'], 'service_id' => 5, 'invoice_duration' => 'month',
    'rate_type' => 'monthly', 'subtotal' => 12.00, 'total_due' => 12.00,
]);
assertTrueValue($renewal['success'], 'renewal invoice created');
assertSameValue('Invoiced', $repo->homes[55]['billing_status'], 'server marked Invoiced');
$renewalReplay = $svc->createRenewalInvoice(['home_id' => 55, 'home_name' => 'New Server', 'user_id' => 10, 'next_invoice_date' => '2026-08-01 00:00:00'], []);
assertSameValue(true, $renewalReplay['idempotent'], 'duplicate renewal invoice is idempotent');

// Renewal payment extension from invoice period baseline.
$renewalId = (int)$renewal['invoice_id'];
$renewPaid = $svc->processPaidInvoice($renewalId, ['transaction_external_id' => 'tx-renew', 'amount' => 12.00, 'paid_at' => '2026-08-02 00:00:00']);
assertTrueValue($renewPaid['success'], 'renewal payment succeeds');
assertSameValue($repo->invoices[$renewalId]['period_end'], $repo->homes[55]['next_invoice_date'], 'renewal extends to invoice period_end');

// Restoration from Expired.
$repo->homes[55]['billing_status'] = 'Expired';
$repo->invoices[300] = [
    'invoice_id' => 300, 'order_id' => (int)$paid['order_id'], 'user_id' => 10, 'home_id' => 55,
    'payment_status' => 'unpaid', 'status' => 'due', 'billing_status' => 'Expired',
    'period_start' => '2026-09-01 00:00:00', 'period_end' => '2026-10-01 00:00:00',
    'amount' => 12.00, 'currency' => 'USD', 'invoice_duration' => 'month',
];
$restore = $svc->processPaidInvoice(300, ['transaction_external_id' => 'tx-restore', 'amount' => 12.00, 'paid_at' => '2026-09-03 00:00:00']);
assertTrueValue($restore['success'], 'expired server restoration succeeds');
assertSameValue('Active', $repo->homes[55]['billing_status'], 'expired server restored Active');

// Duplicate payment replay.
$beforeTransactions = count($repo->transactions);
$replay = $svc->processPaidInvoice(300, ['transaction_external_id' => 'tx-restore', 'amount' => 12.00, 'paid_at' => '2026-09-03 00:00:00']);
assertSameValue(true, $replay['idempotent'], 'duplicate payment replay marked idempotent');
assertSameValue($beforeTransactions, count($repo->transactions), 'duplicate payment does not duplicate transaction');

// Pending and failed gateway attempts are transaction-only and do not mutate invoice/order truth.
$repo->invoices[7000] = [
    'invoice_id' => 7000, 'order_id' => 0, 'user_id' => 70, 'home_id' => 0,
    'payment_status' => 'unpaid', 'status' => 'due', 'billing_status' => 'due',
    'amount' => 9.00, 'total_due' => 9.00, 'currency' => 'USD',
];
$pendingAttempt = $svc->recordGatewayAttempt(7000, [
    'transaction_external_id' => 'paypal-order-pending',
    'payment_method' => 'paypal',
    'amount' => 9.00,
    'currency' => 'USD',
], 'pending');
assertTrueValue($pendingAttempt['success'], 'pending gateway attempt is recorded');
assertSameValue('unpaid', $repo->invoices[7000]['payment_status'], 'pending attempt leaves invoice unpaid');
$pendingReplayCount = count($repo->transactions);
$pendingReplay = $svc->recordGatewayAttempt(7000, [
    'transaction_external_id' => 'paypal-order-pending',
    'payment_method' => 'paypal',
    'amount' => 9.00,
    'currency' => 'USD',
], 'pending');
assertTrueValue($pendingReplay['success'], 'pending gateway replay succeeds');
assertSameValue($pendingReplayCount, count($repo->transactions), 'pending replay does not duplicate transaction');

$failedAttempt = $svc->recordGatewayAttempt(7000, [
    'transaction_external_id' => 'paypal-capture-denied',
    'payment_method' => 'paypal',
    'amount' => 9.00,
    'currency' => 'USD',
], 'failed');
assertTrueValue($failedAttempt['success'], 'failed gateway attempt is recorded');
assertSameValue('unpaid', $repo->invoices[7000]['payment_status'], 'failed attempt leaves invoice payable');
assertSameValue('due', $repo->invoices[7000]['status'], 'failed attempt keeps customer cart/invoice status due');

$invalidAttemptStatus = $svc->recordGatewayAttempt(7000, [], 'completed');
assertSameValue(false, $invalidAttemptStatus['success'], 'completed gateway attempts must use processPaidInvoice');

$paidAttemptReplay = $svc->recordGatewayAttempt(300, [
    'transaction_external_id' => 'late-denied-after-paid',
    'payment_method' => 'paypal',
    'amount' => 12.00,
], 'failed');
assertSameValue(true, $paidAttemptReplay['idempotent'], 'gateway attempt against paid invoice is idempotent');

// Admin mark-paid with explicit paid_date propagates through linked order/server.
$repo->homes[88] = ['home_id' => 88, 'billing_status' => 'Invoiced', 'next_invoice_date' => '2026-08-01 00:00:00'];
$repo->orders[880] = ['order_id' => 880, 'home_id' => 88, 'status' => 'Invoiced', 'end_date' => '2026-08-01 00:00:00'];
$repo->invoices[8800] = [
    'invoice_id' => 8800, 'order_id' => 880, 'user_id' => 88, 'home_id' => 88,
    'payment_status' => 'unpaid', 'status' => 'due', 'billing_status' => 'Invoiced',
    'period_start' => '2026-08-01 00:00:00', 'period_end' => '2026-09-01 00:00:00',
    'amount' => 15.00, 'total_due' => 15.00, 'currency' => 'USD', 'invoice_duration' => 'month',
];
$adminPaid = $svc->adminUpdateInvoicePayment(8800, 'paid', '2026-08-02 03:04:00', ['admin_user_id' => 1]);
assertTrueValue($adminPaid['success'], 'admin mark paid succeeds');
assertSameValue('paid', $repo->invoices[8800]['payment_status'], 'admin mark paid sets payment truth');
assertSameValue('2026-08-02 03:04:00', $repo->invoices[8800]['paid_date'], 'admin paid_date persisted');
assertSameValue('Active', $repo->orders[880]['status'], 'admin mark paid activates linked order');
assertSameValue('2026-09-01 00:00:00', $repo->homes[88]['next_invoice_date'], 'admin mark paid propagates server term');
$adminReplayCount = count($repo->transactions);
$adminPaidReplay = $svc->adminUpdateInvoicePayment(8800, 'paid', '2026-08-02 03:04:00', ['admin_user_id' => 1]);
assertTrueValue($adminPaidReplay['success'], 'admin paid replay succeeds idempotently');
assertSameValue($adminReplayCount, count($repo->transactions), 'admin paid replay does not duplicate transaction');

$badPaidDate = $svc->adminUpdateInvoicePayment(8800, 'paid', 'not-a-date', []);
assertSameValue(false, $badPaidDate['success'], 'invalid paid_date rejected');

$repo->invoices[8801] = ['invoice_id' => 8801, 'payment_status' => 'unpaid', 'status' => 'due'];
$badRefund = $svc->adminUpdateInvoicePayment(8801, 'refunded', null, []);
assertSameValue(false, $badRefund['success'], 'unpaid invoice cannot be refunded');

// Approved service-term extension synchronizes server home, order compatibility, and latest paid invoice period.
$term = $svc->adminChangeServiceTerm(880, '2026-10-01 00:00:00', ['admin_user_id' => 1]);
assertTrueValue($term['success'], 'admin service-term extension succeeds');
assertSameValue('2026-10-01 00:00:00', $repo->orders[880]['end_date'], 'order end_date synchronized as compatibility field');
assertSameValue('2026-10-01 00:00:00', $repo->homes[88]['server_expiration_date'], 'server expiration synchronized');
assertSameValue('2026-10-01 00:00:00', $repo->invoices[8800]['period_end'], 'latest paid invoice period_end synchronized');

$badChronology = $svc->adminChangeServiceTerm(880, '2026-07-01 00:00:00', ['admin_user_id' => 1]);
assertSameValue(false, $badChronology['success'], 'end date before invoice period rejected');

$repo->invoices[8802] = [
    'invoice_id' => 8802, 'order_id' => 880, 'home_id' => 88,
    'payment_status' => 'unpaid', 'billing_status' => 'Invoiced',
];
$blockedTerm = $svc->adminChangeServiceTerm(880, '2026-11-01 00:00:00', ['admin_user_id' => 1]);
assertSameValue(false, $blockedTerm['success'], 'open renewal invoice blocks service-term edit');

// Cancelled/refunded behavior.
$repo->invoices[400] = ['invoice_id' => 400, 'payment_status' => 'unpaid', 'status' => 'due'];
$cancel = $svc->cancelInvoice(400, 'cancelled');
assertTrueValue($cancel['success'], 'cancel succeeds');
assertSameValue('cancelled', $repo->invoices[400]['payment_status'], 'cancel sets payment truth');
$refund = $svc->cancelInvoice(300, 'refunded');
assertTrueValue($refund['success'], 'refund succeeds');
assertSameValue('refunded', $repo->invoices[300]['status'], 'refund syncs legacy status');

// Deletion preparation does not delete ambiguous paid services.
$repo->homes[77] = ['home_id' => 77, 'billing_status' => 'Expired', 'last_invoice_id' => 500];
$repo->invoices[500] = ['invoice_id' => 500, 'home_id' => 77, 'payment_status' => 'paid', 'billing_status' => 'Active'];
$deletePrep = $svc->prepareDeletion(77);
assertSameValue(false, $deletePrep['success'], 'paid ambiguous service is not prepared for deletion');

// Lifecycle transitions fail closed when a required secondary write fails.
$failingRepo = new FakeLifecycleRepository();
$failingSvc = new BillingLifecycleService($failingRepo);
$failingRepo->failMethods['updateInvoiceOrderId'] = true;
$failingRepo->invoices[9000] = [
    'invoice_id' => 9000, 'order_id' => 0, 'user_id' => 90, 'service_id' => 9, 'home_id' => 0,
    'home_name' => 'Failure Case', 'ip' => 1, 'qty' => 1, 'invoice_duration' => 'month',
    'max_players' => 8, 'amount' => 8.00, 'total_due' => 8.00, 'currency' => 'USD',
    'payment_status' => 'unpaid', 'status' => 'due', 'billing_status' => 'due',
    'period_start' => '2026-07-01 00:00:00', 'period_end' => '2026-08-01 00:00:00',
];
$failedLink = $failingSvc->processPaidInvoice(9000, ['transaction_external_id' => 'tx-fail', 'amount' => 8.00, 'paid_at' => '2026-07-01 01:00:00']);
assertSameValue(false, $failedLink['success'], 'invoice/order link failure rejects paid invoice transition');
assertSameValue('invoice_order_link_failed', $failedLink['error'], 'invoice/order link failure is explicit');

echo "BillingLifecycleServiceTest passed\n";
