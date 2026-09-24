<?php
declare(strict_types=1);

// Everything the tests touch uses the separate *_test database.
putenv('RETAIL_POS_ENV=test');
$_SESSION = [];

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/support/assert.php';
