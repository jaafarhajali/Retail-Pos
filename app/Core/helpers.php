<?php
/** Global helpers for controllers and views. */
declare(strict_types=1);

/** HTML-escape anything for output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Internal URL: url('users/edit', ['id' => 5]) → index.php?r=users/edit&id=5 */
function url(string $route, array $params = []): string
{
    return 'index.php?r=' . $route . ($params === [] ? '' : '&' . http_build_query($params));
}

/** The current URL with some query parameters replaced (pagination, filters). */
function url_with(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    $route = is_string($params['r'] ?? null) ? $params['r'] : 'dashboard';
    unset($params['r']);

    return url($route, $params);
}

function redirect(string $route, array $params = []): never
{
    header('Location: ' . url($route, $params));
    exit;
}

function setting(string $key, string $default = ''): string
{
    return \App\Core\Settings::get($key, $default);
}

/** $1,234.50 — every USD amount on screen goes through this. */
function usd(float|int|string|null $amount): string
{
    $value = (float) ($amount ?? 0);

    return ($value < 0 ? '-$' : '$') . number_format(abs($value), 2);
}

/** 1,234,000 LBP — whole lira only. */
function lbp(float|int|string|null $amount): string
{
    return number_format((float) ($amount ?? 0), 0) . ' LBP';
}

function csrf_field(): string
{
    return \App\Core\Csrf::field();
}

/** A previously submitted value after a validation error (already escaped). */
function old(string $key, mixed $default = ''): string
{
    $value = $_SESSION['_old'][$key] ?? $default;

    return e(is_scalar($value) || $value === null ? $value : '');
}

function form_error(string $key): string
{
    return e($_SESSION['_errors'][$key] ?? '');
}

function clear_form_stash(): void
{
    unset($_SESSION['_old'], $_SESSION['_errors']);
}

/** The client address as seen by this server. Proxy headers are not trusted. */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    return is_string($ip) && $ip !== '' ? $ip : 'cli';
}
