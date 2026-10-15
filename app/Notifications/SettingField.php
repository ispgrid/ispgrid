<?php

namespace App\Notifications;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One input a provider or trigger asks for (an API key, a template ID, a number of days). The Providers and Rules
 * screens draw these, so a provider or trigger never ships a view.
 */
final readonly class SettingField
{
    /**
     * @param  'text'|'secret'|'url'|'number'  $type  a secret is stored encrypted and never shown again
     * @param  list<string|ValidationRule|Closure>  $rules  further checks of the provider's own (text fields only)
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type = 'text',
        public bool $required = true,
        public ?string $help = null,
        public ?int $min = null,
        public ?int $max = null,
        public int $maxLength = 255,
        public array $rules = [],
    ) {}

    public function isSecret(): bool
    {
        return $this->type === 'secret';
    }

    /**
     * @return list<string|ValidationRule|Closure>
     */
    public function rules(): array
    {
        $rules = [$this->required ? 'required' : 'nullable'];

        if ($this->type === 'number') {
            return [...$rules, 'integer', 'between:'.($this->min ?? 0).','.($this->max ?? 1000000)];
        }

        $rules = [...$rules, 'string', 'max:'.$this->maxLength, ...$this->rules];

        return $this->type === 'url' ? [...$rules, 'url:https,http'] : $rules;
    }
}
