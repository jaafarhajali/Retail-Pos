<?php
declare(strict_types=1);

return [
    'e() escapes html and quotes but keeps arabic' => function (): void {
        assert_same('&lt;b&gt; &quot;x&quot; &#039;y&#039; معسل', e('<b> "x" \'y\' معسل'));
        assert_same('', e(null));
    },

    'url() builds query-string routes' => function (): void {
        assert_same('index.php?r=dashboard', url('dashboard'));
        assert_same('index.php?r=users/edit&id=5', url('users/edit', ['id' => 5]));
    },

    'url_with() keeps current filters and replaces some' => function (): void {
        $_GET = ['r' => 'audit', 'action' => 'auth.', 'page' => '2'];
        assert_same('index.php?r=audit&action=auth.&page=3', url_with(['page' => 3]));
        $_GET = [];
    },

    'usd() formats two decimals with thousands separators' => function (): void {
        assert_same('$1,234.50', usd(1234.5));
        assert_same('-$3.00', usd(-3));
        assert_same('$0.00', usd(null));
    },

    'lbp() formats whole lira' => function (): void {
        assert_same('2,410,000 LBP', lbp(2410000));
    },

    'old() returns the stashed value escaped and ignores arrays' => function (): void {
        $_SESSION['_old'] = ['name' => '<x>', 'tampered' => ['a']];
        assert_same('&lt;x&gt;', old('name'));
        assert_same('', old('tampered'));
        assert_same('d', old('missing', 'd'));
        clear_form_stash();
        assert_false(isset($_SESSION['_old']));
    },

    'client_ip() is "cli" outside a web request' => function (): void {
        unset($_SERVER['REMOTE_ADDR']);
        assert_same('cli', client_ip());
    },
];
