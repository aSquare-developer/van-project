<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\InspectionPhoto;
use App\Models\Shift;
use App\Models\ShiftTransition;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserManager
{
    public function __construct(private AuditLogger $audit) {}

    private function synchronized(Closure $operation): mixed
    {
        return DB::transaction(function () use ($operation): mixed {
            DB::table('administration_lock')->where('id', 1)->lockForUpdate()->firstOrFail();

            return $operation();
        }, 3);
    }

    private function authorizeActor(User $actor): void
    {
        $current = User::query()->lockForUpdate()->find($actor->id);
        abort_unless($current?->is_active && $current->role === Role::Admin, 403);
    }

    public function create(User $actor, array $data): User
    {
        return $this->synchronized(function () use ($actor, $data): User {
            $this->authorizeActor($actor);

            $user = $this->insert($data);
            $this->audit->record('user.created', $actor->id, $user->id);

            return $user;
        });
    }

    public function bootstrap(array $data): User
    {
        return $this->synchronized(function () use ($data): User {
            if (User::query()->where('role', Role::Admin)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['email' => 'An active administrator already exists. Use the administration panel.']);
            }

            $user = $this->insert([...$data, 'role' => Role::Admin->value, 'is_active' => true]);
            $this->audit->record('admin.bootstrapped', null, $user->id);

            return $user;
        });
    }

    private function insert(array $data): User
    {
        $user = new User;
        $user->forceFill([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => $data['password'],
            'role' => $data['role'],
            'is_active' => (bool) $data['is_active'],
        ])->save();

        return $user;
    }

    public function update(User $actor, int $subjectId, array $data): User
    {
        return $this->synchronized(function () use ($actor, $subjectId, $data): User {
            $this->authorizeActor($actor);
            $subject = User::query()->lockForUpdate()->findOrFail($subjectId);
            $active = (bool) $data['is_active'];
            $role = Role::from($data['role']);
            $this->protectAdministrator($actor, $subject, ! $active, $active && $role === Role::Admin);
            $email = strtolower($data['email']);
            $revoke = $subject->role !== $role || $subject->is_active !== $active || $subject->email !== $email || ! empty($data['password']);
            $oldEmail = $subject->email;
            $oldRole = $subject->role;
            $oldActive = $subject->is_active;
            $subject->forceFill(['name' => $data['name'], 'email' => $email, 'role' => $role, 'is_active' => $active]);
            if (! empty($data['password'])) {
                $subject->password = $data['password'];
            }
            if ($revoke) {
                $subject->session_version++;
                $subject->remember_token = Str::random(60);
                DB::table('sessions')->where('user_id', $subject->id)->delete();
                DB::table('password_reset_tokens')->where('email', $oldEmail)->delete();
            }
            $subject->save();
            $this->audit->record('user.updated', $actor->id, $subject->id, array_keys($subject->getChanges()));
            if ($oldRole !== $role) {
                $this->audit->record('user.role_changed', $actor->id, $subject->id, ['role']);
            }
            if ($oldActive !== $active) {
                $this->audit->record($active ? 'user.unblocked' : 'user.blocked', $actor->id, $subject->id, ['is_active']);
            }

            return $subject;
        });
    }

    public function delete(User $actor, int $subjectId): void
    {
        $this->synchronized(function () use ($actor, $subjectId): void {
            $this->authorizeActor($actor);
            $subject = User::query()->lockForUpdate()->findOrFail($subjectId);
            $this->protectAdministrator($actor, $subject, true, false);
            if (Shift::withTrashed()->where('worker_id', $subject->id)->orWhere('assigned_by', $subject->id)->exists()
                || InspectionPhoto::query()->where('uploaded_by', $subject->id)->exists()
                || ShiftTransition::query()->where('actor_id', $subject->id)->exists()) {
                throw ValidationException::withMessages(['user' => 'This account has inspection or shift history. Block the account instead of deleting it.']);
            }
            DB::table('sessions')->where('user_id', $subject->id)->delete();
            DB::table('password_reset_tokens')->where('email', $subject->email)->delete();
            $this->audit->record('user.deleted', $actor->id, $subject->id);
            $subject->delete();
        });
    }

    private function protectAdministrator(User $actor, User $subject, bool $disabling, bool $remainsAdmin): void
    {
        if ($actor->id === $subject->id && $disabling) {
            throw ValidationException::withMessages(['user' => 'You cannot block or delete your own account.']);
        }
        if ($subject->is_active && $subject->role === Role::Admin && ! $remainsAdmin
            && User::query()->where('role', Role::Admin)->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'At least one active administrator must remain.']);
        }
    }
}
