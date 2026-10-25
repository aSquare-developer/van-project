<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\InspectionTemplate;
use App\Models\User;
use App\Models\Van;
use App\Models\VisionModel;
use App\Models\VisionModelRevision;
use App\Services\AuditLogger;
use App\Services\InspectionCatalog;
use App\Services\InspectionPhotoSubmission;
use App\Services\ShiftAssigner;
use App\Services\VisionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Fixtures\CreatesInspectionPhotos;
use Tests\TestCase;

class VisionCatalogTest extends TestCase
{
    use CreatesInspectionPhotos, RefreshDatabase;

    private function data(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Silicone shelf', 'status' => 'active', 'analysis_type' => 'classification',
            'model_id' => 'lisettes-workspace/silicone-shelf-status-2-resnet18-t1',
            'workspace' => 'lisettes-workspace', 'workflow_id' => 'silicone-shelf-status-test',
            'input_name' => 'image', 'output_name' => 'predictions',
            'passing_class' => 'occupied', 'failing_class' => 'empty', 'uncertain_classes' => [],
            'confidence_threshold' => '0.8', 'automatic_decisions' => '0',
        ], $overrides);
    }

    private function templateData(VisionModelRevision $revision): array
    {
        return ['name' => 'Model checklist', 'is_active' => true, 'items' => [[
            'title' => 'Check shelf', 'instructions' => 'Photograph the complete shelf.',
            'check_key' => 'rf_revision_'.$revision->id, 'is_required' => true,
        ]]];
    }

    public function test_admin_registers_model_rules_and_revision_history_without_exposing_credentials(): void
    {
        config(['inspections.roboflow.api_key' => 'server-only-key']);
        $this->signIn(User::factory()->create(['role' => Role::Admin]));
        $this->get('/admin/vision-models')->assertOk()->assertSee('No models registered')->assertDontSee('server-only-key');
        $this->get('/admin/vision-models/create')->assertOk()->assertSee('Exact Model ID');
        $this->post('/admin/vision-models', $this->data(['uncertain_classes' => 'invalid, obscured', 'endpoint' => 'https://attacker.test', 'api_key' => 'injected']))->assertRedirect('/admin/vision-models');
        $model = VisionModel::firstOrFail();
        $first = $model->latestRevision;
        $this->assertSame(['invalid', 'obscured'], $first->settings['uncertain_classes']);
        $this->assertArrayNotHasKey('endpoint', $first->settings);
        $this->assertArrayNotHasKey('api_key', $first->settings);
        $this->assertFalse($first->settings['automatic_decisions']);
        $this->get('/admin/vision-models/'.$model->id.'/edit')->assertOk()->assertSee('Revision history')->assertDontSee('server-only-key');
        $changed = $this->data(['model_id' => 'workspace/retrained-model-3', 'base_revision' => $first->id]);
        $this->put('/admin/vision-models/'.$model->id, $changed)->assertRedirect();
        $latest = $model->fresh()->latestRevision;
        $this->assertSame(2, $latest->version);
        $this->assertSame('lisettes-workspace/silicone-shelf-status-2-resnet18-t1', $first->fresh()->settings['model_id']);
        $this->put('/admin/vision-models/'.$model->id, [...$changed, 'base_revision' => $latest->id])->assertRedirect();
        $this->assertDatabaseCount('vision_model_revisions', 2);
        $this->put('/admin/vision-models/'.$model->id, $changed)->assertSessionHasErrors('name');
        $this->assertDatabaseHas('activity_logs', ['event' => 'vision_model.revision_created']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'vision_model.saved']);
        $this->get('/dashboard')->assertSee(route('admin.vision-models.index'));
    }

    public function test_only_active_admin_can_access_or_modify_catalog(): void
    {
        $model = VisionModel::factory()->create();
        VisionModelRevision::factory()->create(['vision_model_id' => $model->id]);
        $this->get('/admin/vision-models')->assertRedirect('/login');
        foreach ([Role::User, Role::Moderator] as $role) {
            $this->signIn(User::factory()->create(['role' => $role]));
            $this->get('/admin/vision-models')->assertForbidden();
            $this->get('/admin/vision-models/create')->assertForbidden();
            $this->get('/admin/vision-models/'.$model->id.'/edit')->assertForbidden();
            $this->post('/admin/vision-models', $this->data())->assertForbidden();
            $this->put('/admin/vision-models/'.$model->id, $this->data())->assertForbidden();
            $this->get('/dashboard')->assertDontSee('href="'.route('admin.vision-models.index').'"', false);
        }
        $this->signIn(User::factory()->create(['role' => Role::Admin, 'is_active' => false]))
            ->post('/admin/vision-models', $this->data())->assertRedirect('/login');
        $this->assertDatabaseCount('vision_models', 1);
        $this->assertDatabaseCount('vision_model_revisions', 1);
    }

    public function test_binary_classifier_can_be_registered_from_a_form_with_blank_optional_classes(): void
    {
        $this->signIn(User::factory()->create(['role' => Role::Admin]))
            ->post('/admin/vision-models', $this->data(['uncertain_classes' => '']))->assertRedirect('/admin/vision-models');
        $this->assertSame([], VisionModelRevision::firstOrFail()->settings['uncertain_classes']);
    }

    public function test_invalid_types_identifiers_and_class_rules_are_rejected(): void
    {
        $this->signIn(User::factory()->create(['role' => Role::Admin]));
        foreach ([
            ['analysis_type', 'object_detection'], ['model_id', '../wrong'], ['workspace', 'other/host'],
            ['workflow_id', 'https://attacker.test'], ['input_name', 'a.b'], ['output_name', 'a.b'],
            ['passing_class', 'empty'], ['confidence_threshold', 1.1], ['confidence_threshold', 0],
            ['uncertain_classes', ['occupied']], ['uncertain_classes', ['empty']], ['uncertain_classes', ['invalid', 'invalid']],
        ] as [$field, $value]) {
            $this->post('/admin/vision-models', $this->data([$field => $value]))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('vision_models', 0);
    }

    public function test_multiple_registered_models_and_rules_appear_in_template_choices(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $catalog = app(VisionCatalog::class);
        $first = $catalog->save($admin, $this->data())->latestRevision;
        $second = $catalog->save($admin, $this->data(['name' => 'Tool rack', 'model_id' => 'workspace/tools-1', 'workflow_id' => 'tools', 'passing_class' => 'tools_present', 'failing_class' => 'tools_missing']))->latestRevision;
        $third = $catalog->save($admin, $this->data(['name' => 'Shelf must be empty', 'passing_class' => 'empty', 'failing_class' => 'occupied']))->latestRevision;
        $this->signIn($admin)->get('/admin/templates/create')->assertOk()->assertSee('Silicone shelf')->assertSee('Tool rack')->assertSee('Shelf must be empty');
        $data = $this->templateData($first);
        $data['items'][] = $this->templateData($second)['items'][0];
        $data['items'][] = $this->templateData($third)['items'][0];
        $this->post('/admin/templates', $data)->assertRedirect();
        $this->assertSame(['occupied', 'tools_present', 'empty'], InspectionTemplate::firstOrFail()->items->pluck('expected_class')->all());
    }

    public function test_model_and_rule_are_frozen_for_assignment_and_copied_into_attempt(): void
    {
        Storage::fake('inspection-photos');
        $admin = User::factory()->create(['role' => Role::Admin]);
        $model = app(VisionCatalog::class)->save($admin, $this->data());
        $first = $model->latestRevision;
        $template = app(InspectionCatalog::class)->saveTemplate($admin, $this->templateData($first));
        $assignment = ['title' => 'Test shift', 'shift_date' => '2026-10-25', 'worker_id' => User::factory()->create()->id,
            'van_id' => Van::factory()->create()->id, 'inspection_template_id' => $template->id];
        $shift = app(ShiftAssigner::class)->assign($admin, $assignment);
        $original = $shift->checks->first()->analysis_config;
        $model = app(VisionCatalog::class)->save($admin, $this->data(['base_revision' => $first->id,
            'model_id' => 'workspace/new-model-3', 'passing_class' => 'full', 'confidence_threshold' => 0.95, 'automatic_decisions' => true]), $model);
        app(InspectionCatalog::class)->saveTemplate($admin, $this->templateData($model->latestRevision), $template);
        $new = app(ShiftAssigner::class)->assign($admin, $assignment);
        $this->assertSame($original, $shift->checks->first()->fresh()->analysis_config);
        $this->assertSame('workspace/new-model-3', $new->checks->first()->analysis_config['model_id']);
        $this->assertSame('full', $new->checks->first()->expected_class);
        $attempt = app(InspectionPhotoSubmission::class)->submit($shift->worker, $shift->checks->first(), $this->photo(), Str::uuid()->toString());
        $this->assertSame($original, $attempt->analysis_config);
        $this->assertSame('occupied', $attempt->expected_class);
        $this->assertSame($first->settings['model_id'], $attempt->model_version);
        $this->assertSame(0.8, $attempt->confidence_threshold);
    }

    public function test_disabled_models_prevent_new_assignments_without_rewriting_old_checklists(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $model = app(VisionCatalog::class)->save($admin, $this->data());
        $template = app(InspectionCatalog::class)->saveTemplate($admin, $this->templateData($model->latestRevision));
        $data = ['title' => 'Shift', 'shift_date' => '2026-10-25', 'worker_id' => User::factory()->create()->id,
            'van_id' => Van::factory()->create()->id, 'inspection_template_id' => $template->id];
        $shift = app(ShiftAssigner::class)->assign($admin, $data);
        app(VisionCatalog::class)->save($admin, $this->data(['status' => 'disabled', 'base_revision' => $model->latestRevision->id]), $model);
        $this->signIn($admin)->get('/admin/templates/'.$template->id.'/edit')->assertOk()->assertSee('unavailable for assignment');
        $this->post('/admin/shifts', $data)->assertSessionHasErrors('items');
        $this->assertDatabaseCount('shifts', 1);
        $this->assertSame('occupied', $shift->checks->first()->fresh()->analysis_config['passing_class']);
        $this->get('/admin/templates/create')->assertDontSee('rf_revision_'.$model->latestRevision->id);
    }

    public function test_audit_failure_rolls_back_catalog_and_revision(): void
    {
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new RuntimeException('Audit unavailable'));
        try {
            app(VisionCatalog::class)->save(User::factory()->create(['role' => Role::Admin]), $this->data());
            $this->fail('Audit failure must abort the transaction.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('vision_models', 0);
            $this->assertDatabaseCount('vision_model_revisions', 0);
        }
    }

    public function test_revisions_cannot_be_mutated_through_the_model(): void
    {
        $revision = VisionModelRevision::factory()->create();
        $this->expectException(LogicException::class);
        $revision->forceFill(['settings' => []])->save();
    }
}
