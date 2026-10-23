<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\ShiftCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerShiftChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_lists_only_own_shifts_with_stable_order_and_pagination(): void
    {
        $worker = User::factory()->create();
        $other = Shift::factory()->create(['title' => 'Other workers private shift']);
        $older = Shift::factory()->create(['worker_id' => $worker->id, 'shift_date' => '2026-10-01']);
        $latest = Shift::factory()->create(['worker_id' => $worker->id, 'title' => 'My Friday shift', 'shift_date' => '2026-10-23']);
        $this->signIn($worker)->get('/shifts')->assertOk()->assertSee('My Friday shift')
            ->assertDontSee($other->title)->assertDontSee($other->worker->email)
            ->assertViewHas('shifts', fn ($shifts) => $shifts->pluck('id')->all() === [$latest->id, $older->id] && $shifts->total() === 2);
        Shift::factory()->count(19)->create(['worker_id' => $worker->id, 'shift_date' => '2026-09-01']);
        $this->get('/shifts')->assertOk()->assertViewHas('shifts', fn ($shifts) => $shifts->count() === 20 && $shifts->total() === 21);
        $this->get('/shifts?page=2')->assertOk()->assertViewHas('shifts', fn ($shifts) => $shifts->count() === 1 && $shifts->total() === 21)->assertDontSee($other->title);
    }

    public function test_worker_can_open_own_shift_and_ordered_item_instructions(): void
    {
        $shift = Shift::factory()->create(['title' => 'Personal shift', 'van_name' => 'Snapshot van']);
        $second = ShiftCheck::factory()->create(['shift_id' => $shift->id, 'position' => 2, 'title' => 'Second item', 'is_required' => false]);
        $first = ShiftCheck::factory()->create(['shift_id' => $shift->id, 'position' => 1, 'title' => 'First item', 'instructions' => "Photograph the entire holder.\nKeep the slots visible."]);
        $this->signIn($shift->worker)->get('/shifts/'.$shift->id)->assertOk()->assertSeeInOrder(['1. First item', '2. Second item'])->assertSee('Optional')
            ->assertSee(route('shifts.checks.show', [$shift, $first]), false)->assertSee('0 of 1 required checks passed');
        $this->get('/shifts/'.$shift->id.'/checks/'.$first->id)->assertOk()->assertSee('Photograph the entire holder.')->assertSee('Keep the slots visible.')
            ->assertSee('Required check')->assertSee('Snapshot van')->assertSee('This item has not been checked yet.')
            ->assertDontSee('holder_stocked')->assertDontSee('silicone_holder');
        $this->get('/shifts/'.$shift->id.'/checks/'.$second->id)->assertOk()->assertSee('Optional check');
    }

    public function test_foreign_shifts_and_items_are_denied_even_with_guessed_ids(): void
    {
        $check = ShiftCheck::factory()->create(['title' => 'Confidential check', 'instructions' => 'Private instructions.']);
        $otherWorker = User::factory()->create();
        $this->signIn($otherWorker)->get('/shifts/'.$check->shift_id)->assertForbidden()->assertDontSee('Confidential check');
        $this->get('/shifts/'.$check->shift_id.'/checks/'.$check->id)->assertForbidden()->assertDontSee('Private instructions.');
        $this->assertDatabaseHas('shift_checks', ['id' => $check->id, 'status' => 'pending']);
    }

    public function test_nested_binding_rejects_items_from_a_different_shift(): void
    {
        $firstCheck = ShiftCheck::factory()->create();
        $secondShift = Shift::factory()->create(['worker_id' => $firstCheck->shift->worker_id]);
        $secondCheck = ShiftCheck::factory()->create(['shift_id' => $secondShift->id]);
        $this->signIn($firstCheck->shift->worker)->get('/shifts/'.$firstCheck->shift_id.'/checks/'.$secondCheck->id)->assertNotFound();
        $this->get('/shifts/'.$secondShift->id.'/checks/'.$firstCheck->id)->assertNotFound();
        $this->get('/shifts/'.$secondShift->id.'/checks/999999')->assertNotFound();
        $this->get('/shifts/999999')->assertNotFound();
    }

    public function test_admin_can_review_worker_checklist_without_treating_it_as_own_assignment(): void
    {
        $check = ShiftCheck::factory()->create();
        $admin = $check->shift->assignedBy;
        $this->signIn($admin)->get('/admin/shifts/'.$check->shift_id)->assertOk()->assertSee(route('shifts.show', $check->shift), false);
        $this->get('/shifts/'.$check->shift_id)->assertOk()->assertSee('Reviewing the checklist assigned to')->assertSee($check->shift->worker->name);
        $this->get('/shifts/'.$check->shift_id.'/checks/'.$check->id)->assertOk();
        $this->get('/shifts')->assertOk()->assertViewHas('shifts', fn ($shifts) => $shifts->total() === 0);
    }

    public function test_guests_and_moderators_cannot_access_worker_pages(): void
    {
        $check = ShiftCheck::factory()->create();
        $paths = ['/shifts', '/shifts/'.$check->shift_id, '/shifts/'.$check->shift_id.'/checks/'.$check->id];
        foreach ($paths as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->signIn(User::factory()->create(['role' => Role::Moderator]));
        foreach ($paths as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->get('/dashboard')->assertDontSee('My shifts')->assertDontSee('View my shifts');
    }

    public function test_blocking_or_revoking_the_worker_rejects_an_existing_session(): void
    {
        $check = ShiftCheck::factory()->create();
        $worker = $check->shift->worker;
        $this->signIn($worker)->get('/shifts')->assertOk();
        User::whereKey($worker->id)->update(['is_active' => false]);
        $this->get('/shifts/'.$check->shift_id)->assertRedirect('/login');
        User::whereKey($worker->id)->update(['is_active' => true]);
        $this->signIn($worker->fresh());
        User::whereKey($worker->id)->increment('session_version');
        $this->get('/shifts/'.$check->shift_id.'/checks/'.$check->id)->assertRedirect('/login');
    }

    public function test_required_progress_excludes_optional_and_nonpassing_results(): void
    {
        $shift = Shift::factory()->create();
        foreach ([
            [CheckStatus::Passed, true],
            [CheckStatus::Passed, true],
            [CheckStatus::AnalysisError, true],
            [CheckStatus::Passed, false],
            [CheckStatus::Pending, false],
        ] as $index => [$status, $required]) {
            ShiftCheck::factory()->create(['shift_id' => $shift->id, 'position' => $index + 1, 'status' => $status, 'is_required' => $required]);
        }
        $this->signIn($shift->worker)->get('/shifts')->assertOk()->assertSee('2 of 3 required checks passed')
            ->assertViewHas('shifts', fn ($shifts) => $shifts->first()->required_checks_count === 3 && $shifts->first()->passed_required_checks_count === 2);
        $this->get('/shifts/'.$shift->id)->assertOk()->assertSee('2 of 3 required checks passed')->assertViewHas('requiredCount', 3)->assertViewHas('passedCount', 2);
    }

    public function test_all_check_results_have_distinct_and_honest_explanations(): void
    {
        $shift = Shift::factory()->create();
        foreach (CheckStatus::cases() as $index => $status) {
            $check = ShiftCheck::factory()->create(['shift_id' => $shift->id, 'position' => $index + 1, 'status' => $status]);
            $this->signIn($shift->worker)->get('/shifts/'.$shift->id.'/checks/'.$check->id)->assertOk()->assertSee($status->label())->assertSee($status->description());
        }
        $this->get('/shifts/'.$shift->id)->assertOk()->assertSee('Not checked')->assertSee('Passed')->assertSee('Not passed')->assertSee('Unable to verify')->assertSee('Analysis error')
            ->assertSee('This does not mean the item is missing.');
    }

    public function test_zero_required_checks_and_empty_checklists_do_not_complete_shifts(): void
    {
        $shift = Shift::factory()->create();
        $this->signIn($shift->worker)->get('/shifts/'.$shift->id)->assertOk()->assertSee('No required checks')->assertSee('No checklist items are available.');
        ShiftCheck::factory()->create(['shift_id' => $shift->id, 'is_required' => false, 'status' => CheckStatus::Passed]);
        $this->get('/shifts')->assertOk()->assertSee('No required checks');
        $this->get('/shifts/'.$shift->id)->assertOk()->assertSee('No required checks');
        $this->assertSame(ShiftStatus::Assigned, $shift->fresh()->status);
    }

    public function test_reading_pages_or_supplying_status_parameters_cannot_change_results(): void
    {
        $check = ShiftCheck::factory()->create();
        $path = '/shifts/'.$check->shift_id.'/checks/'.$check->id;
        $this->signIn($check->shift->worker)->get($path.'?status=passed&is_required=0')->assertOk()->assertSee('Not checked');
        $this->post($path, ['status' => 'passed'])->assertMethodNotAllowed();
        $this->put('/shifts/'.$check->shift_id, ['status' => 'completed'])->assertMethodNotAllowed();
        $this->delete($path)->assertMethodNotAllowed();
        $this->assertSame(CheckStatus::Pending, $check->fresh()->status);
        $this->assertTrue($check->fresh()->is_required);
        $this->assertSame(ShiftStatus::Assigned, $check->shift->fresh()->status);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_snapshot_instructions_and_titles_are_escaped(): void
    {
        $check = ShiftCheck::factory()->create(['title' => '<script>alert(1)</script>', 'instructions' => '<img src=x onerror=alert(2)>']);
        $this->signIn($check->shift->worker)->get('/shifts/'.$check->shift_id.'/checks/'.$check->id)->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertSee('&lt;img src=x onerror=alert(2)&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x onerror=alert(2)>', false);
    }

    public function test_dashboard_and_both_navigation_surfaces_link_to_my_shifts(): void
    {
        $worker = User::factory()->create();
        $this->signIn($worker)->get('/dashboard')->assertOk()->assertSee('View my shifts')
            ->assertSee('href="'.route('shifts.index').'"', false)->assertSee('Mobile navigation');
        $response = $this->get('/shifts')->assertOk()->assertSee('No shifts assigned')->assertSee('aria-current="page"', false);
        $this->assertSame(2, substr_count($response->getContent(), '>My shifts</a>'));
    }

    public function test_shift_history_shows_saved_status_without_changing_it(): void
    {
        $worker = User::factory()->create();
        foreach (ShiftStatus::cases() as $status) {
            $shift = Shift::factory()->create(['worker_id' => $worker->id, 'status' => $status]);
            $this->signIn($worker)->get('/shifts/'.$shift->id)->assertOk()->assertSee($status->label());
            $this->assertSame($status, $shift->fresh()->status);
        }
    }
}
