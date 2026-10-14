# Contributing to ISPGrid

Thanks for helping. Work on a branch from `master` (no `/` in branch names), keep commits small with a short
imperative subject, and run `php artisan test --compact` and `vendor/bin/pint --dirty` before opening a pull request.
Every UI string goes through `__()` and needs a Hindi entry in `lang/hi.json`.

## Add a notification provider

A provider delivers one message on one channel (SMS or WhatsApp). It decides nothing else: the panel picks the
customers, fills the values, stops duplicates, retries, logs and keeps keys secret.

1. Create `app/Notifications/Providers/YourProvider.php` implementing `App\Notifications\Contracts\Provider`:
   - `key()`: lowercase and stable (it is stored in rules and the log), e.g. `textlocal`.
   - `settingFields()`: connection settings as `SettingField`s. Mark API keys `'secret'`.
   - `templateFields()`: what a rule fills in to pick a template (a template ID, or a name and language).
   - `send(OutgoingMessage $message)`: call the API with a 15 second timeout and return `SendResult::sent($id)` or
     `SendResult::failed($reason, retryable: ..., retryAfterSeconds: ...)`. Map every API error; mark rate limits,
     server errors and temporary outages retryable. Throw only when the API cannot be reached.
   - `testConnection()`: check the key without messaging anyone, or return false from `supportsConnectionTest()`.
2. Register it in `AppServiceProvider` next to the built-ins: `$registry->registerProvider(YourProvider::class);`.
3. Add an entry to the `providers` dataset in `tests/Feature/Notifications/ProviderContractTest.php` with fake
   responses for a sent message, a refusal that echoes the key back, and a rate limit of 30 seconds. Every contract
   test must pass. Add a test of the exact request your API expects (see `WacrmWhatsAppTest.php`).

Checklist: no secret in any returned reason; no secret in URLs (use headers); values sent in the given order;
the number sent in the format the API wants (`$message->to` is E.164, e.g. `+919876543210`).

## Add a trigger

A trigger is a kind of event rules can send on.

1. Create `app/Notifications/Triggers/YourTrigger.php` implementing `App\Notifications\Contracts\Trigger`:
   - Scheduled (`scheduled()` true): `due()` returns the query of customers due now, by calendar day, so a run that
     falls outside the sending hours is caught up later. Event triggers return an empty query and are fired with
     `app(Notifier::class)->fire(YourTrigger::key(), $account)` where the event happens, inside its transaction.
   - `onceKey()`: unique for each message that must go out once. Include what makes a new message right (the
     expiry date for reminders, the recharge id for a recharge).
   - `placeholders()`: the values this trigger can fill, from `App\Notifications\Placeholders`.
2. Register it in `AppServiceProvider`: `$registry->registerTrigger(YourTrigger::class);`.
3. Test it like `tests/Feature/Notifications/TriggersTest.php`: who is due at a frozen time, the date edges, and that a
   second run sends nothing.
