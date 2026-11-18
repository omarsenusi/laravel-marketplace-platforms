<?php

namespace Omarsenusi\MarketplacePlatforms\Tests;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\ServiceProvider;
use Omarsenusi\MarketplacePlatforms\Contracts\AppliesSubscriptions;
use Omarsenusi\MarketplacePlatforms\Contracts\ProvisionsAccounts;
use Omarsenusi\MarketplacePlatforms\PlatformsServiceProvider;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\FakeAppliesSubscriptions;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\FakeProvisionsAccounts;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\User;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\Workspace;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            PlatformsServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'http://localhost');

        // Host stand-ins: the package must resolve users/workspaces
        // through config, never directly.
        $app['config']->set('platforms.models.user', User::class);
        $app['config']->set('platforms.models.workspace', Workspace::class);
        $app['config']->set('platforms.tables.users', 'users');
        $app['config']->set('platforms.tables.workspaces', 'workspaces');

        // Auth against the fixture user model so actingAs/login work.
        $app['config']->set('auth.providers.users.model', User::class);

        // The package renders Inertia pages for claim/launch/callback;
        // the fixture root view keeps those responses renderable.
        $app['view']->getFinder()->prependLocation(__DIR__.'/Fixtures/views');

        // Host apps ship the JS page components (auth/claim-workspace,
        // platforms/launch, ...); the package cannot — skip the
        // existence check and assert on component names instead.
        $app['config']->set('inertia.testing.ensure_pages_exist', false);

        $app->singleton(ProvisionsAccounts::class, FakeProvisionsAccounts::class);
        $app->singleton(AppliesSubscriptions::class, FakeAppliesSubscriptions::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function defineRoutes($router): void
    {
        // Minimal host routes the package redirects to: login page,
        // dashboard home, and a Fortify-style password reset pair.
        $router->get('/login', fn () => 'login')->name('login');
        $router->post('/login', function (Request $request) {
            $credentials = $request->only('email', 'password');

            if (! Auth::attempt($credentials, $request->boolean('remember'))) {
                return back()->withErrors(['email' => 'These credentials do not match our records.']);
            }

            $request->session()->regenerate();

            return redirect()->intended('/dashboard');
        });

        $router->get('/dashboard', fn () => 'dashboard')->name('dashboard');

        $router->post('/forgot-password', function (Request $request) {
            $request->validate(['email' => 'required|email']);

            $status = Password::sendResetLink(
                $request->only('email')
            );

            return $status === Password::RESET_LINK_SENT
                ? back()->with('status', __($status))
                : back()->withErrors(['email' => __($status)]);
        });

        $router->post('/reset-password', function (Request $request) {
            $request->validate([
                'token' => 'required',
                'email' => 'required|email',
                'password' => ['required', 'string', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
            ]);

            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, #[\SensitiveParameter] $password) {
                    $user->forceFill([
                        'password' => Hash::make($password),
                        'email_verified_at' => $user->getAttribute('email_verified_at') ?? now(),
                    ])->save();

                    event(new PasswordReset($user));
                }
            );

            return $status === Password::PASSWORD_RESET
                ? redirect()->route('login')->with('status', __($status))
                : back()->withErrors(['email' => __($status)]);
        })->name('password.update');
    }

    protected function applier(): FakeAppliesSubscriptions
    {
        return $this->app->make(AppliesSubscriptions::class);
    }
}
