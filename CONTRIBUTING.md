# Contributing to ISPGrid

Thanks for helping. Work on a branch from `master` (no `/` in branch names), keep commits small with a short
imperative subject, and run `php artisan test --compact` and `vendor/bin/pint --dirty` before opening a pull request.
Every UI string goes through `__()` and needs a Hindi entry in `lang/hi.json`.

Questions go to [Discussions](https://github.com/ispgrid/ispgrid/discussions), bugs and feature requests to Issues,
and security problems to private reporting (see `SECURITY.md`). Everyone taking part follows the
[Code of Conduct](CODE_OF_CONDUCT.md).

## Add a language

The panel is written in English; every other language is a translation file. Hindi (`hi`) is the example to copy.

1. Copy `lang/hi.json` to `lang/<code>.json` (`<code>` as in Laravel, e.g. `ta`, `bn`, `pt_BR`) and translate the
   values. The keys are the English text, so leave them as they are. Keep every `:placeholder` (`:count`, `:name`) and
   the `|` between the singular and plural forms of a string.
2. Copy `lang/hi/` to `lang/<code>/` and translate Laravel's own messages there (`auth.php`, `pagination.php`,
   `passwords.php`, `validation.php`).
3. Add the language to `locales` in `config/app.php`, with its name written in that language:
   `'ta' => 'தமிழ்',`. It then shows in App settings and on each staff member's Profile.
4. If Geist has no letters for the script, add a font: put an OFL-licensed `.ttf` in `resources/fonts`, add an
   `@font-face` with the script's `unicode-range` in `resources/css/app.css` (see Noto Sans Devanagari), add it to
   `--font-sans`, and name it under "License" in `README.md`.
5. Run `php artisan test --compact tests/Feature/Support/TranslationsTest.php`. It fails for any string the panel
   uses that the new file lacks, and for a translation that drops a `:placeholder`.

Translate only the panel. Values that reach FreeRADIUS and the NAS (plan names, attribute values, messages sent to the
NAS) must stay in English letters; the panel refuses anything else.

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

## Write an add-on

An add-on is a Composer package that adds a feature without changing core. `ispgrid/ipdr` (Session records) is the
example to copy.

- **composer.json:** `"extra": {"ispgrid": {"name": "Shown name", "core": ["0.1"], "providers": ["Vendor\Pkg\PkgServiceProvider"]}}`.
  `core` lists the core minor series it supports; core loads only add-ons that list the running series. Do not use
  `extra.laravel`: core registers the providers itself.
- **What it may use:** `App\Contracts` (such as `SessionKeeper`), the registries `App\Support\Permissions`,
  `App\Support\Navigation\Navigation` and `App\Support\SessionKeepers`, `ActivityFormatter::addEventLabel()`, the
  `x-ui.*` Blade components, and core actions such as `RechargeAccount`. Everything else in core is internal and may
  change in any release.
- **What it must not do:** write `radcheck`, `radreply`, `radusergroup`, `radacct` or any other `rad*` table.
  Anything FreeRADIUS must do differently is a core change.
- **Its own database:** name it `ispgrid_` and then lowercase letters, digits or `_` (the panel's database user can
  create only those, and the nightly backup dumps every one), create it in a migration, and list it in
  `extra.ispgrid.databases`, e.g. `"databases": ["ispgrid_ipdr"]`. Keep the migrations in `database/migrations`.
  The installer refuses a database that another add-on lists. When the add-on is removed, its data is kept;
  `--remove-addon=vendor/pkg --delete-data` drops the listed databases and removes its migrations from core's
  `migrations` table, so installing it again starts fresh.
- **Permissions:** register the box in the provider and insert its `permissions` row (guard `web`) in a migration.
- **Strings:** `__()` everywhere, and a `lang/hi.json` loaded with `loadJsonTranslationsFrom()`.
- **Develop locally:** in the core checkout, `composer config repositories.my-addon path ../path/to/addon` and
  `composer require vendor/pkg:@dev`. Do not commit the changed `composer.json` and `composer.lock`.
- **Test:** `ISPGRID_ADDONS=on php vendor/bin/pest --test-directory=vendor/vendor/pkg/tests vendor/vendor/pkg/tests`.
  Core's own tests run with add-ons off.
- **Install on a server:** `sudo bash install.sh --addon=vendor/pkg` (see `install.sh --help`).
