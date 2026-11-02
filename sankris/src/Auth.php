<?php
declare(strict_types=1);

namespace Sankris;

/**
 * Staff roles on top of GYM One's workers table.
 * GYM One bosses (is_boss = 1) are admins unless a role says otherwise.
 */
final class Auth
{
    public const ROLES = ['admin' => 'Administración', 'recepcion' => 'Recepción', 'coach' => 'Coach'];

    /** Permission => roles allowed. Kept in one place so it is easy to review. */
    private const PERMISSIONS = [
        'families.view' => ['admin', 'recepcion', 'coach'],
        'families.edit' => ['admin', 'recepcion'],
        'students.medical' => ['admin', 'coach'],
        'plans.edit' => ['admin'],
        'subscriptions.view' => ['admin', 'recepcion'],
        'subscriptions.edit' => ['admin', 'recepcion'],
        'subscriptions.price' => ['admin'],
        'schedule.view' => ['admin', 'recepcion', 'coach'],
        'schedule.edit' => ['admin'],
        'bookings.edit' => ['admin', 'recepcion'],
        'attendance.mark' => ['admin', 'recepcion', 'coach'],
        'billing.view' => ['admin', 'recepcion'],
        'billing.edit' => ['admin', 'recepcion'],
        'billing.refund' => ['admin'],
        'billing.void' => ['admin'],
        'settings.edit' => ['admin'],
        'staff.edit' => ['admin'],
        'audit.view' => ['admin'],
    ];

    private ?array $worker;
    private string $role;

    public function __construct(?array $worker, string $role)
    {
        $this->worker = $worker;
        $this->role = $role;
    }

    public static function fromSession(Db $db): self
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $id = $_SESSION['adminuser'] ?? null;
        if ($id === null) {
            return new self(null, 'none');
        }
        $worker = $db->one('SELECT userid, firstname, lastname, username, is_boss FROM workers WHERE userid = ?', [$id]);
        if ($worker === null) {
            return new self(null, 'none');
        }
        $role = $db->value('SELECT role FROM sk_staff_roles WHERE worker_id = ?', [$id]);
        if ($role === null) {
            $role = ((int) $worker['is_boss'] === 1) ? 'admin' : 'recepcion';
        }
        return new self($worker, (string) $role);
    }

    public function loggedIn(): bool
    {
        return $this->worker !== null;
    }

    public function id(): ?int
    {
        return $this->worker ? (int) $this->worker['userid'] : null;
    }

    public function name(): string
    {
        return $this->worker ? trim($this->worker['firstname'] . ' ' . $this->worker['lastname']) : '';
    }

    public function role(): string
    {
        return $this->role;
    }

    public function can(string $permission): bool
    {
        return in_array($this->role, self::PERMISSIONS[$permission] ?? [], true);
    }

    public static function roleCan(string $role, string $permission): bool
    {
        return in_array($role, self::PERMISSIONS[$permission] ?? [], true);
    }
}
