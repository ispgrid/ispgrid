<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Fails when another customer gave this username up by a rename: its sessions and login log stay under it, so a new
 * holder would see them as its own. The customer that gave it up may take it back.
 */
class NotRetiredUsername implements ValidationRule
{
    public function __construct(private ?int $accountId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = DB::table('retired_usernames')->where('username', (string) $value)
            ->when($this->accountId !== null, fn ($query) => $query->where('account_id', '!=', $this->accountId))
            ->exists();

        if ($taken) {
            $fail(__('Another customer used this username before, and its sessions stay under it. Choose another.'));
        }
    }
}
