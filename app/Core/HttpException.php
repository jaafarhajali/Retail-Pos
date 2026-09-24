<?php
declare(strict_types=1);

namespace App\Core;

/** Ends a request with an HTTP error page (403, 404, 405, 419, 400). */
final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message, $status);
    }
}
