<?php
require_once __DIR__ . '/../includes/support_tickets.php';

function assertSupportSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

// --- validation ---
assertSupportSame([], support_ticket_errors('Server will not start', 'It stopped after the update.', 0, []), 'a ticket with no server is fine');
assertSupportSame([], support_ticket_errors('Server will not start', 'It stopped after the update.', 7, [3, 7]), 'a ticket on an owned server is fine');
assertSupportSame(1, count(support_ticket_errors('Server will not start', 'It stopped after the update.', 9, [3, 7])), 'a server the customer does not own is refused');
assertSupportSame(1, count(support_ticket_errors('abc', 'It stopped after the update.', 0, [])), 'a 3 character subject is refused');
assertSupportSame(1, count(support_ticket_errors(str_repeat('a', 65), 'It stopped after the update.', 0, [])), 'a 65 character subject is refused');
assertSupportSame([], support_ticket_errors(str_repeat('a', 64), 'abcd', 0, []), '64 and 4 characters are accepted');
assertSupportSame(1, count(support_ticket_errors('Valid subject', 'abc', 0, [])), 'a 3 character message is refused');
assertSupportSame(1, count(support_ticket_errors('Valid subject', str_repeat('m', 5001), 0, [])), 'a 5001 character message is refused');
assertSupportSame(3, count(support_ticket_errors('', '', 5, [])), 'every problem is reported');
assertSupportSame([], support_ticket_errors(str_repeat("\u{00e9}", 64), 'abcd', 0, []), 'length counts characters, not bytes');

// --- status labels and address bytes ---
assertSupportSame('Waiting for staff', support_ticket_status_label(3), 'status 3 is waiting for staff');
assertSupportSame('Closed', support_ticket_status_label(0), 'status 0 is closed');
assertSupportSame(4, strlen(support_ticket_ip_bytes('203.0.113.9')), 'an IPv4 address is 4 bytes');
assertSupportSame(16, strlen(support_ticket_ip_bytes('2001:db8::1')), 'an IPv6 address is 16 bytes');
assertSupportSame(inet_pton('0.0.0.0'), support_ticket_ip_bytes('not an ip'), 'a bad address falls back to 0.0.0.0');

echo "SupportTicketsTest passed\n";
