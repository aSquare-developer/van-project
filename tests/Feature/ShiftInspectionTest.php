<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Models\InspectionTemplate;
use App\Models\InspectionTemplateItem;
use App\Models\Shift;
use App\Models\ShiftCheck;
use App\Models\User;
use App\Models\Van;
use App\Services\ShiftAssigner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiftInspectionTest extends TestCase
{
    use RefreshDatabase;

    private function assignment(array $overrides = []): array
    {
        $template = InspectionTemplate::factory()->create();
        InspectionTemplateItem::factory()->create(['inspection_template_id' => $template->id]);

        return [
            'title' => 'Friday shift',
            'shift_date' => '2026-10-23',
            'worker_id' => User::factory()->create()->id,
            'van_id' => Van::factory()->create()->id,
            'inspection_template_id' => $template->id,
            ...$overrides,
        ];
    }

    public function test_assignment_snapshots_ordered_items_and_separates_each_workers_results(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $data = $this->assignment();
        $template = InspectionTemplate::findOrFail($data['inspection_template_id']);
        InspectionTemplateItem::factory()->create(['inspection_template_id' => $template->id, 'position' => 2, 'title' => 'Second holder', 'is_required' => false]);
        $assigner = app(ShiftAssigner::class);
        $first = $assigner->assign($admin, [...$data, 'status' => 'completed', 'assigned_by' => $data['worker_id']]);
        $second = $assigner->assign($admin, [...$data, 'worker_id' => User::factory()->create()->id]);

        $this->assertSame(ShiftStatus::Assigned, $first->status);
        $this->assertSame($admin->id, $first->assigned_by);
        $this->assertSame([1, 2], $first->checks->pluck('position')->all());
        $this->assertTrue($first->checks[0]->is_required);
        $this->assertFalse($first->checks[1]->is_required);
        $this->assertSame(CheckStatus::Pending, $first->checks[0]->status);
        $this->assertNotSame($first->checks[0]->id, $second->checks[0]->id);
        $first->checks[0]->forceFill(['status' => CheckStatus::Passed])->save();
        $this->assertSame(CheckStatus::Pending, $second->checks[0]->fresh()->status);

        $template->update(['name' => 'Changed template']);
        $template->items()->update(['instructions' => 'Changed instruction', 'expected_class' => 'changed']);
        $template->items()->delete();
        $first->van->update(['name' => 'Changed van', 'registration' => 'CHANGED']);
        $snapshot = $first->fresh('checks');
        $this->assertSame('End of shift inspection', $snapshot->template_name);
        $this->assertNotSame('Changed van', $snapshot->van_name);
        $this->assertNotSame('CHANGED', $snapshot->van_registration);
        $this->assertSame('Photograph the complete cartridge holder.', $snapshot->checks[0]->instructions);
        $this->assertSame('holder_stocked', $snapshot->checks[0]->expected_class);
        $this->assertDatabaseHas('activity_logs', ['event' => 'shift.assigned', 'actor_id' => $admin->id, 'subject_id' => $data['worker_id']]);
    }

    public function test_workers_only_see_their_shifts_and_admin_sees_all(): void
    {
        $check = ShiftCheck::factory()->create();
        $own = $check->shift;
        $other = Shift::factory()->create();
        $worker = $own->worker;
        $admin = $own->assignedBy;
        $moderator = User::factory()->create(['role' => Role::Moderator]);
        $this->assertSame([$own->id], Shift::visibleTo($worker)->pluck('id')->all());
        $this->assertSame(2, Shift::visibleTo($admin)->count());
        $this->assertSame(0, Shift::visibleTo($moderator)->count());
        $this->assertTrue(Gate::forUser($worker)->allows('view', $own));
        $this->assertTrue(Gate::forUser($worker)->allows('view', $check));
        $this->assertFalse(Gate::forUser($other->worker)->allows('view', $check));
        $this->assertFalse(Gate::forUser($worker)->allows('view', $other));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $other));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $check));
        $this->assertFalse(Gate::forUser($moderator)->allows('view', $own));
        $worker->forceFill(['is_active' => false])->save();
        $this->assertFalse(Gate::forUser($worker)->allows('view', $own));
        $this->assertSame(0, Shift::visibleTo($worker)->count());
    }

    public function test_only_active_admins_manage_templates_vans_and_assignments(): void
    {
        $van = Van::factory()->create();
        $template = InspectionTemplate::factory()->create();
        foreach ([Role::User, Role::Moderator, Role::Admin] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            foreach ([Van::class, InspectionTemplate::class, Shift::class] as $model) {
                $this->assertSame($role === Role::Admin, Gate::forUser($actor)->allows('create', $model));
            }
            $this->assertSame($role === Role::Admin, Gate::forUser($actor)->allows('update', $van));
            $this->assertSame($role === Role::Admin, Gate::forUser($actor)->allows('update', $template));
            $this->assertFalse(Gate::forUser($actor)->allows('delete', $van));
        }
        $admin = User::factory()->create(['role' => Role::Admin, 'is_active' => false]);
        $this->assertFalse(Gate::forUser($admin)->allows('create', Shift::class));
    }

    public function test_assignment_rechecks_actor_authorization_inside_transaction(): void
    {
        $actor = User::factory()->create(['role' => Role::Admin]);
        $data = $this->assignment();
        User::whereKey($actor->id)->update(['is_active' => false]);
        try {
            app(ShiftAssigner::class)->assign($actor, $data);
            $this->fail('Inactive administrator assigned a shift.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('shifts', 0);
            $this->assertDatabaseCount('shift_checks', 0);
        }
        foreach ([Role::User, Role::Moderator] as $role) {
            try {
                app(ShiftAssigner::class)->assign(User::factory()->create(['role' => $role]), $data);
                $this->fail('Non-admin assigned a shift.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('shifts', 0);
            }
        }
    }

    public function test_assignment_rejects_inactive_or_non_worker_accounts(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $data = $this->assignment();
        foreach ([['is_active' => false], ['role' => Role::Moderator], ['role' => Role::Admin]] as $attributes) {
            $worker = User::factory()->create($attributes);
            try {
                app(ShiftAssigner::class)->assign($admin, [...$data, 'worker_id' => $worker->id]);
                $this->fail('Invalid worker accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('worker_id', $exception->errors());
                $this->assertDatabaseCount('shifts', 0);
            }
        }
    }

    public function test_invalid_or_unsupported_templates_cannot_create_partial_assignments(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $data = $this->assignment();
        $template = InspectionTemplate::findOrFail($data['inspection_template_id']);
        foreach (['unsupported_key', 'wrong_class', 'empty', 'inactive'] as $scenario) {
            if ($scenario === 'unsupported_key') {
                $template->items()->update(['check_key' => 'arbitrary_service']);
            } elseif ($scenario === 'wrong_class') {
                $template->items()->update(['check_key' => 'silicone_holder', 'expected_class' => 'holder_empty']);
            } elseif ($scenario === 'empty') {
                $template->items()->delete();
            } else {
                $template->update(['is_active' => false]);
            }
            try {
                app(ShiftAssigner::class)->assign($admin, $data);
                $this->fail('Invalid template accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('shifts', 0);
                $this->assertDatabaseCount('shift_checks', 0);
                $this->assertDatabaseMissing('activity_logs', ['event' => 'shift.assigned']);
            }
        }
    }

    public function test_assignment_rejects_inactive_van_and_invalid_date_or_missing_records(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $data = $this->assignment();
        Van::whereKey($data['van_id'])->update(['is_active' => false]);
        foreach ([$data, [...$data, 'shift_date' => '2026-02-31'], [...$data, 'worker_id' => 999999]] as $invalid) {
            try {
                app(ShiftAssigner::class)->assign($admin, $invalid);
                $this->fail('Invalid assignment accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('shifts', 0);
            }
        }
    }

    public function test_failure_while_copying_items_rolls_back_shift_checks_and_audit(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $data = $this->assignment();
        InspectionTemplateItem::factory()->create(['inspection_template_id' => $data['inspection_template_id'], 'position' => 2]);
        ShiftCheck::creating(function (ShiftCheck $check): void {
            if ($check->position === 2) {
                throw new \RuntimeException('Simulated snapshot failure');
            }
        });
        try {
            app(ShiftAssigner::class)->assign($admin, $data);
            $this->fail('Expected simulated failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated snapshot failure', $exception->getMessage());
            $this->assertDatabaseCount('shifts', 0);
            $this->assertDatabaseCount('shift_checks', 0);
            $this->assertDatabaseMissing('activity_logs', ['event' => 'shift.assigned']);
        } finally {
            ShiftCheck::flushEventListeners();
        }
    }

    public function test_worker_with_shift_history_cannot_be_deleted_but_can_be_blocked(): void
    {
        $shift = Shift::factory()->create();
        $this->signIn($shift->assignedBy)->delete('/admin/users/'.$shift->worker_id)->assertSessionHasErrors('user');
        $this->assertDatabaseHas('users', ['id' => $shift->worker_id]);
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->put('/admin/users/'.$shift->worker_id, [
            'name' => $shift->worker->name, 'email' => $shift->worker->email, 'role' => 'user', 'is_active' => '0',
        ])->assertRedirect('/admin/users');
        $this->assertFalse($shift->worker->fresh()->is_active);
    }

    public function test_foreign_keys_preserve_shift_history(): void
    {
        $shift = Shift::factory()->create();
        $this->expectException(QueryException::class);
        $shift->van->delete();
    }
}
