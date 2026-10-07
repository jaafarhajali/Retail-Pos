<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * 2026-10-07 (Aya, found while testing): the Discount button opened the payment dialog as it was drawn the last time,
 * so a $44.00 sale showed "Due $22.00" with a 22.00 cash line, and the first sale after a finished one showed the old
 * sale's amounts. A discount typed for a sale that was then held or emptied also followed the next sale, with the
 * administrator's approval it had been given. The dialog is now drawn for the sale as it is, each time it opens, and
 * a sale that ended leaves nothing behind.
 */
return [
    'Take payment and Discount open the payment dialog the same way: drawn for the sale as it is now' => function (): void {
        $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/pos.js');
        assert_contains("\$('btn-pay').addEventListener('click', openPay);", $js);
        assert_contains("\$('btn-discount').addEventListener('click', function () { if (!cart.length) return; openPay();", $js);
        assert_same(1, preg_match('~function openPay\(\) \{(.*?)\n  \}~s', $js, $m));
        assert_contains('followTotal();', $m[1], 'an untouched cash line follows the new total');
        assert_contains('renderPay();', $m[1]);
        assert_true(strpos($m[1], 'renderPay();') < strpos($m[1], "modals['m-pay'].show()"), 'drawn before it is shown');
    },

    'a sale that was paid, held or emptied leaves no payment, discount, note or approval for the next one' => function (): void {
        $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/pos.js');
        assert_same(1, preg_match('~function clearPayment\(\) \{(.*?)\n  \}~s', $js, $m));
        foreach (['payments = []', "approvedPin = ''", "\$('pay-discount').value = ''", "\$('pay-pin').value = ''", "\$('pay-note').value = ''", 'allowShort = false'] as $reset) {
            assert_contains($reset, $m[1]);
        }
        assert_contains('cart = []; selected = -1; clearPayment(); setCustomer(null); renderCart(); renderPay(); loadData();', $js, 'after a completed sale');
        assert_contains('cart = []; selected = -1; clearPayment(); renderCart(); modals[\'m-hold\'].hide();', $js, 'after the cart was held');
        assert_contains('if (!cart.length) { clearPayment(); }', $js, 'after the last line was removed');
    },
];
