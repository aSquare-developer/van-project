<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\Role;
use App\Models\InspectionAttempt;
use App\Models\InspectionTemplate;
use App\Models\InspectionTemplateItem;
use App\Models\Shift;
use App\Models\ShiftCheck;
use App\Models\ShiftTransition;
use App\Models\User;
use App\Models\Van;
use App\Models\VisionModel;
use App\Models\VisionModelRevision;
use App\Services\AuditLogger;
use App\Services\InspectionAnalysis;
use App\Services\ResourceDeletion;
use App\Services\VisionCatalog;
use App\Services\VisionClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class AdminResourceDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('inspection-photos');
        Http::preventStrayRequests();
    }

    private function resources(): array
    {
        return [
            ['/admin/vans', Van::factory()->create(), 'van.deleted'],
            ['/admin/templates', InspectionTemplate::factory()->create(), 'template.deleted'],
            ['/admin/shifts', Shift::factory()->create(), 'shift.deleted'],
            ['/admin/vision-models', VisionModel::factory()->create(), 'vision_model.deleted'],
        ];
    }

    public function test_admin_deletes_all_four_resources_with_confirmation_and_audit(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->signIn($admin);
        foreach ($this->resources() as [$base, $record, $event]) {
            $this->get($base)->assertOk()->assertSee('action="'.url($base.'/'.$record->id).'"', false)->assertSee('I confirm this deletion.');
            $this->delete($base.'/'.$record->id)->assertSessionHasErrors('confirmed');
            $this->assertFalse($record->fresh()->trashed());
            $this->delete($base.'/'.$record->id, ['confirmed' => '0'])->assertSessionHasErrors('confirmed');
            $this->delete($base.'/'.$record->id, ['confirmed' => '1'])->assertRedirect($base);
            $this->assertSoftDeleted($record);
            $this->delete($base.'/'.$record->id, ['confirmed' => '1'])->assertNotFound();
            $this->assertDatabaseHas('activity_logs', ['event' => $event, 'actor_id' => $admin->id]);
        }
    }

    public function test_guests_workers_moderators_and_blocked_admins_cannot_delete(): void
    {
        $resources = $this->resources();
        foreach ($resources as [$base, $record]) {
            $this->delete($base.'/'.$record->id, ['confirmed' => '1'])->assertRedirect('/login');
        }
        foreach ([Role::User, Role::Moderator] as $role) {
            $this->signIn(User::factory()->create(['role' => $role]));
            foreach ($resources as [$base, $record]) {
                $this->delete($base.'/'.$record->id, ['confirmed' => '1'])->assertForbidden();
                $this->assertFalse($record->fresh()->trashed());
            }
        }
        $this->signIn(User::factory()->create(['role' => Role::Admin, 'is_active' => false]));
        $this->delete('/admin/vans/'.$resources[0][1]->id, ['confirmed' => '1'])->assertRedirect('/login');
    }

    public function test_deleting_catalog_resources_preserves_existing_shift_and_model_history(): void
    {
        $shift = Shift::factory()->create();
        $item = InspectionTemplateItem::factory()->create(['inspection_template_id' => $shift->inspection_template_id]);
        $revision = VisionModelRevision::factory()->create();
        $item->forceFill(['vision_model_revision_id' => $revision->id, 'check_key' => 'rf_revision_'.$revision->id])->save();
        $check = ShiftCheck::factory()->create(['shift_id' => $shift->id, 'analysis_config' => ['revision_id' => $revision->id, ...$revision->settings]]);
        $snapshot = $check->analysis_config;
        $admin = $shift->assignedBy;
        foreach ([$shift->van, $shift->template, $revision->model] as $record) {
            app(ResourceDeletion::class)->delete($admin, $record);
        }
        $this->assertTrue($shift->fresh()->van->trashed());
        $this->assertTrue($shift->fresh()->template->trashed());
        $this->assertTrue($revision->fresh()->model->trashed());
        $this->assertEquals($snapshot, $check->fresh()->analysis_config);
        $this->assertDatabaseHas('inspection_template_items', ['id' => $item->id]);
        $this->assertDatabaseHas('vision_model_revisions', ['id' => $revision->id]);
        $this->signIn($admin)->get('/admin/shifts/'.$shift->id)->assertOk();
        $this->signIn($shift->worker)->get('/shifts/'.$shift->id)->assertOk();
    }

    public function test_deleted_model_is_not_selectable_for_new_assignments_but_existing_template_can_retain_revision(): void
    {
        $revision = VisionModelRevision::factory()->create();
        $item = InspectionTemplateItem::factory()->create(['vision_model_revision_id' => $revision->id, 'check_key' => 'rf_revision_'.$revision->id]);
        app(ResourceDeletion::class)->delete(User::factory()->create(['role' => Role::Admin]), $revision->model);
        $key = 'rf_revision_'.$revision->id;
        $catalog = app(VisionCatalog::class);
        $this->assertArrayNotHasKey($key, $catalog->choices());
        $this->assertArrayHasKey($key, $catalog->choices($item->template));
        $this->assertSame($revision->id, $catalog->definition($key, $item)['revision_id']);
        $this->expectException(ValidationException::class);
        $catalog->definition($key);
    }

    public function test_deleting_shift_cancels_pending_analysis_hides_routes_and_keeps_evidence(): void
    {
        $pending = InspectionAttempt::factory()->create(['status' => 'processing', 'processing_token' => 'old-token']);
        $check = $pending->check;
        $shift = $check->shift;
        $finished = InspectionAttempt::factory()->create(['shift_check_id' => $check->id, 'status' => 'analysed', 'result' => 'passed']);
        $transition = ShiftTransition::factory()->create(['shift_id' => $shift->id]);
        Storage::disk('inspection-photos')->put($pending->photo->path, 'private-photo');
        app(ResourceDeletion::class)->delete($shift->assignedBy, $shift);
        $this->assertSame('cancelled', $pending->refresh()->status);
        $this->assertNull($pending->processing_token);
        $this->assertSame('passed', $finished->refresh()->result);
        $this->assertDatabaseHas('shift_transitions', ['id' => $transition->id]);
        Storage::disk('inspection-photos')->assertExists($pending->photo->path);
        $this->assertFalse(app(InspectionAnalysis::class)->enqueue($pending->id));
        app(InspectionAnalysis::class)->run($pending->id);
        Http::assertNothingSent();
        $this->signIn($shift->worker)->get('/shifts/'.$shift->id)->assertNotFound();
        $this->get('/shifts/'.$shift->id.'/checks/'.$check->id.'/attempts/'.$pending->id.'/photo')->assertNotFound();
        $this->post('/shifts/'.$shift->id.'/complete', [])->assertNotFound();
        $this->signIn($shift->assignedBy)->get('/admin/shifts/'.$shift->id)->assertNotFound();
        $this->assertDatabaseCount('inspection_attempts', 2);
    }

    public function test_deletion_during_remote_analysis_discards_late_result(): void
    {
        $attempt = InspectionAttempt::factory()->create();
        $check = $attempt->check;
        $shift = $check->shift;
        $this->mock(VisionClient::class)->shouldReceive('analyse')->once()->andReturnUsing(function () use ($shift): array {
            app(ResourceDeletion::class)->delete($shift->assignedBy, $shift);

            return ['result' => 'passed', 'detected_class' => 'holder_stocked', 'confidence' => 0.95, 'boxes' => []];
        });
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('cancelled', $attempt->refresh()->status);
        $this->assertNull($attempt->result);
        $this->assertSame(CheckStatus::Pending, $check->fresh()->status);
        $this->assertSoftDeleted($shift);
    }

    public function test_audit_failure_rolls_back_deletion_and_attempt_cancellation(): void
    {
        $attempt = InspectionAttempt::factory()->create();
        $shift = $attempt->check->shift;
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        try {
            app(ResourceDeletion::class)->delete($shift->assignedBy, $shift);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException) {
            $this->assertFalse($shift->fresh()->trashed());
            $this->assertSame('uploaded', $attempt->fresh()->status);
        }
    }

    public function test_service_rechecks_actor_after_account_is_blocked(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $van = Van::factory()->create();
        User::whereKey($admin->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        app(ResourceDeletion::class)->delete($admin, $van);
    }

    public function test_deleted_shift_history_still_prevents_account_deletion(): void
    {
        $shift = Shift::factory()->create();
        app(ResourceDeletion::class)->delete($shift->assignedBy, $shift);
        $this->signIn($shift->assignedBy)->delete('/admin/users/'.$shift->worker_id)->assertSessionHasErrors('user');
    }

    public function test_retention_includes_old_photos_of_deleted_shifts(): void
    {
        $attempt = InspectionAttempt::factory()->create();
        $attempt->photo->forceFill(['created_at' => now()->subDays(100)])->save();
        Storage::disk('inspection-photos')->put($attempt->photo->path, 'private-photo');
        $shift = $attempt->check->shift;
        app(ResourceDeletion::class)->delete($shift->assignedBy, $shift);
        $this->artisan('inspections:prune-photos')->expectsOutput('Eligible photos: 1')->assertSuccessful();
        Storage::disk('inspection-photos')->assertExists($attempt->photo->path);
        $this->artisan('inspections:prune-photos', ['--force' => true])->assertSuccessful();
        Storage::disk('inspection-photos')->assertMissing($attempt->photo->path);
        $this->assertDatabaseCount('inspection_attempts', 1);
    }

    public function test_deleted_van_and_template_cannot_be_reused_from_an_old_assignment_form(): void
    {
        $shift = Shift::factory()->create();
        $admin = $shift->assignedBy;
        app(ResourceDeletion::class)->delete($admin, $shift->van);
        app(ResourceDeletion::class)->delete($admin, $shift->template);
        $this->signIn($admin)->post('/admin/shifts', [
            'title' => 'Stale assignment', 'shift_date' => '2026-10-25', 'worker_id' => $shift->worker_id,
            'van_id' => $shift->van_id, 'inspection_template_id' => $shift->inspection_template_id,
        ])->assertSessionHasErrors(['van_id', 'inspection_template_id']);
        $this->assertDatabaseCount('shifts', 1);
    }
}
