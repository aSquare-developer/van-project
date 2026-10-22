<?php

namespace App\Listeners;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

class LogAuthenticationEvent
{
    public function __construct(private AuditLogger $logger) {}

    public function record(object $event): void
    {
        $name = match (true) {
            $event instanceof Login => 'auth.login',
            $event instanceof Logout => 'auth.logout',
            $event instanceof Failed => 'auth.failed',
            $event instanceof PasswordReset => 'auth.password_reset',
            $event instanceof Lockout => 'auth.lockout',
            default => null,
        };
        if ($name === null || ($event instanceof Logout && $event->user === null)) {
            return;
        }
        $userId = $event instanceof Login || $event instanceof Logout || $event instanceof PasswordReset
            ? $event->user?->getAuthIdentifier() : null;
        $this->logger->record($name, $userId, $userId);
    }
}
