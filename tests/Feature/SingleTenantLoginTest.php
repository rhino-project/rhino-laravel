<?php

namespace Rhino\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\SanctumServiceProvider;
use Rhino\Tests\TestCase;

/**
 * A single-tenant user model: no Organization model, no organizations()
 * relation. Exercises the AuthController guard that lets org-less apps log in.
 */
class SingleTenantUser extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';
    protected $guarded = [];
    protected $hidden = ['password'];
}

class SingleTenantLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            SanctumServiceProvider::class,
        ]);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('auth.guards.sanctum', ['driver' => 'sanctum', 'provider' => 'users']);
        $app['config']->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => SingleTenantUser::class,
        ]);

        // Single-tenant: one default group, no organization middleware.
        $app['config']->set('rhino.models', []);
        $app['config']->set('rhino.route_groups', [
            'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
        ]);
    }

    public function test_login_succeeds_without_an_organizations_relation(): void
    {
        SingleTenantUser::forceCreate([
            'name' => 'Solo',
            'email' => 'solo@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'solo@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['organization_slug' => null]);
        $this->assertNotEmpty($response->json('token'));
    }
}
