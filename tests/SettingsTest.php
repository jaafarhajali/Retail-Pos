<?php
declare(strict_types=1);

use App\Core\Settings;
use App\Models\Setting;

return [
    '__before' => 'test_db_reset',

    'get returns stored values and falls back to the default' => function (): void {
        assert_same('5000', Settings::get('lbp_rounding_step'));
        assert_same('x', Settings::get('no_such_key', 'x'));
        assert_same('5000', setting('lbp_rounding_step'));
    },

    'values are cached until flush' => function (): void {
        assert_same('Retail POS', Settings::get('shop_name'));
        (new Setting())->setMany(['shop_name' => 'متجر الأرجيلة']);
        assert_same('Retail POS', Settings::get('shop_name'), 'still cached');
        Settings::flush();
        assert_same('متجر الأرجيلة', Settings::get('shop_name'));
    },
];
