<?php

namespace App\Notifications;

/**
 * One input a provider or trigger asks for (an API key, a template ID, a number of days). The Providers and Rules
 * screens draw these, so a provider or trigger never ships a view.
 */
final readonly class SettingField
{
    /**
     * @param  'text'|'secret'|'url'|'number'  $type  a secret is stored encrypted and never shown again
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
    ) {}

    public function isSecret(): bool
    {
        return $this->type === 'secret';
    }

    /**
     * @return list<string>
     */
    public function rules(): array
    {
        $rules = [$this->required ? 'required' : 'nullable'];

        if ($this->type === 'number') {
            return [...$rules, 'integer', 'between:'.($this->min ?? 0).','.($this->max ?? 1000000)];
        }

        $rules = [...$rules, 'string', 'max:'.$this->maxLength];

        return $this->type === 'url' ? [...$rules, 'url:https,http'] : $rules;
    }
}
