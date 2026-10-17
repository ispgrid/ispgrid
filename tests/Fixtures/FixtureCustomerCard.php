<?php

namespace Tests\Fixtures;

use App\Models\Account;
use Livewire\Component;

/**
 * An add-on card on the customer page, for the customer page tests.
 */
class FixtureCustomerCard extends Component
{
    public int $accountId;

    public string $title = 'Fixture card';

    public function render(): string
    {
        $username = Account::withTrashed()->findOrFail($this->accountId)->username;

        return '<div>'.e($this->title).' for '.e($username).'</div>';
    }
}
