<?php

namespace Tests\Fixtures;

use App\Contracts\CustomerFormTab;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\User;
use RuntimeException;

/**
 * An add-on tab for the customer form tests. Its note is stored as an app setting; "fail-save" makes save() throw
 * and "fail-saved" makes saved() throw.
 */
class FixtureCustomerTab implements CustomerFormTab
{
    /**
     * @var list<array{account: int, state: array<string, mixed>}>
     */
    public static array $finished = [];

    public function key(): string
    {
        return 'fixture';
    }

    public function label(): string
    {
        return 'Fixture tab';
    }

    public function view(): string
    {
        return 'fixtures::customer-tab';
    }

    public function visible(User $user, ?Account $account): bool
    {
        return $user->can('fixture-tab');
    }

    public function state(?Account $account): array
    {
        return ['note' => $account === null ? '' : (string) AppSetting::valueOf("fixture.note.{$account->id}", ''), 'file' => null];
    }

    public function rules(?Account $account): array
    {
        return ['note' => ['nullable', 'string', 'max:20'], 'file' => ['nullable', 'file', 'max:2048']];
    }

    public function warnings(array $state, ?Account $account): array
    {
        return ($state['note'] ?? '') === '' ? ['Note is empty.'] : [];
    }

    public function save(Account $account, array $state): void
    {
        if ($state['note'] === 'fail-save') {
            throw new RuntimeException('The fixture tab failed.');
        }

        AppSetting::put("fixture.note.{$account->id}", (string) $state['note']);
    }

    public function saved(Account $account, array $state): void
    {
        if ($state['note'] === 'fail-saved') {
            throw new RuntimeException('The fixture tab could not store its file.');
        }

        self::$finished[] = ['account' => $account->id, 'state' => $state];
    }
}
