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
        foreach ([['POST', '/login'], ['POST', '/logout'], ['POST', '/forgot-password'], ['POST', '/reset-password'], ['POST', '/admin/users'], ['PUT', '/admin/users/1'], ['DELETE', '/admin/users/1']] as [$method, $uri]) {
            $this->withSession(['_token' => 'expected-token'])->call($method, $uri, ['_token' => 'wrong-token'])->assertStatus(419);
        }
    }

    public function test_valid_token_reaches_normal_validation(): void
    {
        $this->withSession(['_token' => 'expected-token'])->post('/login', ['_token' => 'expected-token'])->assertSessionHasErrors(['email', 'password']);
    }
}
