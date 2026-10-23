<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\Role;
use App\Enums\ShiftDecision;
use App\Enums\ShiftStatus;
use App\Models\ActivityLog;
use App\Models\InspectionAttempt;
use App\Models\InspectionTemplateItem;
use App\Models\Shift;
use App\Models\ShiftCheck;
use App\Models\ShiftTransition;
use App\Models\User;
use App\Models\Van;
use App\Services\AuditLogger;
use App\Services\InspectionAnalysis;
use App\Services\InspectionPhotoSubmission;
use App\Services\ShiftWorkflow;
use App\Services\UserManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\CreatesWorkflowEvidence;
use Tests\TestCase;

class ShiftWorkflowTest extends TestCase
{
    use CreatesWorkflowEvidence, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('inspection-photos');
        Http::preventStrayRequests();
    }

    private function payload(Shift $shift, ?string $comment = null): array
    {
        return [
            'request_key' => Str::uuid()->toString(), 'revision' => $shift->fresh()->revision,
            ...($comment !== null ? ['comment' => $comment] : []),
        ];
    }

    private function requestReview(InspectionAttempt $attempt, string $comment = 'The holder needs a manual check.'): ShiftTransition
    {
        $shift = $attempt->check->shift;

        return app(ShiftWorkflow::class)->act($shift->worker, $shift, ShiftDecision::RequestReview, $this->payload($shift, $comment));
    }

    public function test_worker_completes_only_confirmed_required_items_and_locks_future_changes(): void
    {
        $attempt = $this->evidence();
        $shift = $attempt->check->shift;
        ShiftCheck::factory()->create(['shift_id' => $shift->id, 'position' => 2, 'is_required' => false]);
        $this->signIn($shift->worker)->get('/shifts/'.$shift->id)->assertOk()->assertSee('Complete inspection');
        $this->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertRedirect(route('shifts.show', $shift));
        $this->assertSame(ShiftStatus::Completed, $shift->refresh()->status);
        $this->assertNotNull($shift->completed_at);
        $transition = ShiftTransition::firstOrFail();
        $this->assertSame(ShiftDecision::Complete, $transition->decision);
        $this->assertSame($attempt->id, $transition->snapshot[0]['attempt_id']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'shift.complete', 'actor_id' => $shift->worker_id]);
        $this->get('/shifts/'.$shift->id)->assertSee('This inspection is closed')->assertSee('Completed by worker')
            ->assertDontSee('action="'.route('shifts.complete', $shift).'"', false);
        $this->post('/shifts/'.$shift->id.'/checks/'.$attempt->shift_check_id.'/photos', [
            'photo' => $this->photo(), 'submission_key' => Str::uuid()->toString(),
        ])->assertForbidden();
        $this->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertSessionHasErrors('shift');
        $this->assertDatabaseCount('shift_transitions', 1);
    }

    public function test_complete_is_idempotent_but_cannot_reuse_action_key_for_changed_payload(): void
    {
        $attempt = $this->evidence();
        $shift = $attempt->check->shift;
        $data = $this->payload($shift);
        $this->signIn($shift->worker);
        for ($i = 0; $i < 2; $i++) {
            $this->post('/shifts/'.$shift->id.'/complete', $data)->assertRedirect();
        }
        $this->assertDatabaseCount('shift_transitions', 1);
        $this->post('/shifts/'.$shift->id.'/review-request', [...$data, 'comment' => 'Different action'])->assertSessionHasErrors('request_key');
        $this->assertSame($data['revision'] + 1, $shift->fresh()->revision);
    }

    public function test_nonpassing_checks_are_not_directly_completable(): void
    {
        foreach ([CheckStatus::Failed, CheckStatus::Inconclusive, CheckStatus::AnalysisError] as $status) {
            $attempt = $this->evidence(result: $status);
            $shift = $attempt->check->shift;
            $this->signIn($shift->worker)->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertSessionHasErrors('shift');
            $this->get('/shifts/'.$shift->id)->assertSee('Send for administrator review');
            $this->assertSame(ShiftStatus::InProgress, $shift->fresh()->status);
        }
    }

    public function test_pending_unscanned_or_empty_required_checklists_cannot_finish_or_request_review(): void
    {
        $empty = Shift::factory()->create();
        $optional = Shift::factory()->create();
        ShiftCheck::factory()->create(['shift_id' => $optional->id, 'is_required' => false]);
        $unscanned = ShiftCheck::factory()->create()->shift;
        $pending = ShiftCheck::factory()->create();
        app(InspectionPhotoSubmission::class)->submit($pending->shift->worker, $pending, $this->photo(), Str::uuid()->toString());
        foreach ([$empty, $optional, $unscanned, $pending->shift] as $shift) {
            $this->signIn($shift->worker)->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertSessionHasErrors('shift');
            $this->post('/shifts/'.$shift->id.'/review-request', $this->payload($shift, 'Please inspect'))->assertSessionHasErrors('shift');
        }
        $this->assertDatabaseCount('shift_transitions', 0);
    }

    public function test_cached_passed_flag_or_forged_attempt_metadata_is_not_valid_evidence(): void
    {
        $check = ShiftCheck::factory()->create(['status' => CheckStatus::Passed]);
        $shift = $check->shift;
        $this->signIn($shift->worker)->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertSessionHasErrors('shift');
        foreach ([
            ['model_version' => null], ['confidence' => 0.1], ['detected_class' => 'wrong'],
            ['check_key' => 'wrong'], ['analysed_at' => null], ['result' => 'failed'], ['status' => 'error'],
        ] as $invalid) {
            $attempt = $this->evidence($check);
            $attempt->forceFill($invalid)->save();
            $this->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertSessionHasErrors('shift');
        }
        $this->assertDatabaseCount('shift_transitions', 0);
    }

    public function test_latest_attempt_must_pass_and_old_success_does_not_count(): void
    {
        $old = $this->evidence();
        $new = $this->evidence($old->check, CheckStatus::Failed);
        $shift = $new->check->shift;
        $new->check->forceFill(['status' => CheckStatus::Passed])->save();
        $this->signIn($shift->worker)->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertSessionHasErrors('shift');
        $this->assertSame('passed', $old->refresh()->result);
        $this->assertSame('failed', $new->refresh()->result);
    }

    public function test_missing_or_purged_photo_cannot_be_submitted_as_evidence(): void
    {
        $attempt = $this->evidence();
        $shift = $attempt->check->shift;
        Storage::disk('inspection-photos')->delete($attempt->photo->path);
        $this->signIn($shift->worker)->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertSessionHasErrors('shift');
        $attempt = $this->evidence(result: CheckStatus::Failed);
        $shift = $attempt->check->shift;
        $attempt->photo->forceFill(['purged_at' => now()])->save();
        $this->signIn($shift->worker)->post('/shifts/'.$shift->id.'/review-request', $this->payload($shift, 'Manual review'))->assertSessionHasErrors('shift');
    }

    public function test_optional_failure_does_not_block_completion_but_pending_optional_photo_does(): void
    {
        $attempt = $this->evidence();
        $shift = $attempt->check->shift;
        $optional = ShiftCheck::factory()->create(['shift_id' => $shift->id, 'position' => 2, 'is_required' => false]);
        app(InspectionPhotoSubmission::class)->submit($shift->worker, $optional, $this->photo(), Str::uuid()->toString());
        $this->signIn($shift->worker)->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertSessionHasErrors('shift');
        $this->evidence($optional, CheckStatus::Failed);
        $this->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertRedirect();
        $this->assertSame(ShiftStatus::Completed, $shift->fresh()->status);
    }

    public function test_worker_requests_review_with_comment_and_admin_approves_without_overwriting_recognition(): void
    {
        $attempt = $this->evidence(result: CheckStatus::Failed);
        $shift = $attempt->check->shift;
        $data = $this->payload($shift, 'Cartridges are in a different compartment.');
        $this->signIn($shift->worker)->post('/shifts/'.$shift->id.'/review-request', $data)->assertRedirect();
        $this->post('/shifts/'.$shift->id.'/review-request', $data)->assertRedirect();
        $this->assertSame(ShiftStatus::AwaitingReview, $shift->refresh()->status);
        $this->get('/shifts/'.$shift->id)->assertSee('Waiting for an administrator')->assertSee($data['comment']);
        $this->post('/shifts/'.$shift->id.'/checks/'.$attempt->shift_check_id.'/photos', [
            'photo' => $this->photo(), 'submission_key' => Str::uuid()->toString(),
        ])->assertForbidden();
        $adminData = [...$this->payload($shift, 'Accepted after inspecting the photo.'), 'decision' => 'approve_with_remarks'];
        $this->signIn($shift->assignedBy)->get('/admin/shifts/'.$shift->id)->assertOk()->assertSee('Administrator decision')
            ->assertSee($data['comment'])->assertSee(route('shifts.checks.attempts.photo', [$shift, $attempt->shift_check_id, $attempt]), false);
        for ($i = 0; $i < 2; $i++) {
            $this->post('/admin/shifts/'.$shift->id.'/review', $adminData)->assertRedirect();
        }
        $this->assertSame(ShiftStatus::CompletedWithRemarks, $shift->refresh()->status);
        $this->assertNotNull($shift->completed_at);
        $this->assertSame(CheckStatus::Failed, $attempt->check->fresh()->status);
        $this->assertSame('failed', $attempt->fresh()->result);
        $this->assertDatabaseCount('shift_transitions', 2);
        $this->assertDatabaseHas('activity_logs', ['event' => 'shift.approve_with_remarks']);
        $this->get('/admin/shifts/'.$shift->id)->assertDontSee('action="'.route('admin.shifts.review', $shift).'"', false);
    }

    public function test_returned_inspection_can_be_rescanned_and_completed_with_original_evidence_preserved(): void
    {
        $old = $this->evidence(result: CheckStatus::Inconclusive);
        $shift = $old->check->shift;
        $this->requestReview($old);
        $this->signIn($shift->assignedBy)->post('/admin/shifts/'.$shift->id.'/review', [
            ...$this->payload($shift, 'Photograph the full holder, not the floor.'), 'decision' => 'return_for_check',
        ])->assertRedirect();
        $this->assertSame(ShiftStatus::ReturnedForCheck, $shift->refresh()->status);
        $this->assertNull($shift->completed_at);
        $this->signIn($shift->worker)->get('/shifts/'.$shift->id)->assertSee('Photograph the full holder, not the floor.');
        $new = $this->evidence($old->check);
        $this->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertRedirect();
        $this->assertSame(ShiftStatus::Completed, $shift->refresh()->status);
        $this->assertDatabaseCount('shift_transitions', 3);
        $this->assertDatabaseCount('inspection_attempts', 2);
        $history = $shift->transitions()->oldest('id')->get();
        $this->assertSame($old->id, $history[0]->snapshot[0]['attempt_id']);
        $this->assertSame($old->id, $history[1]->snapshot[0]['attempt_id']);
        $this->assertSame($new->id, $history[2]->snapshot[0]['attempt_id']);
    }

    public function test_worker_cannot_use_review_to_bypass_passing_required_items_or_mandatory_comment(): void
    {
        $attempt = $this->evidence();
        $shift = $attempt->check->shift;
        $this->signIn($shift->worker)->post('/shifts/'.$shift->id.'/review-request', $this->payload($shift, 'All passed'))->assertSessionHasErrors('shift');
        $attempt = $this->evidence($attempt->check, CheckStatus::AnalysisError);
        foreach ([null, '', '   ', str_repeat('x', 2001)] as $comment) {
            $this->post('/shifts/'.$shift->id.'/review-request', $this->payload($shift, $comment))->assertSessionHasErrors('comment');
        }
        $this->assertDatabaseCount('shift_transitions', 0);
    }

    public function test_unavailable_model_can_be_reviewed_but_never_normal_completed(): void
    {
        $check = ShiftCheck::factory()->create();
        $attempt = app(InspectionPhotoSubmission::class)->submit($check->shift->worker, $check, $this->photo(), Str::uuid()->toString());
        config(['inspections.vision.endpoint' => null]);
        app(InspectionAnalysis::class)->run($attempt->id);
        $shift = $check->shift;
        $this->assertSame('configuration_error', $attempt->refresh()->error_code);
        $this->signIn($shift->worker)->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertSessionHasErrors('shift');
        $this->post('/shifts/'.$shift->id.'/review-request', $this->payload($shift, 'Analysis service is unavailable; inspect the photo.'))->assertRedirect();
        $this->signIn($shift->assignedBy)->post('/admin/shifts/'.$shift->id.'/review', [
            ...$this->payload($shift, 'Checked manually; service failure noted.'), 'decision' => 'approve_with_remarks',
        ])->assertRedirect();
        $this->assertSame(ShiftStatus::CompletedWithRemarks, $shift->fresh()->status);
        $this->assertSame(CheckStatus::AnalysisError, $check->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_stale_revision_after_photo_or_analysis_changes_rejects_finalization(): void
    {
        $attempt = $this->evidence();
        $shift = $attempt->check->shift;
        $oldData = $this->payload($shift);
        $this->evidence($attempt->check);
        $this->signIn($shift->worker)->post('/shifts/'.$shift->id.'/complete', $oldData)->assertSessionHasErrors('revision');
        $this->assertSame(ShiftStatus::InProgress, $shift->fresh()->status);
    }

    public function test_competing_admin_decision_and_old_review_cycle_are_rejected(): void
    {
        $attempt = $this->evidence(result: CheckStatus::Failed);
        $shift = $attempt->check->shift;
        $this->requestReview($attempt);
        $oldData = [...$this->payload($shift, 'Accept old review'), 'decision' => 'approve_with_remarks'];
        $this->signIn($shift->assignedBy)->post('/admin/shifts/'.$shift->id.'/review', [
            ...$this->payload($shift, 'Rescan'), 'decision' => 'return_for_check',
        ])->assertRedirect();
        $attempt = $this->evidence($attempt->check, CheckStatus::Failed);
        $this->requestReview($attempt);
        $this->post('/admin/shifts/'.$shift->id.'/review', $oldData)->assertSessionHasErrors('revision');
        $this->assertSame(ShiftStatus::AwaitingReview, $shift->fresh()->status);
        $this->assertDatabaseCount('shift_transitions', 3);
    }

    public function test_authorization_rejects_guests_other_workers_moderators_and_admin_worker_actions(): void
    {
        $attempt = $this->evidence(result: CheckStatus::Failed);
        $shift = $attempt->check->shift;
        $routes = ['/shifts/'.$shift->id.'/complete', '/shifts/'.$shift->id.'/review-request', '/admin/shifts/'.$shift->id.'/review'];
        foreach ($routes as $route) {
            $this->post($route, $this->payload($shift))->assertRedirect('/login');
        }
        foreach ([Role::User, Role::Moderator] as $role) {
            $this->signIn(User::factory()->create(['role' => $role]));
            foreach ($routes as $route) {
                $this->post($route, $this->payload($shift))->assertForbidden();
            }
        }
        $this->signIn($shift->assignedBy);
        foreach (array_slice($routes, 0, 2) as $route) {
            $this->post($route, $this->payload($shift))->assertForbidden();
        }
        $this->signIn($shift->worker)->post($routes[2], $this->payload($shift))->assertForbidden();
        $this->assertDatabaseCount('shift_transitions', 0);
    }

    public function test_service_rechecks_fresh_role_and_blocked_state_and_rejects_self_review(): void
    {
        $attempt = $this->evidence();
        $shift = $attempt->check->shift;
        $worker = $shift->worker;
        User::whereKey($worker->id)->update(['is_active' => false]);
        try {
            app(ShiftWorkflow::class)->act($worker, $shift, ShiftDecision::Complete, $this->payload($shift));
            $this->fail('Blocked worker must be rejected.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('shift_transitions', 0);
        }
        User::whereKey($worker->id)->update(['is_active' => true, 'role' => Role::Admin]);
        $this->assertFalse($worker->fresh()->can('review', $shift));
        $this->signIn($worker->fresh())->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertForbidden();
    }

    public function test_admin_cannot_decide_without_review_request_or_with_invalid_decision_or_comment(): void
    {
        $attempt = $this->evidence(result: CheckStatus::Failed);
        $shift = $attempt->check->shift;
        $this->signIn($shift->assignedBy)->post('/admin/shifts/'.$shift->id.'/review', [
            ...$this->payload($shift, 'Not yet requested'), 'decision' => 'approve_with_remarks',
        ])->assertSessionHasErrors('shift');
        $this->requestReview($attempt);
        foreach (['completed', 'passed', 'complete'] as $decision) {
            $this->post('/admin/shifts/'.$shift->id.'/review', [...$this->payload($shift, 'Wrong action'), 'decision' => $decision])->assertSessionHasErrors('decision');
        }
        $this->post('/admin/shifts/'.$shift->id.'/review', [...$this->payload($shift), 'decision' => 'return_for_check'])->assertSessionHasErrors('comment');
        $this->assertDatabaseCount('shift_transitions', 1);
    }

    public function test_audit_failure_rolls_back_status_history_and_completion_timestamp(): void
    {
        $attempt = $this->evidence();
        $shift = $attempt->check->shift;
        $revision = $shift->fresh()->revision;
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(ShiftWorkflow::class)->act($shift->worker, $shift, ShiftDecision::Complete, $this->payload($shift));
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException) {
            $this->assertSame(ShiftStatus::InProgress, $shift->fresh()->status);
            $this->assertSame($revision, $shift->fresh()->revision);
            $this->assertNull($shift->fresh()->completed_at);
            $this->assertDatabaseCount('shift_transitions', 0);
        }
    }

    public function test_history_comments_are_escaped_private_paginated_and_excluded_from_audit_metadata(): void
    {
        $attempt = $this->evidence(result: CheckStatus::Failed);
        $shift = $attempt->check->shift;
        $comment = '<script>private-explanation</script>';
        $this->requestReview($attempt, $comment);
        $this->signIn($shift->worker)->get('/shifts/'.$shift->id)->assertSee('&lt;script&gt;private-explanation&lt;/script&gt;', false)
            ->assertDontSee($comment, false)->assertViewHas('transitions', fn ($history) => $history->perPage() === 10);
        $this->assertStringNotContainsString('private-explanation', ActivityLog::all()->toJson());
        $this->signIn(User::factory()->create())->get('/shifts/'.$shift->id)->assertForbidden()->assertDontSee('private-explanation');
    }

    public function test_admin_review_queue_filters_status_and_validates_filter(): void
    {
        $attempt = $this->evidence(result: CheckStatus::Failed);
        $shift = $attempt->check->shift;
        $shift->forceFill(['title' => 'Needs manual review'])->save();
        $this->requestReview($attempt);
        Shift::factory()->create(['title' => 'Not awaiting review']);
        $this->signIn($shift->assignedBy)->get('/admin/shifts?status=awaiting_review')->assertOk()
            ->assertSee('Needs manual review')->assertDontSee('Not awaiting review')
            ->assertViewHas('awaitingCount', 1)->assertViewHas('shifts', fn ($shifts) => $shifts->total() === 1);
        $this->getJson('/admin/shifts?status=invalid')->assertUnprocessable();
    }

    public function test_review_author_is_retained_in_user_history(): void
    {
        $attempt = $this->evidence(result: CheckStatus::Failed);
        $shift = $attempt->check->shift;
        $this->requestReview($attempt);
        $reviewer = User::factory()->create(['role' => Role::Admin]);
        app(ShiftWorkflow::class)->act($reviewer, $shift, ShiftDecision::ReturnForCheck, $this->payload($shift, 'Photograph again.'));
        $this->expectException(ValidationException::class);
        app(UserManager::class)->delete($shift->assignedBy, $reviewer->id);
    }

    public function test_actions_reject_invalid_keys_revisions_and_client_status_injection(): void
    {
        $attempt = $this->evidence();
        $shift = $attempt->check->shift;
        $this->signIn($shift->worker);
        $this->post('/shifts/'.$shift->id.'/complete', [])->assertSessionHasErrors(['request_key', 'revision']);
        $this->post('/shifts/'.$shift->id.'/complete', ['request_key' => 'not-uuid', 'revision' => -1])->assertSessionHasErrors(['request_key', 'revision']);
        $this->post('/shifts/'.$shift->id.'/complete', [...$this->payload($shift), 'decision' => 'approve_with_remarks'])->assertSessionHasErrors('decision');
        $this->post('/shifts/'.$shift->id.'/complete', [...$this->payload($shift), 'status' => 'completed_with_remarks'])->assertRedirect();
        $this->assertSame(ShiftStatus::Completed, $shift->fresh()->status);
    }

    public function test_return_requires_new_photos_for_unconfirmed_required_items_not_already_passed_items(): void
    {
        $first = $this->evidence(result: CheckStatus::Failed);
        $shift = $first->check->shift;
        $second = $this->evidence(ShiftCheck::factory()->create(['shift_id' => $shift->id, 'position' => 2]), CheckStatus::AnalysisError);
        $passed = $this->evidence(ShiftCheck::factory()->create(['shift_id' => $shift->id, 'position' => 3]));
        $this->requestReview($first);
        app(ShiftWorkflow::class)->act($shift->assignedBy, $shift, ShiftDecision::ReturnForCheck, $this->payload($shift, 'Retake the unconfirmed items.'));
        $this->signIn($shift->worker)->post('/shifts/'.$shift->id.'/review-request', $this->payload($shift, 'Same photos'))->assertSessionHasErrors('shift');
        $this->evidence($first->check);
        $this->post('/shifts/'.$shift->id.'/review-request', $this->payload($shift, 'Only one retaken'))->assertSessionHasErrors('shift');
        $this->evidence($second->check);
        $this->post('/shifts/'.$shift->id.'/complete', $this->payload($shift))->assertRedirect();
        $this->assertSame(ShiftStatus::Completed, $shift->fresh()->status);
        $this->assertSame(1, $passed->check->attempts()->count());
    }

    public function test_missing_review_photo_prevents_approval_but_still_allows_return_for_recovery(): void
    {
        $attempt = $this->evidence(result: CheckStatus::Failed);
        $shift = $attempt->check->shift;
        $this->requestReview($attempt);
        Storage::disk('inspection-photos')->delete($attempt->photo->path);
        $this->signIn($shift->assignedBy)->post('/admin/shifts/'.$shift->id.'/review', [
            ...$this->payload($shift, 'Cannot approve missing evidence.'), 'decision' => 'approve_with_remarks',
        ])->assertSessionHasErrors('shift');
        $this->post('/admin/shifts/'.$shift->id.'/review', [
            ...$this->payload($shift, 'Upload a new photo; the old file is unavailable.'), 'decision' => 'return_for_check',
        ])->assertRedirect();
        $this->assertSame(ShiftStatus::ReturnedForCheck, $shift->fresh()->status);
    }

    public function test_assignment_rejects_optional_only_template_instead_of_creating_unfinishable_shift(): void
    {
        $item = InspectionTemplateItem::factory()->create(['is_required' => false]);
        $template = $item->template;
        $template->update(['name' => 'Optional-only template']);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $worker = User::factory()->create();
        $van = Van::factory()->create();
        $this->signIn($admin)->get('/admin/shifts/create')->assertOk()->assertDontSee('Optional-only template');
        $this->post('/admin/shifts', [
            'title' => 'Invalid assignment', 'shift_date' => '2026-10-23', 'worker_id' => $worker->id,
            'van_id' => $van->id, 'inspection_template_id' => $template->id,
        ])->assertSessionHasErrors('inspection_template_id');
        $this->assertDatabaseCount('shifts', 0);
    }
}
