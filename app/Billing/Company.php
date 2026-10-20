<?php

namespace App\Billing;

/**
 * The ISP's own details printed on every invoice (Billing settings, Company details). Saved as JSON in
 * billing.company; each invoice keeps a copy as it was when issued.
 */
final readonly class Company
{
    /**
     * @param  list<string>  $addressLines
     * @param  list<array{label: string, value: string}>  $extraLines  at most 4, printed under the address
     */
    public function __construct(
        public string $name = '',
        public array $addressLines = [],
        public string $city = '',
        public string $state = '',
        public string $postalCode = '',
        public string $country = '',
        public string $phone = '',
        public string $email = '',
        public string $website = '',
        public string $taxNumber = '',
        public array $extraLines = [],
        public string $notes = '',
        public string $bankDetails = '',
        public string $terms = '',
        public bool $showSignature = true,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $text = fn (string $key): string => is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
        $lines = is_array($data['address_lines'] ?? null) ? $data['address_lines'] : [];
        $extra = is_array($data['extra_lines'] ?? null) ? $data['extra_lines'] : [];

        return new self(
            name: $text('name'),
            addressLines: array_values(array_filter(array_map(fn (mixed $line): string => is_scalar($line) ? trim((string) $line) : '', $lines), fn (string $line): bool => $line !== '')),
            city: $text('city'),
            state: $text('state'),
            postalCode: $text('postal_code'),
            country: strtoupper($text('country')),
            phone: $text('phone'),
            email: $text('email'),
            website: $text('website'),
            taxNumber: $text('tax_number'),
            extraLines: array_slice(array_values(array_filter(array_map(
                fn (mixed $line): array => [
                    'label' => is_array($line) && is_scalar($line['label'] ?? null) ? trim((string) $line['label']) : '',
                    'value' => is_array($line) && is_scalar($line['value'] ?? null) ? trim((string) $line['value']) : '',
                ],
                $extra,
            ), fn (array $line): bool => $line['label'] !== '' || $line['value'] !== '')), 0, 4),
            notes: $text('notes'),
            bankDetails: $text('bank_details'),
            terms: $text('terms'),
            showSignature: (bool) ($data['show_signature'] ?? true),
        );
    }

    /**
     * @return array{name: string, address_lines: list<string>, city: string, state: string, postal_code: string, country: string, phone: string, email: string, website: string, tax_number: string, extra_lines: list<array{label: string, value: string}>, notes: string, bank_details: string, terms: string, show_signature: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'address_lines' => $this->addressLines,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'tax_number' => $this->taxNumber,
            'extra_lines' => $this->extraLines,
            'notes' => $this->notes,
            'bank_details' => $this->bankDetails,
            'terms' => $this->terms,
            'show_signature' => $this->showSignature,
        ];
    }
}
