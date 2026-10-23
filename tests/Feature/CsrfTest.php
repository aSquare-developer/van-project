<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PreventRequestForgery::class, function ($app) {
            return new class($app, $app['encrypter']) extends PreventRequestForgery
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            };
        });
    }

    public function test_mutating_routes_reject_missing_or_invalid_csrf_tokens(): void
    {
        foreach ([['POST', '/login'], ['POST', '/logout'], ['POST', '/forgot-password'], ['POST', '/reset-password'], ['POST', '/admin/users'], ['PUT', '/admin/users/1'], ['DELETE', '/admin/users/1'], ['POST', '/admin/vans'], ['PUT', '/admin/vans/1'], ['POST', '/admin/templates'], ['PUT', '/admin/templates/1'], ['POST', '/admin/shifts']] as [$method, $uri]) {
            $this->withSession(['_token' => 'expected-token'])->call($method, $uri, ['_token' => 'wrong-token'])->assertStatus(419);
        }
    }

    public function test_valid_token_reaches_normal_validation(): void
    {
        $this->withSession(['_token' => 'expected-token'])->post('/login', ['_token' => 'expected-token'])->assertSessionHasErrors(['email', 'password']);
    }

    public function test_photo_upload_rejects_invalid_csrf_token(): void
    {
        $this->withSession(['_token' => 'expected-token'])->post('/shifts/1/checks/1/photos', ['_token' => 'wrong-token'])->assertStatus(419);
    }

    public function test_workflow_actions_reject_missing_and_invalid_csrf_tokens(): void
    {
        foreach (['/shifts/1/complete', '/shifts/1/review-request', '/admin/shifts/1/review'] as $path) {
            $this->withSession(['_token' => 'expected-token'])->post($path)->assertStatus(419);
            $this->withSession(['_token' => 'expected-token'])->post($path, ['_token' => 'wrong-token'])->assertStatus(419);
        }
    }
}
