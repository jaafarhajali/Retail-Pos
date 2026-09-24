<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;
use App\Services\SettingService;

$valid = static fn (array $override = []): array => array_merge([
    'shop_name'                => 'Argile House',
    'shop_address'             => 'Hamra Street, Beirut',
    'shop_phone'               => '+961 1 234 567',
    'receipt_header'           => 'Welcome',
    'receipt_footer'           => 'Thank you!',
    'lbp_rounding_step'        => '5000',
    'max_cashier_discount_pct' => '0',
    'usd_denominations'        => '100,50,20,10,5,1',
    'lbp_denominations'        => '100000,50000,20000,10000,5000,1000',
], $override);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'valid settings are saved and visible immediately' => function () use ($valid): void {
        Settings::get('shop_name');   // warm the cache
        (new SettingService())->save($valid(['max_cashier_discount_pct' => '10']));
        assert_same('Argile House', Settings::get('shop_name'));
        assert_same('10', Settings::get('max_cashier_discount_pct'));
    },

    // Review focus 1
    'arabic shop names are kept exactly' => function () use ($valid): void {
        (new SettingService())->save($valid(['shop_name' => 'معسل الشام']));
        assert_same('معسل الشام', Settings::get('shop_name'));
    },

    'the shop name is required' => function () use ($valid): void {
        assert_throws(DomainException::class, fn () => (new SettingService())->save($valid(['shop_name' => '  '])));
    },

    'the cashier discount must be a whole number from 0 to 100' => function () use ($valid): void {
        foreach (['101', '-1', '5.5', 'abc', ''] as $bad) {
            assert_throws(DomainException::class, fn () => (new SettingService())->save($valid(['max_cashier_discount_pct' => $bad])));
        }
        (new SettingService())->save($valid(['max_cashier_discount_pct' => '100']));
        assert_same('100', Settings::get('max_cashier_discount_pct'));
    },

    'the LBP rounding step must be 1,000, 5,000 or 10,000' => function () use ($valid): void {
        foreach (['2500', '0', 'abc'] as $bad) {
            assert_throws(DomainException::class, fn () => (new SettingService())->save($valid(['lbp_rounding_step' => $bad])));
        }
    },

    'denominations are cleaned: duplicates removed, sorted high to low' => function () use ($valid): void {
        (new SettingService())->save($valid(['usd_denominations' => '5, 20,1,20']));
        assert_same('20,5,1', Settings::get('usd_denominations'));
    },

    'bad denominations are refused' => function () use ($valid): void {
        foreach (['abc', '', '0', '10,-5'] as $bad) {
            assert_throws(DomainException::class, fn () => (new SettingService())->save($valid(['lbp_denominations' => $bad])));
        }
    },

    'changes are audited with old and new values' => function () use ($valid): void {
        (new SettingService())->save($valid());
        $row = Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'settings.updated' ORDER BY id DESC LIMIT 1")->fetch();
        $details = json_decode((string) $row['details'], true);
        assert_same('Retail POS', $details['shop_name']['old']);
        assert_same('Argile House', $details['shop_name']['new']);
    },
];
