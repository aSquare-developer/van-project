<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\InspectionPhoto;
use App\Models\InspectionTemplate;
use App\Models\InspectionTemplateItem;
use App\Models\Shift;
use App\Models\ShiftCheck;
use App\Models\User;
use App\Models\Van;
use App\Services\AuditLogger;
use App\Services\InspectionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\CreatesInspectionPhotos;
use Tests\TestCase;

class InspectionReferencePhotoTest extends TestCase
{
    use CreatesInspectionPhotos, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('inspection-photos');
    }

    private function templateData(array $item = []): array
    {
        return [
            'name' => 'Reference checklist', 'is_active' => '1',
            'items' => [[
                'title' => 'Holder', 'instructions' => 'Match this reference.', 'check_key' => 'silicone_holder', 'is_required' => '1',
                ...$item,
            ]],
        ];
    }

    public function test_reference_upload_is_private_and_assignment_keeps_original_after_replacement(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->signIn($admin)->post('/admin/templates', $this->templateData(['reference_photo' => $this->photo()]))->assertRedirect('/admin/templates');
        $template = InspectionTemplate::firstOrFail();
        $item = $template->items->first();
        $originalPhoto = $item->referencePhoto;
        $adminReference = '/admin/templates/'.$template->id.'/items/'.$item->id.'/reference';
        $this->get($adminReference)->assertOk()->assertHeader('Content-Type', 'image/png');
        $worker = User::factory()->create();
        $this->post('/admin/shifts', [
            'title' => 'Photo shift', 'shift_date' => '2026-10-23', 'worker_id' => $worker->id,
            'van_id' => Van::factory()->create()->id, 'inspection_template_id' => $template->id,
        ])->assertRedirect();
        $shift = Shift::firstOrFail();
        $check = $shift->checks->first();
        $this->assertSame($originalPhoto->id, $check->reference_photo_id);
        $this->put('/admin/templates/'.$template->id, $this->templateData(['id' => $item->id, 'reference_photo' => $this->photo()]))->assertRedirect();
        $replacement = $template->fresh()->items->first();
        $this->assertNotSame($originalPhoto->id, $replacement->reference_photo_id);
        Storage::disk('inspection-photos')->assertExists($originalPhoto->path);
        $workerReference = '/shifts/'.$shift->id.'/checks/'.$check->id.'/reference';
        $this->signIn($worker)->get($workerReference)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/shifts/'.$shift->id.'/checks/'.$check->id)->assertOk()->assertSee('Reference example')->assertSee($workerReference);
        $this->get($adminReference)->assertNotFound();
        $this->get('/admin/templates/'.$template->id.'/items/'.$replacement->id.'/reference')->assertForbidden();
        $this->signIn(User::factory()->create())->get($workerReference)->assertForbidden();
        $this->signIn($admin)->get('/admin/templates/'.$template->id.'/items/'.$replacement->id.'/reference')->assertOk();
        $this->assertSame($originalPhoto->id, $check->fresh()->reference_photo_id);
    }

    public function test_editing_without_new_file_keeps_reference_and_explicit_removal_only_affects_future_assignments(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->signIn($admin)->post('/admin/templates', $this->templateData(['reference_photo' => $this->photo()]))->assertRedirect();
        $template = InspectionTemplate::firstOrFail();
        $item = $template->items->first();
        $photoId = $item->reference_photo_id;
        $this->get('/admin/templates/'.$template->id.'/edit')->assertOk()->assertSee('multipart/form-data', false)->assertSee('View current reference photo');
        $this->put('/admin/templates/'.$template->id, $this->templateData(['id' => $item->id]))->assertRedirect();
        $item = $template->fresh()->items->first();
        $this->assertSame($photoId, $item->reference_photo_id);
        $this->put('/admin/templates/'.$template->id, $this->templateData(['id' => $item->id, 'remove_reference_photo' => '1']))->assertRedirect();
        $this->assertNull($template->fresh()->items->first()->reference_photo_id);
        $this->assertDatabaseCount('inspection_photos', 1);
    }

    public function test_reference_validation_rejects_svg_and_foreign_item_ids_atomically(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->signIn($admin)->post('/admin/templates', $this->templateData([
            'reference_photo' => UploadedFile::fake()->createWithContent('bad.svg', '<svg></svg>'),
        ]))->assertSessionHasErrors('items.0.reference_photo');
        $this->assertDatabaseCount('inspection_photos', 0);
        $this->assertDatabaseCount('inspection_templates', 0);
        $own = InspectionTemplate::factory()->create();
        InspectionTemplateItem::factory()->create(['inspection_template_id' => $own->id]);
        $foreign = InspectionTemplateItem::factory()->create();
        $this->put('/admin/templates/'.$own->id, $this->templateData(['id' => $foreign->id]))->assertSessionHasErrors('items');
        $this->assertDatabaseCount('inspection_template_items', 2);
        $this->assertCount(0, Storage::disk('inspection-photos')->allFiles());
    }

    public function test_reference_file_is_removed_when_template_transaction_fails(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(InspectionCatalog::class)->saveTemplate($admin, $this->templateData(['reference_photo' => $this->photo()]));
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('inspection_photos', 0);
            $this->assertDatabaseCount('inspection_templates', 0);
            $this->assertCount(0, Storage::disk('inspection-photos')->allFiles());
        }
    }

    public function test_retention_preserves_template_and_snapshot_references_but_can_purge_orphans(): void
    {
        $current = InspectionPhoto::factory()->create(['purpose' => 'reference', 'created_at' => now()->subDays(14)]);
        $snapshot = InspectionPhoto::factory()->create(['purpose' => 'reference', 'created_at' => now()->subDays(14)]);
        $orphan = InspectionPhoto::factory()->create(['purpose' => 'reference', 'created_at' => now()->subDays(14)]);
        InspectionTemplateItem::factory()->create(['reference_photo_id' => $current->id]);
        ShiftCheck::factory()->create(['reference_photo_id' => $snapshot->id]);
        foreach ([$current, $snapshot, $orphan] as $photo) {
            Storage::disk('inspection-photos')->put($photo->path, 'reference-bytes');
        }
        $this->artisan('inspections:prune-photos', ['--force' => true])->assertSuccessful();
        Storage::disk('inspection-photos')->assertExists($current->path);
        Storage::disk('inspection-photos')->assertExists($snapshot->path);
        Storage::disk('inspection-photos')->assertMissing($orphan->path);
        $this->assertNull($current->fresh()->purged_at);
        $this->assertNull($snapshot->fresh()->purged_at);
        $this->assertDatabaseCount('inspection_photos', 3);
    }

    public function test_accounts_with_reference_upload_history_must_be_blocked_instead_of_deleted(): void
    {
        $uploader = User::factory()->create(['role' => Role::Admin]);
        InspectionPhoto::factory()->create(['uploaded_by' => $uploader->id, 'purpose' => 'reference']);
        $this->signIn(User::factory()->create(['role' => Role::Admin]))->delete('/admin/users/'.$uploader->id)->assertSessionHasErrors('user');
        $this->assertDatabaseHas('users', ['id' => $uploader->id]);
    }
}
