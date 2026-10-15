<?php

namespace App\Rules;

use App\Models\Account;
use App\Models\AddressList;
use App\Models\ConnectionType;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fails when a soft-deleted row already holds the value, so a form can say "restore it" instead of a bare
 * "already taken". Pair it with Rule::unique(...)->withoutTrashed() for the live rows.
 */
class NotUsedByTrashed implements ValidationRule
{
    /**
     * @param  class-string<Account|AddressList|ConnectionType|Nas|Plan|Pool|User>  $model  a model with SoftDeletes
     */
    public function __construct(
        private string $model,
        private string $column,
        private string $message,
        private ?int $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = $this->model::onlyTrashed()
            ->where($this->column, $value)
            ->when($this->ignoreId !== null, fn ($query) => $query->whereKeyNot($this->ignoreId))
            ->exists();

        if ($taken) {
            $fail($this->message);
        }
    }
}
