<?php

namespace App\Providers;

use App\Billing\BillingSettings;
use App\Billing\Pdf\DompdfInvoicePdf;
use App\Billing\Pdf\DompdfReceiptPdf;
use App\Billing\Pdf\InvoicePdf;
use App\Billing\Pdf\ReceiptPdf;
use App\Billing\Tax\IndiaGst;
use App\Billing\Tax\NoTax;
use App\Billing\Tax\SingleTax;
use App\Billing\Tax\TaxSetups;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Passkey;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\NotificationRegistry;
use App\Notifications\Providers\Msg91Sms;
use App\Notifications\Providers\WacrmWhatsApp;
use App\Notifications\Triggers\AfterExpiry;
use App\Notifications\Triggers\BeforeExpiry;
use App\Notifications\Triggers\CustomerCreated;
use App\Notifications\Triggers\OnlinePaymentReceived;
use App\Notifications\Triggers\RechargeDone;
use App\Payments\PaymentGateways;
use App\Payments\Paytm\PaytmGateway;
use App\Support\Addons\AddonSettings;
use App\Support\Addons\CustomerCards;
use App\Support\Addons\CustomerFormTabs;
use App\Support\Addons\CustomerTabs;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions;
use App\Support\SessionKeepers;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View as ViewInstance;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SessionKeepers::class);
        $this->app->singleton(AddonSettings::class);
        $this->app->singleton(CustomerFormTabs::class);
        $this->app->singleton(CustomerCards::class);
        $this->app->singleton(CustomerTabs::class);
        $this->app->singleton(TaxSetups::class, fn (): TaxSetups => new TaxSetups([new NoTax, new SingleTax, new IndiaGst]));
        $this->app->bind(BillingSettings::class, fn (): BillingSettings => BillingSettings::current());
        $this->app->bind(InvoicePdf::class, DompdfInvoicePdf::class);
        $this->app->bind(ReceiptPdf::class, DompdfReceiptPdf::class);
        $this->app->singleton(Navigation::class, fn (): Navigation => $this->coreNavigation());
        $this->app->singleton(PaymentGateways::class, function (): PaymentGateways {
            $gateways = new PaymentGateways;
            $gateways->register(PaytmGateway::class);

            return $gateways;
        });

        $this->app->singleton(Permissions::class, function (): Permissions {
            $permissions = new Permissions;

            foreach (Permission::cases() as $permission) {
                $permissions->register($permission->value, $permission->label(...), $permission->area(...), $permission->implies()?->value);
            }

            return $permissions;
        });

        $this->app->singleton(NotificationRegistry::class, function (): NotificationRegistry {
            $registry = new NotificationRegistry;

            foreach ([BeforeExpiry::class, AfterExpiry::class, RechargeDone::class, CustomerCreated::class, OnlinePaymentReceived::class] as $trigger) {
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

        View::composer('partials.navigation', fn (ViewInstance $view) => $view->with('menu', app(Navigation::class)->forUser(auth()->user(), request())));

        Passkeys::usePasskeyModel(Passkey::class);

        // The public pay page: searches and payment starts per visitor address.
        RateLimiter::for('quick-pay', fn (Request $request): array => [
            Limit::perMinute(10)->by('quick-pay-minute|'.$request->ip()),
            Limit::perHour(60)->by('quick-pay-hour|'.$request->ip()),
        ]);

        // The reset link goes to the panel's own address: the Host header is the visitor's to choose, and a link
        // built from it would send the reset token to their server.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim((string) config('app.url'), '/')
            .route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false));
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
     * Every box on the Roles screen is a gate (Admin passes them all), add-on boxes included even when an add-on
     * registers them after this runs; plus staff and roles management, which stays with Admin.
     */
    protected function configureGates(): void
    {
        Gate::before(fn (User $user, string $ability): ?bool => app(Permissions::class)->has($ability) ? $user->hasAccess($ability) : null);

        Gate::define('manage-staff', fn (User $user): bool => $user->isAdmin());
    }

    /**
     * Today's menu, in its order. Add-ons slot their items in between (e.g. order 45 after Login log).
     */
    private function coreNavigation(): Navigation
    {
        $menu = new Navigation;
        $can = fn (string $ability, ?string $model = null): Closure => fn (User $user): bool => $model === null ? $user->can($ability) : $user->can($ability, $model);

        $menu->group('main', null, false, 10);
        $menu->add(new NavItem('main', fn (): string => __('Dashboard'), 'dashboard', 'home', ['dashboard'], fn (User $user): bool => true, 10));
        $menu->add(new NavItem('main', fn (): string => __('Customers'), 'customers.index', 'user-group', ['customers.*'], $can('viewAny', Account::class), 20));
        $menu->add(new NavItem('main', fn (): string => __('Online'), 'online.index', 'signal', ['online.*'], $can('view-online-sessions'), 30));
        $menu->add(new NavItem('main', fn (): string => __('Login log'), 'login-log.index', 'queue-list', ['login-log.*'], $can('view-login-log'), 40));
        $menu->add(new NavItem('main', fn (): string => __('Plans'), 'plans.index', 'rectangle-stack', ['plans.*'], $can('viewAny', Plan::class), 50));

        $menu->group('network', fn (): string => __('Network'), true, 20);
        $menu->add(new NavItem('network', fn (): string => __('NAS'), 'routers.index', 'server-stack', ['routers.*'], $can('view-network'), 10));
        $menu->add(new NavItem('network', fn (): string => __('Pools'), 'pools.index', 'circle-stack', ['pools.*'], $can('view-network'), 20));
        $menu->add(new NavItem('network', fn (): string => __('IP ranges'), 'ip-ranges.index', 'globe-alt', ['ip-ranges.*'], $can('view-network'), 30));
        $menu->add(new NavItem('network', fn (): string => __('Address lists'), 'address-lists.index', 'list-bullet', ['address-lists.*'], $can('view-network'), 40));

        $menu->group('notifications', fn (): string => __('Notifications'), true, 30);
        $menu->add(new NavItem('notifications', fn (): string => __('Rules'), 'notification-rules.index', 'bell', ['notification-rules.*'], $can('manage-notification-rules'), 10));
        $menu->add(new NavItem('notifications', fn (): string => __('Message log'), 'notification-log.index', 'chat-bubble-left-right', ['notification-log.*'], $can('view-notification-log'), 20));
        $menu->add(new NavItem('notifications', fn (): string => __('Providers'), 'notifications.providers', 'paper-airplane', ['notifications.*'], $can('manage-notification-providers'), 30));

        // Invoices and Payments: shown while invoicing is on, or once there is an invoice to look at.
        $billing = fn (User $user): bool => $user->can('view-invoices') && (BillingSettings::current()->enabled || Invoice::query()->exists());
        $menu->group('billing', fn (): string => __('Billing'), true, 33);
        $menu->add(new NavItem('billing', fn (): string => __('Invoices'), 'invoices.index', 'document-text', ['invoices.*'], $billing, 10));
        $menu->add(new NavItem('billing', fn (): string => __('Payments'), 'payments.index', 'credit-card', ['payments.*'], $billing, 20));

        // Add-ons put their pages here; the group stays hidden until one does.
        $menu->group('addons', fn (): string => __('Add-ons'), true, 35);

        $menu->group('system', fn (): string => __('System'), true, 40);
        $menu->add(new NavItem('system', fn (): string => __('Staff'), 'staff.index', 'users', ['staff.*'], $can('manage-staff'), 10));
        $menu->add(new NavItem('system', fn (): string => __('Roles'), 'roles.index', 'key', ['roles.*'], $can('manage-staff'), 20));
        $menu->add(new NavItem('system', fn (): string => __('App settings'), 'app-settings.edit', 'cog-6-tooth', ['app-settings.*'], $can('manage-app-settings'), 30));
        $menu->add(new NavItem('system', fn (): string => __('Billing'), 'billing-settings.edit', 'banknotes', ['billing-settings.*'], $can('manage-billing'), 32));
        $menu->add(new NavItem('system', fn (): string => __('Online payments'), 'online-payments.edit', 'qr-code', ['online-payments.*'], $can('manage-billing'), 33));
        $menu->add(new NavItem('system', fn (): string => __('Add-ons'), 'addons.index', 'puzzle-piece', ['addons.*'], fn (User $user): bool => app(AddonSettings::class)->canOpenPage($user), 35));
        $menu->add(new NavItem('system', fn (): string => __('FreeRADIUS'), 'radius-settings.edit', 'adjustments-horizontal', ['radius-settings.*'], $can('manage-radius-settings'), 40));
        $menu->add(new NavItem('system', fn (): string => __('Connection types'), 'connection-types.index', 'wifi', ['connection-types.*'], $can('manage-connection-types'), 50));
        $menu->add(new NavItem('system', fn (): string => __('Activity log'), 'activity.index', 'clipboard-document-list', ['activity.*'], $can('view-activity-log'), 60));
        $menu->add(new NavItem('system', fn (): string => __('Import'), 'import.index', 'arrow-up-tray', ['import.*'], $can('import-data'), 70));

        return $menu;
    }
}
