<?php
declare(strict_types=1);

use App\Core\Csrf;

return [
    '__before' => function (): void {
        $_SESSION = [];
    },

    'the token is 64 hex characters and stable within a session' => function (): void {
        $token = Csrf::token();
        assert_true((bool) preg_match('/^[a-f0-9]{64}$/', $token), 'token format');
        assert_same($token, Csrf::token());
    },

    'verify accepts the token and rejects wrong, empty and array values' => function (): void {
        $token = Csrf::token();
        assert_true(Csrf::verify($token));
        assert_false(Csrf::verify('wrong'));
        assert_false(Csrf::verify(''));
        assert_false(Csrf::verify(null));
        assert_false(Csrf::verify([$token]));
    },

    'the hidden field carries the token' => function (): void {
        assert_contains('name="_token" value="' . Csrf::token() . '"', Csrf::field());
    },
];
