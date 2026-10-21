<?php

namespace App\Enums;

/**
 * Every box on the Roles screen. Admin has all of them through User::hasAccess(); other roles hold the ticked
 * ones. Staff and roles management is not here: it stays with Admin, so no role can grant itself more.
 * A case added later needs a migration that creates its row.
 */
enum Permission: string
{
    case ViewCustomers = 'view-customers';
    case AddCustomers = 'add-customers';
    case EditCustomers = 'edit-customers';
    case RechargeCustomers = 'recharge-customers';
    case ChangeCustomerStatus = 'change-customer-status';
    case DeleteCustomers = 'delete-customers';
    case ViewCustomerPasswords = 'view-customer-passwords';
    case ResetCustomerDevices = 'reset-customer-devices';

    case ViewOnlineSessions = 'view-online-sessions';
    case DisconnectSessions = 'disconnect-sessions';
    case ViewLoginLog = 'view-login-log';

    case ViewPlans = 'view-plans';
    case ManagePlans = 'manage-plans';

    case ViewNetwork = 'view-network';
    case ManageNas = 'manage-nas';
    case ManagePools = 'manage-pools';
    case ManageIpRanges = 'manage-ip-ranges';
    case ManageAddressLists = 'manage-address-lists';

    case ImportData = 'import-data';
    case ManageConnectionTypes = 'manage-connection-types';
    case ManageAppSettings = 'manage-app-settings';
    case ManageRadiusSettings = 'manage-radius-settings';
    case ViewActivityLog = 'view-activity-log';

    case ViewNotificationLog = 'view-notification-log';
    case ManageNotificationRules = 'manage-notification-rules';
    case ManageNotificationProviders = 'manage-notification-providers';

    case ManageBilling = 'manage-billing';
    case SetCustomerPricing = 'set-customer-pricing';
    case ViewInvoices = 'view-invoices';
    case CreateInvoices = 'create-invoices';
    case RecordPayments = 'record-payments';
    case CancelInvoices = 'cancel-invoices';
    case ViewSales = 'view-sales';

    public function label(): string
    {
        return match ($this) {
            self::ViewCustomers => __('View customers'),
            self::AddCustomers => __('Add customers'),
            self::EditCustomers => __('Edit customers'),
            self::RechargeCustomers => __('Recharge'),
            self::ChangeCustomerStatus => __('Change status'),
            self::DeleteCustomers => __('Delete and restore customers'),
            self::ViewCustomerPasswords => __('See customer passwords'),
            self::ResetCustomerDevices => __('Reset devices and unlock logins'),
            self::ViewOnlineSessions => __('View online sessions'),
            self::DisconnectSessions => __('Disconnect sessions'),
            self::ViewLoginLog => __('View login log'),
            self::ViewPlans => __('View plans'),
            self::ManagePlans => __('Add, edit and delete plans'),
            self::ViewNetwork => __('View NAS, pools, IP ranges and address lists'),
            self::ManageNas => __('Manage NAS (restarts FreeRADIUS)'),
            self::ManagePools => __('Manage pools'),
            self::ManageIpRanges => __('Manage IP ranges'),
            self::ManageAddressLists => __('Manage address lists'),
            self::ImportData => __('Import customers and plans'),
            self::ManageConnectionTypes => __('Manage connection types'),
            self::ManageAppSettings => __('App settings'),
            self::ManageRadiusSettings => __('FreeRADIUS settings'),
            self::ViewActivityLog => __('View activity log'),
            self::ViewNotificationLog => __('View message log'),
            self::ManageNotificationRules => __('Manage notification rules'),
            self::ManageNotificationProviders => __('Manage notification providers (API keys)'),
            self::ManageBilling => __('Billing settings and payment methods'),
            self::SetCustomerPricing => __('Free customers, discounts and place of supply'),
            self::ViewInvoices => __('View invoices and payments'),
            self::CreateInvoices => __('Create invoices'),
            self::RecordPayments => __('Record and remove payments'),
            self::CancelInvoices => __('Cancel invoices'),
            self::ViewSales => __('View sales'),
        };
    }

    /**
     * The area heading this box sits under on the Roles screen.
     */
    public function area(): string
    {
        return match ($this) {
            self::ViewCustomers, self::AddCustomers, self::EditCustomers, self::RechargeCustomers,
            self::ChangeCustomerStatus, self::DeleteCustomers, self::ViewCustomerPasswords,
            self::ResetCustomerDevices => __('Customers'),
            self::ViewOnlineSessions, self::DisconnectSessions, self::ViewLoginLog => __('Sessions'),
            self::ViewPlans, self::ManagePlans => __('Plans'),
            self::ViewNetwork, self::ManageNas, self::ManagePools, self::ManageIpRanges,
            self::ManageAddressLists => __('Network'),
            self::ViewNotificationLog, self::ManageNotificationRules,
            self::ManageNotificationProviders => __('Notifications'),
            self::ManageBilling, self::SetCustomerPricing, self::ViewInvoices, self::CreateInvoices,
            self::RecordPayments, self::CancelInvoices, self::ViewSales => __('Billing'),
            default => __('System'),
        };
    }

    /**
     * The view box an action needs, so a role can always reach the page where it acts.
     */
    public function implies(): ?self
    {
        return match ($this) {
            self::AddCustomers, self::EditCustomers, self::RechargeCustomers, self::ChangeCustomerStatus,
            self::DeleteCustomers, self::ViewCustomerPasswords, self::ResetCustomerDevices => self::ViewCustomers,
            self::ManagePlans => self::ViewPlans,
            self::ManageNas, self::ManagePools, self::ManageIpRanges, self::ManageAddressLists => self::ViewNetwork,
            self::ManageNotificationRules, self::ManageNotificationProviders => self::ViewNotificationLog,
            self::CreateInvoices, self::RecordPayments, self::CancelInvoices => self::ViewInvoices,
            self::ViewInvoices => self::ViewCustomers,
            default => null,
        };
    }

    /**
     * The boxes grouped by area, in screen order.
     *
     * @return array<string, list<self>>
     */
    public static function byArea(): array
    {
        $areas = [];

        foreach (self::cases() as $permission) {
            $areas[$permission->area()][] = $permission;
        }

        return $areas;
    }

    /**
     * The given permission names plus the view boxes they imply, unknown names dropped.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function withImplied(array $names): array
    {
        $permissions = array_filter(array_map(self::tryFrom(...), $names));

        foreach ($permissions as $permission) {
            if ($permission->implies() !== null) {
                $permissions[] = $permission->implies();
            }
        }

        return array_values(array_unique(array_map(fn (self $permission): string => $permission->value, $permissions)));
    }
}
