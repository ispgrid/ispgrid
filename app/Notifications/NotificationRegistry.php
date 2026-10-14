<?php

namespace App\Notifications;

use App\Notifications\Contracts\Provider;
use App\Notifications\Contracts\Trigger;

/**
 * The providers and triggers this install knows. Built-ins are registered in AppServiceProvider; a contributor adds
 * one line there for theirs.
 */
final class NotificationRegistry
{
    /** @var array<string, class-string<Provider>> */
    private array $providers = [];

    /** @var array<string, class-string<Trigger>> */
    private array $triggers = [];

    /**
     * @param  class-string<Provider>  $class
     */
    public function registerProvider(string $class): void
    {
        $this->providers[$class::key()] = $class;
    }

    /**
     * @param  class-string<Trigger>  $class
     */
    public function registerTrigger(string $class): void
    {
        $this->triggers[$class::key()] = $class;
    }

    /**
     * @return array<string, class-string<Provider>>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * @return array<string, class-string<Trigger>>
     */
    public function triggers(): array
    {
        return $this->triggers;
    }

    /**
     * @return ?class-string<Provider>
     */
    public function providerClass(string $key): ?string
    {
        return $this->providers[$key] ?? null;
    }

    /**
     * @param  array<string, string>  $settings
     */
    public function makeProvider(string $key, array $settings): ?Provider
    {
        $class = $this->providerClass($key);

        return $class === null ? null : new $class($settings);
    }

    public function trigger(string $key): ?Trigger
    {
        $class = $this->triggers[$key] ?? null;

        return $class === null ? null : new $class;
    }

    /**
     * The saved values of a provider's secret fields, for scrubbing them out of any text that gets stored.
     *
     * @param  array<string, string>  $settings
     * @return list<string>
     */
    public function secretValues(string $key, array $settings): array
    {
        $class = $this->providerClass($key);

        if ($class === null) {
            return [];
        }

        $values = [];

        foreach ($class::settingFields() as $field) {
            if ($field->isSecret() && ($settings[$field->name] ?? '') !== '') {
                $values[] = (string) $settings[$field->name];
            }
        }

        return $values;
    }
}
