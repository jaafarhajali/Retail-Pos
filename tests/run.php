<?php
/**
 * Minimal test runner (no Composer).
 *   php tests/run.php            run every tests/*Test.php
 *   php tests/run.php Auth       run only files whose name contains "Auth"
 * Each test file returns [name => callable]; an optional '__before' entry
 * runs before every test of that file.
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$passed = 0;
$failed = 0;
foreach ($files as $file) {
    $suite = basename($file, '.php');
    if ($filter !== '' && stripos($suite, $filter) === false) {
        continue;
    }
    // Load the file in its own scope so its variables cannot clobber the runner's.
    $tests = (static fn (string $path): array => require $path)($file);
    $before = $tests['__before'] ?? null;
    unset($tests['__before']);
    foreach ($tests as $name => $test) {
        try {
            if ($before !== null) {
                $before();
            }
            $test();
            $passed++;
            echo "  PASS  {$suite} :: {$name}\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  FAIL  {$suite} :: {$name}\n        " . get_class($e) . ': ' . $e->getMessage()
               . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
        }
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
