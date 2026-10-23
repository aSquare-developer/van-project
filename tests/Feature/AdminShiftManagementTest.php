<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\InspectionTemplate;
use App\Models\InspectionTemplateItem;
use App\Models\Shift;
use App\Models\User;
use App\Models\Van;
use App\Services\AuditLogger;
use App\Services\InspectionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminShiftManagementTest extends TestCase
{
    use RefreshDatabase;

    private function templateData(array $overrides = []): array
    {
        return [
            'name' => 'Friday inspection',
            'description' => 'Before leaving the van',
            'is_active' => '1',
            'items' => [
                ['title' => 'Silicone holder', 'instructions' => 'Photograph the complete holder.', 'check_key' => 'silicone_holder', 'is_required' => '1'],
                ['title' => 'Spare holder', 'instructions' => 'Photograph the spare holder.', 'check_key' => 'silicone_holder', 'is_required' => '0'],
            ],
            ...$overrides,
        ];
    }

    private function populatedTemplate(): InspectionTemplate
    {
        $template = InspectionTemplate::factory()->create();
        InspectionTemplateItem::factory()->create(['inspection_template_id' => $template->id]);

        return $template;
    }

    public function test_admin_can_manage_vans_and_normalized_unique_registration(): void
    {
        $this->signIn(User::factory()->create(['role' => Role::Admin]));
        $this->get('/admin/vans')->assertOk()->assertSee('No vans yet');
        $this->get('/admin/vans/create')->assertOk();
        $this->post('/admin/vans', ['name' => 'Blue van', 'registration' => ' 123abc ', 'is_active' => '1'])->assertRedirect('/admin/vans');
        $van = Van::firstOrFail();
        $this->assertSame('123ABC', $van->registration);
        $this->get('/admin/vans/'.$van->id.'/edit')->assertOk()->assertSee('Blue van');
        $this->put('/admin/vans/'.$van->id, ['name' => 'Renamed van', 'registration' => '123abc', 'is_active' => '0'])->assertRedirect('/admin/vans');
        $this->assertFalse($van->fresh()->is_active);
        $this->post('/admin/vans', ['name' => 'Duplicate', 'registration' => '123abc', 'is_active' => '1'])->assertSessionHasErrors('registration');
        $this->post('/admin/vans', ['name' => 'No plate', 'registration' => '', 'is_active' => '1'])->assertRedirect('/admin/vans');
        $this->assertDatabaseHas('vans', ['name' => 'No plate', 'registration' => null]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'van.updated', 'actor_id' => auth()->id()]);
        $entry = ActivityLog::where('event', 'van.created')->firstOrFail();
        $this->assertSame('vans', $entry->metadata['resource']['type']);
        $this->assertSame($van->id, $entry->metadata['resource']['id']);
    }

    public function test_admin_can_create_edit_reorder_and_archive_templates(): void
    {
        $this->signIn(User::factory()->create(['role' => Role::Admin]));
        $this->get('/admin/templates')->assertOk()->assertSee('No templates yet');
        $this->get('/admin/templates/create')->assertOk()->assertSee('Silicone cartridge holder');
        $data = $this->templateData();
        $this->post('/admin/templates', $data)->assertRedirect('/admin/templates');
        $template = InspectionTemplate::firstOrFail();
        $this->assertSame(['Silicone holder', 'Spare holder'], $template->items->pluck('title')->all());
        $this->assertSame([true, false], $template->items->pluck('is_required')->all());
        $this->assertSame(['holder_stocked'], $template->items->pluck('expected_class')->unique()->values()->all());
        $this->get('/admin/templates/'.$template->id.'/edit')->assertOk()->assertSee('Photograph the complete holder.');
        $data['items'] = array_reverse($data['items']);
        $data['is_active'] = '0';
        $this->put('/admin/templates/'.$template->id, $data)->assertRedirect('/admin/templates');
        $this->assertSame(['Spare holder', 'Silicone holder'], $template->fresh()->items->pluck('title')->all());
        $this->assertSame([1, 2], $template->fresh()->items->pluck('position')->all());
        $this->assertFalse($template->fresh()->is_active);
        $this->assertDatabaseHas('activity_logs', ['event' => 'template.created']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'template.updated']);
    }

    public function test_assignment_creates_snapshot_and_template_edits_do_not_change_it(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $worker = User::factory()->create();
        $van = Van::factory()->create();
        $this->signIn($admin)->post('/admin/templates', $this->templateData())->assertRedirect();
        $template = InspectionTemplate::firstOrFail();
        $this->get('/admin/shifts/create')->assertOk()->assertSee($worker->email)->assertSee($template->name);
        $data = ['title' => 'Friday shift', 'shift_date' => '2026-10-23', 'worker_id' => $worker->id, 'van_id' => $van->id, 'inspection_template_id' => $template->id, 'status' => 'completed', 'assigned_by' => $worker->id];
        $this->post('/admin/shifts', $data)->assertRedirect('/admin/shifts/'.Shift::firstOrFail()->id);
        $shift = Shift::firstOrFail();
        $this->assertSame('assigned', $shift->status->value);
        $this->assertSame($admin->id, $shift->assigned_by);
        $this->assertSame(2, $shift->checks()->count());
        $this->get('/admin/shifts')->assertOk()->assertSee('Friday shift');
        $this->get('/admin/shifts/'.$shift->id)->assertOk()->assertSee('Photograph the complete holder.')->assertSee('Optional');
        $changed = $this->templateData(['name' => 'Changed template', 'items' => [
            ['title' => 'Changed item', 'instructions' => 'Changed instructions.', 'check_key' => 'silicone_holder', 'is_required' => '1'],
        ]]);
        $this->put('/admin/templates/'.$template->id, $changed)->assertRedirect('/admin/templates');
        $this->get('/admin/shifts/'.$shift->id)->assertOk()->assertSee('Friday inspection')->assertSee('Silicone holder')->assertDontSee('Changed instructions.');
        $entry = ActivityLog::where('event', 'shift.assigned')->firstOrFail();
        $this->assertSame('shifts', $entry->metadata['resource']['type']);
        $this->assertSame($shift->id, $entry->metadata['resource']['id']);
        $this->post('/admin/shifts', [...$data, 'worker_id' => User::factory()->create()->id])->assertRedirect();
        $this->assertDatabaseCount('shifts', 2);
        $this->assertDatabaseCount('shift_checks', 3);
    }

    public function test_non_admins_cannot_access_any_management_endpoints(): void
    {
        $van = Van::factory()->create();
        $template = $this->populatedTemplate();
        $shift = Shift::factory()->create();
        $endpoints = [
            ['GET', '/admin/vans'], ['GET', '/admin/vans/create'], ['POST', '/admin/vans'], ['GET', '/admin/vans/'.$van->id.'/edit'], ['PUT', '/admin/vans/'.$van->id],
            ['GET', '/admin/templates'], ['GET', '/admin/templates/create'], ['POST', '/admin/templates'], ['GET', '/admin/templates/'.$template->id.'/edit'], ['PUT', '/admin/templates/'.$template->id],
            ['GET', '/admin/shifts'], ['GET', '/admin/shifts/create'], ['POST', '/admin/shifts'], ['GET', '/admin/shifts/'.$shift->id],
        ];
        foreach ([Role::User, Role::Moderator] as $role) {
            $this->signIn(User::factory()->create(['role' => $role]));
            foreach ($endpoints as [$method, $uri]) {
                $this->call($method, $uri, $this->templateData())->assertForbidden();
            }
            $this->get('/dashboard')->assertDontSee('href="'.route('admin.shifts.index').'"', false);
        }
        $this->assertDatabaseCount('shifts', 1);
        $this->assertDatabaseCount('vans', 2);
        $this->assertDatabaseCount('inspection_templates', 2);
    }

    public function test_guests_and_blocked_admins_cannot_access_management(): void
    {
        foreach (['/admin/vans', '/admin/templates', '/admin/shifts'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
        $blocked = User::factory()->create(['role' => Role::Admin, 'is_active' => false]);
        $this->signIn($blocked)->post('/admin/templates', $this->templateData())->assertRedirect('/login');
        $this->assertDatabaseCount('inspection_templates', 0);
    }

    public function test_assignment_options_only_include_active_workers_vans_and_nonempty_templates(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'name' => 'Administrator choice']);
        User::factory()->create(['role' => Role::Moderator, 'name' => 'Moderator choice']);
        User::factory()->create(['is_active' => false, 'name' => 'Blocked choice']);
        $worker = User::factory()->create(['name' => 'Active worker choice']);
        Van::factory()->create(['name' => 'Inactive van choice', 'is_active' => false]);
        $van = Van::factory()->create(['name' => 'Active van choice']);
        InspectionTemplate::factory()->create(['name' => 'Empty template choice']);
        $inactive = $this->populatedTemplate();
        $inactive->update(['name' => 'Inactive template choice', 'is_active' => false]);
        $this->populatedTemplate()->update(['name' => 'Active template choice']);
        $this->signIn($admin)->get('/admin/shifts/create')->assertOk()->assertSee($worker->name)->assertSee($van->name)->assertSee('Active template choice')
            ->assertDontSee('Moderator choice')->assertDontSee('Blocked choice')->assertDontSee('Inactive van choice')->assertDontSee('Empty template choice')->assertDontSee('Inactive template choice');
    }

    public function test_empty_setup_explains_required_steps(): void
    {
        $this->signIn(User::factory()->create(['role' => Role::Admin]))->get('/admin/shifts/create')->assertOk()
            ->assertSee('Create an active user')->assertSee('Add an active van')->assertSee('Create an active template');
    }

    public function test_template_validation_rejects_empty_items_unsupported_checks_and_injected_fields(): void
    {
        $this->signIn(User::factory()->create(['role' => Role::Admin]));
        $this->post('/admin/templates', $this->templateData(['items' => []]))->assertSessionHasErrors('items');
        $data = $this->templateData();
        $data['items'][0]['check_key'] = 'https://untrusted.example/analyze';
        $this->post('/admin/templates', $data)->assertSessionHasErrors('items.0.check_key');
        $data = $this->templateData();
        $data['items'][0]['expected_class'] = 'holder_empty';
        $this->post('/admin/templates', $data)->assertSessionHasErrors('items.0');
        $data = $this->templateData();
        $data['items'][0]['instructions'] = '';
        $this->post('/admin/templates', $data)->assertSessionHasErrors('items.0.instructions');
        $this->post('/admin/templates', $this->templateData(['items' => array_fill(0, 31, $this->templateData()['items'][0])]))->assertSessionHasErrors('items');
        $this->assertDatabaseCount('inspection_templates', 0);
    }

    public function test_assignment_rejects_invalid_worker_van_template_and_date_without_writes(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $worker = User::factory()->create();
        $van = Van::factory()->create();
        $template = $this->populatedTemplate();
        $data = ['title' => 'Shift', 'shift_date' => '2026-10-23', 'worker_id' => $worker->id, 'van_id' => $van->id, 'inspection_template_id' => $template->id];
        $this->signIn($admin);
        $this->post('/admin/shifts', [...$data, 'worker_id' => $admin->id])->assertSessionHasErrors('worker_id');
        $worker->forceFill(['is_active' => false])->save();
        $this->post('/admin/shifts', $data)->assertSessionHasErrors('worker_id');
        $worker->forceFill(['is_active' => true])->save();
        $van->update(['is_active' => false]);
        $this->post('/admin/shifts', $data)->assertSessionHasErrors('van_id');
        $van->update(['is_active' => true]);
        $template->update(['is_active' => false]);
        $this->post('/admin/shifts', $data)->assertSessionHasErrors('inspection_template_id');
        $template->update(['is_active' => true]);
        $this->post('/admin/shifts', [...$data, 'shift_date' => '2026-02-31'])->assertSessionHasErrors('shift_date');
        $this->post('/admin/shifts', [...$data, 'inspection_template_id' => 999999])->assertSessionHasErrors('inspection_template_id');
        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('shift_checks', 0);
    }

    public function test_template_edit_and_van_creation_roll_back_when_audit_fails(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $template = $this->populatedTemplate();
        $oldItem = $template->items->first()->id;
        $this->mock(AuditLogger::class)->shouldReceive('record')->twice()->andThrow(new \RuntimeException('Audit unavailable'));
        foreach (['template', 'van'] as $kind) {
            try {
                if ($kind === 'template') {
                    app(InspectionCatalog::class)->saveTemplate($admin, $this->templateData(['name' => 'Not saved']), $template);
                } else {
                    app(InspectionCatalog::class)->saveVan($admin, ['name' => 'Not saved', 'is_active' => true]);
                }
                $this->fail('Expected audit error.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Audit unavailable', $exception->getMessage());
            }
        }
        $this->assertDatabaseMissing('inspection_templates', ['name' => 'Not saved']);
        $this->assertDatabaseHas('inspection_template_items', ['id' => $oldItem]);
        $this->assertDatabaseCount('inspection_template_items', 1);
        $this->assertDatabaseCount('vans', 0);
    }

    public function test_catalog_rechecks_revoked_admin_rights(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        User::whereKey($admin->id)->update(['role' => Role::User]);
        $this->expectException(AuthorizationException::class);
        app(InspectionCatalog::class)->saveTemplate($admin, $this->templateData());
    }

    public function test_management_content_is_escaped_and_history_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->signIn($admin)->post('/admin/templates', $this->templateData(['name' => '<script>alert(1)</script>']))->assertRedirect();
        $this->get('/admin/templates')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->delete('/admin/templates/'.InspectionTemplate::firstOrFail()->id)->assertMethodNotAllowed();
        $this->delete('/admin/vans/1')->assertMethodNotAllowed();
        $this->get('/dashboard')->assertSee('href="'.route('admin.vans.index').'"', false)->assertSee('href="'.route('admin.templates.index').'"', false)->assertSee('href="'.route('admin.shifts.index').'"', false);
    }
}
