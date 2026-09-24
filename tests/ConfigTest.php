<?php
declare(strict_types=1);

return [
    'the test environment uses the separate test database' => function (): void {
        assert_same('test', APP_ENV);
        assert_same('retail_pos_test', DB_NAME);
    },

    'php runs in the configured application timezone' => function (): void {
        assert_same(APP_TIMEZONE, date_default_timezone_get());
    },
];
