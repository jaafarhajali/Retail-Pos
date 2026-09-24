<?php
declare(strict_types=1);

final class AssertionFailed extends Exception
{
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? $message . ' — ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true(bool $condition, string $message = 'expected true'): void
{
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

function assert_false(bool $condition, string $message = 'expected false'): void
{
    if ($condition) {
        throw new AssertionFailed($message);
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(($message !== '' ? $message . ' — ' : '')
            . 'expected to find ' . var_export($needle, true) . ' in ' . var_export(mb_substr($haystack, 0, 300), true));
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionFailed(($message !== '' ? $message . ' — ' : '')
            . 'did not expect to find ' . var_export($needle, true));
    }
}

/** Run $fn and return the exception it throws; fail if it throws nothing or the wrong type. */
function assert_throws(string $class, callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed("expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new AssertionFailed("expected {$class}, nothing was thrown");
}
