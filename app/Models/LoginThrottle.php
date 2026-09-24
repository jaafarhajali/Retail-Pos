<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Login lockout stored in the database, so clearing cookies or opening a
 * new browser does not reset it. Two rules, both over the last LOCK_MINUTES:
 *  - MAX_FAILURES for one username from one IP (since its last success)
 *  - MAX_IP_FAILURES from one IP across all usernames (guessing names)
 */
final class LoginThrottle extends Model
{
    public const MAX_FAILURES = 5;
    public const MAX_IP_FAILURES = 20;
    public const LOCK_MINUTES = 15;

    public function isLocked(string $username, string $ip): bool
    {
        $window = 'created_at > NOW() - INTERVAL ' . self::LOCK_MINUTES . ' MINUTE';

        $userFailures = (int) $this->fetchValue(
            "SELECT COUNT(*) FROM login_attempts
             WHERE username = :u1 AND ip = :i1 AND success = 0 AND {$window}
               AND id > COALESCE((SELECT MAX(id) FROM login_attempts
                                  WHERE username = :u2 AND ip = :i2 AND success = 1), 0)",
            ['u1' => $username, 'i1' => $ip, 'u2' => $username, 'i2' => $ip]
        );
        if ($userFailures >= self::MAX_FAILURES) {
            return true;
        }

        $ipFailures = (int) $this->fetchValue(
            "SELECT COUNT(*) FROM login_attempts WHERE ip = :i AND success = 0 AND {$window}",
            ['i' => $ip]
        );

        return $ipFailures >= self::MAX_IP_FAILURES;
    }

    public function record(string $username, string $ip, bool $success): void
    {
        $this->execute(
            'INSERT INTO login_attempts (username, ip, success) VALUES (:u, :i, :s)',
            ['u' => mb_substr($username, 0, 50), 'i' => mb_substr($ip, 0, 45), 's' => (int) $success]
        );
    }
}
