<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Models\Register;

/** POS register rules. Throws \DomainException carrying the message to show. */
final class RegisterService
{
    private Register $registers;

    public function __construct()
    {
        $this->registers = new Register();
    }

    public function create(string $name): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 50) {
            throw new \DomainException('Register name must be 1–50 characters.');
        }
        if ($this->registers->nameExists($name)) {
            throw new \DomainException('A register with that name already exists.');
        }
        $id = $this->registers->create($name);
        Audit::log('register.created', 'register', $id, ['name' => $name]);

        return $id;
    }

    /**
     * Link the current browser to the register and return the new raw token
     * (the controller stores it in a cookie). A device linked earlier stops
     * being this register.
     */
    public function bindThisDevice(int $id): string
    {
        $register = $this->registers->find($id) ?? throw new \DomainException('Register not found.');
        if ((int) $register['is_active'] !== 1) {
            throw new \DomainException('This register is deactivated.');
        }
        $token = bin2hex(random_bytes(32));
        $this->registers->bind($id, hash('sha256', $token));
        Audit::log('register.bound', 'register', $id, ['name' => $register['name']]);

        return $token;
    }

    public function deactivate(int $id): void
    {
        $register = $this->registers->find($id) ?? throw new \DomainException('Register not found.');
        $this->registers->deactivate($id);
        Audit::log('register.deactivated', 'register', $id, ['name' => $register['name']]);
    }
}
