<?php

namespace App\Contracts;

use App\Models\Account;
use App\Models\User;

/**
 * An add-on's tab in the customer form, saved with core's Save button. Register it with
 * App\Support\Addons\CustomerFormTabs. The tab's fields live in the form's $addonTabs[key()], so its partial binds
 * them as wire:model="addonTabs.<key>.<field>". That state comes from the browser: validate every value in rules().
 */
interface CustomerFormTab
{
    /**
     * Unique: lowercase letters, digits and "-", e.g. "documents".
     */
    public function key(): string;

    /**
     * The tab's text, translated.
     */
    public function label(): string;

    /**
     * The Blade partial shown in the tab panel, e.g. "documents::customer-tab".
     */
    public function view(): string;

    /**
     * Whether this staff member sees the tab (a null account is a new customer). Core asks again when saving.
     */
    public function visible(User $user, ?Account $account): bool;

    /**
     * The tab's fields and their values when the form opens.
     *
     * @return array<string, mixed>
     */
    public function state(?Account $account): array;

    /**
     * Validation rules by field name; core checks them with the rest of the form.
     *
     * @return array<string, mixed>
     */
    public function rules(?Account $account): array;

    /**
     * Sentences worth telling staff that do not stop the save, e.g. "Missing documents: Aadhaar." While there are
     * any, the tab shows an amber dot; after saving, the message names them.
     *
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    public function warnings(array $state, ?Account $account): array;

    /**
     * Writes the tab's database rows inside core's save transaction, after the customer and their FreeRADIUS rows.
     * It may run up to three times (a deadlock is retried), so it must be safe to repeat. An exception rolls the
     * whole save back; a ValidationException shows on the form.
     *
     * @param  array<string, mixed>  $state  the validated fields
     */
    public function save(Account $account, array $state): void;

    /**
     * Runs once after the save has committed, for work a rollback cannot undo, such as storing uploaded files. If it
     * fails, the customer stays saved, the error is logged and staff are told the tab could not finish.
     *
     * @param  array<string, mixed>  $state  the validated fields
     */
    public function saved(Account $account, array $state): void;
}
