<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Models\InspectionAttempt;
use App\Models\Shift;
use App\Models\ShiftCheck;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InspectionPhotoSubmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\CreatesInspectionPhotos;
use Tests\TestCase;

class InspectionPhotoTest extends TestCase
{
    use CreatesInspectionPhotos, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('inspection-photos');
    }

    private function uploadPath(ShiftCheck $check): string
    {
        return '/shifts/'.$check->shift_id.'/checks/'.$check->id.'/photos';
    }

    private function photoPath(InspectionAttempt $attempt): string
    {
        return '/shifts/'.$attempt->check->shift_id.'/checks/'.$attempt->shift_check_id.'/attempts/'.$attempt->id.'/photo';
    }

    public function test_worker_uploads_private_photo_and_creates_a_pending_attempt(): void
    {
        $check = ShiftCheck::factory()->create(['status' => CheckStatus::Passed]);
        $this->signIn($check->shift->worker)->post($this->uploadPath($check), [
            'photo' => $this->photo('untrusted-name.png'), 'submission_key' => Str::uuid()->toString(), 'status' => 'passed',
        ])->assertRedirect(route('shifts.checks.show', [$check->shift, $check]));
        $attempt = InspectionAttempt::firstOrFail();
        $photo = $attempt->photo;
        $this->assertSame('uploaded', $attempt->status);
        $this->assertSame(CheckStatus::Pending, $check->fresh()->status);
        $this->assertSame(ShiftStatus::InProgress, $check->shift->fresh()->status);
        $this->assertSame('image/png', $photo->mime);
        $this->assertSame(64, $photo->width);
        $this->assertStringEndsWith('.png', $photo->path);
        $this->assertStringNotContainsString('untrusted', $photo->path);
        Storage::disk('inspection-photos')->assertExists($photo->path);
        $this->get($this->photoPath($attempt))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/storage/'.$photo->path)->assertForbidden();
        $this->get('/shifts/'.$check->shift_id.'/checks/'.$check->id)->assertOk()->assertSee('Photo received')->assertSee('Awaiting analysis')
            ->assertSee('No recognition result has been recorded')->assertSee('Not checked')->assertDontSee($photo->path);
        $this->assertDatabaseHas('activity_logs', ['event' => 'inspection.photo_submitted', 'actor_id' => $check->shift->worker_id]);
    }

    public function test_repeated_submission_is_idempotent_and_new_form_preserves_history(): void
    {
        $check = ShiftCheck::factory()->create();
        $this->signIn($check->shift->worker);
        $key = Str::uuid()->toString();
        for ($i = 0; $i < 2; $i++) {
            $this->post($this->uploadPath($check), ['photo' => $this->photo(), 'submission_key' => $key])->assertRedirect();
        }
        $this->assertDatabaseCount('inspection_attempts', 1);
        $this->assertDatabaseCount('inspection_photos', 1);
        $this->post($this->uploadPath($check), ['photo' => $this->photo(), 'submission_key' => Str::uuid()->toString()])->assertRedirect();
        $this->assertDatabaseCount('inspection_attempts', 2);
        $this->assertCount(2, Storage::disk('inspection-photos')->allFiles());
        $attempts = InspectionAttempt::orderBy('id')->get();
        $this->get('/shifts/'.$check->shift_id.'/checks/'.$check->id)->assertSeeInOrder(['Attempt #'.$attempts[1]->id, 'Attempt #'.$attempts[0]->id]);
        $this->assertDatabaseCount('activity_logs', 2);
    }

    public function test_foreign_workers_and_moderators_cannot_upload_or_view_images(): void
    {
        $check = ShiftCheck::factory()->create();
        $this->signIn($check->shift->worker)->post($this->uploadPath($check), ['photo' => $this->photo(), 'submission_key' => Str::uuid()->toString()]);
        $attempt = InspectionAttempt::firstOrFail();
        foreach ([Role::User, Role::Moderator] as $role) {
            $this->signIn(User::factory()->create(['role' => $role]));
            $this->post($this->uploadPath($check), ['photo' => $this->photo(), 'submission_key' => Str::uuid()->toString()])->assertForbidden();
            $this->get($this->photoPath($attempt))->assertForbidden();
        }
        $this->assertDatabaseCount('inspection_attempts', 1);
        $this->signIn($check->shift->assignedBy)->get($this->photoPath($attempt))->assertOk();
        $this->post($this->uploadPath($check), ['photo' => $this->photo(), 'submission_key' => Str::uuid()->toString()])->assertForbidden();
    }

    public function test_nested_routes_reject_foreign_check_and_attempt_ids(): void
    {
        $first = ShiftCheck::factory()->create();
        $second = ShiftCheck::factory()->create(['shift_id' => $first->shift_id, 'position' => 2]);
        $this->signIn($first->shift->worker)->post($this->uploadPath($second), ['photo' => $this->photo(), 'submission_key' => Str::uuid()->toString()]);
        $attempt = InspectionAttempt::firstOrFail();
        $this->get('/shifts/'.$first->shift_id.'/checks/'.$first->id.'/attempts/'.$attempt->id.'/photo')->assertNotFound();
        $other = Shift::factory()->create(['worker_id' => $first->shift->worker_id]);
        $this->post('/shifts/'.$other->id.'/checks/'.$first->id.'/photos', ['photo' => $this->photo(), 'submission_key' => Str::uuid()->toString()])->assertNotFound();
    }

    public function test_closed_and_under_review_shifts_reject_uploads(): void
    {
        $check = ShiftCheck::factory()->create();
        $this->signIn($check->shift->worker);
        foreach ([ShiftStatus::Completed, ShiftStatus::CompletedWithRemarks, ShiftStatus::AwaitingReview] as $status) {
            $check->shift->forceFill(['status' => $status])->save();
            $this->post($this->uploadPath($check), ['photo' => $this->photo(), 'submission_key' => Str::uuid()->toString()])->assertForbidden();
            $this->get('/shifts/'.$check->shift_id.'/checks/'.$check->id)->assertOk()->assertDontSee('data-photo-upload', false)
                ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        }
        $this->assertDatabaseCount('inspection_photos', 0);
    }

    public function test_upload_rejects_nonimages_svg_oversized_files_and_invalid_dimensions(): void
    {
        $check = ShiftCheck::factory()->create();
        $this->signIn($check->shift->worker);
        $invalid = [
            UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "unsafe";'),
            UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('broken.png', "\x89PNG\r\n\x1a\n"),
            $this->photo()->size(10241),
            $this->photo('too-wide.png', 9000, 64),
            $this->photo('too-many-pixels.png', 6000, 6000),
        ];
        foreach ($invalid as $file) {
            $this->post($this->uploadPath($check), ['photo' => $file, 'submission_key' => Str::uuid()->toString()])->assertSessionHasErrors('photo');
        }
        $this->assertDatabaseCount('inspection_photos', 0);
        $this->assertCount(0, Storage::disk('inspection-photos')->allFiles());
    }

    public function test_audit_failure_rolls_back_database_and_removes_new_file(): void
    {
        $check = ShiftCheck::factory()->create(['status' => CheckStatus::Passed]);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(InspectionPhotoSubmission::class)->submit($check->shift->worker, $check, $this->photo(), Str::uuid()->toString());
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('inspection_attempts', 0);
            $this->assertDatabaseCount('inspection_photos', 0);
            $this->assertCount(0, Storage::disk('inspection-photos')->allFiles());
            $this->assertSame(CheckStatus::Passed, $check->fresh()->status);
            $this->assertSame(ShiftStatus::Assigned, $check->shift->fresh()->status);
        }
    }

    public function test_submission_rechecks_blocked_account_inside_transaction(): void
    {
        $check = ShiftCheck::factory()->create();
        $worker = $check->shift->worker;
        User::whereKey($worker->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        app(InspectionPhotoSubmission::class)->submit($worker, $check, $this->photo(), Str::uuid()->toString());
    }

    public function test_upload_throttling_and_missing_submission_key(): void
    {
        $check = ShiftCheck::factory()->create();
        $this->signIn($check->shift->worker)->post($this->uploadPath($check), ['photo' => $this->photo()])->assertSessionHasErrors('submission_key');
        for ($i = 0; $i < 9; $i++) {
            $this->post($this->uploadPath($check), ['photo' => $this->photo(), 'submission_key' => Str::uuid()->toString()])->assertRedirect();
        }
        $this->post($this->uploadPath($check), ['photo' => $this->photo(), 'submission_key' => Str::uuid()->toString()])->assertStatus(429);
        $this->assertDatabaseCount('inspection_attempts', 9);
    }

    public function test_camera_and_blob_preview_permissions_are_limited_to_authorized_capture_page(): void
    {
        $check = ShiftCheck::factory()->create();
        $this->app->detectEnvironment(fn () => 'production');
        $this->signIn($check->shift->worker)->get('/shifts/'.$check->shift_id.'/checks/'.$check->id)->assertOk()
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        $this->assertStringContainsString('blob:', $this->get('/shifts/'.$check->shift_id.'/checks/'.$check->id)->headers->get('Content-Security-Policy'));
        $response = $this->get('/dashboard')->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $this->assertStringNotContainsString('blob:', $response->headers->get('Content-Security-Policy'));
        $this->signIn(User::factory()->create())->get('/shifts/'.$check->shift_id.'/checks/'.$check->id)->assertForbidden()
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_retention_defaults_to_dry_run_and_preserves_active_shifts_and_attempt_metadata(): void
    {
        $old = InspectionAttempt::factory()->create();
        $active = InspectionAttempt::factory()->create();
        foreach ([$old, $active] as $attempt) {
            $attempt->photo->forceFill(['created_at' => now()->subDays(100)])->save();
            Storage::disk('inspection-photos')->put($attempt->photo->path, 'test-photo-bytes');
        }
        $old->check->shift->forceFill(['status' => ShiftStatus::Completed])->save();
        $this->artisan('inspections:prune-photos')->expectsOutput('Eligible photos: 1')->assertSuccessful();
        Storage::disk('inspection-photos')->assertExists($old->photo->path);
        $this->artisan('inspections:prune-photos', ['--force' => true])->assertSuccessful();
        Storage::disk('inspection-photos')->assertMissing($old->photo->path);
        Storage::disk('inspection-photos')->assertExists($active->photo->path);
        $this->assertNotNull($old->photo->fresh()->purged_at);
        $this->assertDatabaseCount('inspection_attempts', 2);
        $this->signIn($old->check->shift->worker)->get($this->photoPath($old))->assertNotFound();
        $this->get('/shifts/'.$old->check->shift_id.'/checks/'.$old->shift_check_id)->assertSee('Photo removed under the retention policy');
    }

    public function test_guests_and_blocked_workers_cannot_download_photos(): void
    {
        $attempt = InspectionAttempt::factory()->create();
        Storage::disk('inspection-photos')->put($attempt->photo->path, 'fixture');
        $this->get($this->photoPath($attempt))->assertRedirect('/login');
        $worker = $attempt->check->shift->worker;
        $this->signIn($worker);
        User::whereKey($worker->id)->update(['is_active' => false]);
        $this->get($this->photoPath($attempt))->assertRedirect('/login');
    }
}
