<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Passkey;
use App\Models\User;
use App\Notifications\NotificationRegistry;
use App\Notifications\Providers\Msg91Sms;
use App\Notifications\Providers\WacrmWhatsApp;
use App\Notifications\Triggers\AfterExpiry;
use App\Notifications\Triggers\BeforeExpiry;
use App\Notifications\Triggers\CustomerCreated;
use App\Notifications\Triggers\RechargeDone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(NotificationRegistry::class, function (): NotificationRegistry {
            $registry = new NotificationRegistry;

            foreach ([BeforeExpiry::class, AfterExpiry::class, RechargeDone::class, CustomerCreated::class] as $trigger) {
                $registry->registerTrigger($trigger);
            }

            $registry->registerProvider(Msg91Sms::class);
            $registry->registerProvider(WacrmWhatsApp::class);

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();

        Passkeys::usePasskeyModel(Passkey::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Every box on the Roles screen is a gate (Admin passes them all), plus staff and roles management, which
     * stays with Admin.
     */
    protected function configureGates(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasAccess($permission));
        }

        Gate::define('manage-staff', fn (User $user): bool => $user->isAdmin());
    }
}
