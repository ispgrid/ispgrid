<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Fails when a soft-deleted row already holds the value, so a form can say "restore it" instead of a bare
 * "already taken". Pair it with Rule::unique(...)->withoutTrashed() for the live rows.
 */
class NotUsedByTrashed implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
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
