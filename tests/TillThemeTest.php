<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Models\Register;

/**
 * The till has a dark look (the default) and a light one. The choice belongs to the terminal:
 * it is kept in the browser (localStorage "pos.theme"), never in the database.
 */
$openTill = static function (): string {
    $registerId = (new Register())->create('Register 01');
    $client = login_as('admin', TEST_ADMIN_PASSWORD);
    $client->get('registers');
    $client->post('registers/bind', ['id' => $registerId]);
    $client->get('sessions/open');
    assert_same(302, $client->post('sessions/open', ['opening_usd' => '100', 'opening_lbp' => '0'])->status);
    $pos = $client->get('pos');
    assert_same(200, $pos->status);
    assert_contains('pos-body', $pos->body, 'the till renders, not the blocked page');

    return $pos->body;
};

return [
    '__before' => 'test_db_reset',

    'the till has one button in its top bar to switch between light and dark' => function () use ($openTill): void {
        $till = $openTill();
        assert_same(1, preg_match('~<nav class="pos-links".*?</nav>~s', $till, $nav), 'the top bar links');
        assert_contains('<button type="button" class="pos-theme" id="btn-theme"', $nav[0], 'a button, not a link, next to the other top-bar actions');
        assert_contains('aria-label="Switch to light mode"', $nav[0], 'dark is the default, so the button offers light');
        assert_contains('bi-sun', $nav[0]);
        assert_contains('>Light</span>', $nav[0], 'the label says what a press does while the till is dark');
        assert_contains('bi-moon-stars', $nav[0]);
        assert_contains('>Dark</span>', $nav[0], 'and what it does while the till is light');
    },

    'dark stays the default and the saved choice is applied before the first paint' => function () use ($openTill): void {
        $till = $openTill();
        assert_contains('<body class="pos-body">', $till, 'no theme is forced by the server: dark unless this terminal chose light');
        assert_same(1, preg_match('~<body class="pos-body">\s*<script>(.*?)</script>~s', $till, $m), 'an inline script right after <body> opens');
        assert_contains("localStorage.getItem('pos.theme')", $m[1]);
        assert_contains("'light'", $m[1]);
        assert_contains('data-theme', $m[1]);
        assert_contains('try {', $m[1], 'a browser without storage keeps the dark till instead of an error');
        assert_true(strpos($till, $m[0]) < strpos($till, 'class="pos"'), 'before anything of the till is drawn');
    },

    'the stylesheet has the light theme and the till script remembers the choice' => function (): void {
        $css = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        assert_contains('.pos-body[data-theme="light"] {', $css, 'the light theme is one block of tokens');
        $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/pos.js');
        assert_contains("'pos.theme'", $js, 'the storage key');
        assert_contains('localStorage.setItem(THEME', $js);
        assert_contains("'Switch to '", $js, 'the button says what the next press does');
        assert_contains("\$('search').focus()", $js, 'a press gives the focus back to the scan field');
    },

    'the theme is a screen preference: no route and no setting' => function (): void {
        $routes = (string) file_get_contents(APP_PATH . '/routes.php');
        assert_not_contains('theme', strtolower($routes));
    },
];
