<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the seeded rate is current' => function (): void {
        assert_same(90000, (new ExchangeRate())->current());
    },

    'setting a rate makes it current and keeps the history, newest first' => function (): void {
        assert_same(89500, (new ExchangeRateService())->set('89500'));
        assert_same(89500, (new ExchangeRate())->current());
        $history = (new ExchangeRate())->history();
        assert_same(89500, (int) $history[0]['lbp_per_usd']);
        assert_same('admin', $history[0]['username']);
        assert_same(90000, (int) $history[1]['lbp_per_usd']);
    },

    // Review focus 5
    'thousands separators and spaces are accepted' => function (): void {
        assert_same(89500, (new ExchangeRateService())->set('89,500'));
        assert_same(91000, (new ExchangeRateService())->set(' 91 000 '));
    },

    'rates outside 1,000-10,000,000 or non-numbers are refused' => function (): void {
        foreach (['0', '999', '10000001', 'abc', '89.5', '-5', ''] as $bad) {
            assert_throws(DomainException::class, fn () => (new ExchangeRateService())->set($bad));
        }
    },

    'setting the same rate again is refused' => function (): void {
        $e = assert_throws(DomainException::class, fn () => (new ExchangeRateService())->set('90,000'));
        assert_contains('already', $e->getMessage());
    },

    'rate changes are audited with the old and new rate' => function (): void {
        (new ExchangeRateService())->set('89500');
        $row = Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'rate.changed' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same(['old' => 90000, 'new' => 89500], json_decode((string) $row['details'], true));
    },
];
