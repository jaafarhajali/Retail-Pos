<?php
declare(strict_types=1);

namespace App\Core;

/** Base controller: rendering, typed input, and the redirect-back-with-errors pattern. */
abstract class Controller
{
    protected function render(string $view, array $data = [], string $title = ''): void
    {
        View::render($view, $data + ['pageTitle' => $title !== '' ? $title : APP_NAME]);
    }

    /** Trimmed POST string; '' when missing or tampered (sent as an array). */
    protected function input(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /** Untrimmed POST string, for passwords. */
    protected function rawInput(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    protected function inputInt(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return preg_match('/^-?\d+$/', $value) ? (int) $value : $default;
    }

    protected function query(string $key): string
    {
        $value = $_GET[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    protected function queryInt(string $key, int $default = 0): int
    {
        $value = $this->query($key);

        return preg_match('/^-?\d+$/', $value) ? (int) $value : $default;
    }

    /** JSON reply for the till's fetch() calls. */
    protected function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** The JSON body of a fetch() POST (empty array when absent or malformed). */
    protected function jsonInput(): array
    {
        $raw = (string) file_get_contents('php://input');
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /** Keep the form input (minus secrets) and errors, flash the first error, go back. */
    protected function failBack(string $route, array $params, array $errors): never
    {
        $old = $_POST;
        foreach (array_keys($old) as $key) {
            if ($key === '_token' || $key === 'pin' || str_contains((string) $key, 'password')) {
                unset($old[$key]);
            }
        }
        $_SESSION['_old'] = $old;
        $_SESSION['_errors'] = $errors;
        Flash::set('danger', (string) reset($errors));
        redirect($route, $params);
    }
}
