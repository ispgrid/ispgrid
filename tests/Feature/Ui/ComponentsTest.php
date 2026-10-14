<?php

it('links the input label to its field and shows the field error', function () {
    $this->withViewErrors(['email' => 'Enter a valid email address.'])
        ->blade('<x-ui.input name="email" label="Email" />')
        ->assertSee('<label for="field-email"', false)
        ->assertSee('id="field-email"', false)
        ->assertSee('aria-invalid="true"', false)
        ->assertSee('Enter a valid email address.');
});

it('takes the field name from wire:model', function () {
    $this->withViewErrors(['form.name' => 'Name is required.'])
        ->blade('<x-ui.input wire:model="form.name" label="Name" />')
        ->assertSee('id="field-form-name"', false)
        ->assertSee('Name is required.');
});

it('renders a button with an href as a link', function () {
    $this->blade('<x-ui.button href="/staff">Staff</x-ui.button>')
        ->assertSee('<a href="/staff"', false)
        ->assertDontSee('<button', false);
});

it('only submits forms from buttons that ask for it', function () {
    $this->blade('<x-ui.button>Cancel</x-ui.button>')->assertSee('type="button"', false);
    $this->blade('<x-ui.button type="submit">Save</x-ui.button>')->assertSee('type="submit"', false);
});

it('marks the selected option', function () {
    $this->blade(
        '<x-ui.select name="expiry_mode" label="Expiry" :options="$options" selected="midnight" />',
        ['options' => ['exact' => 'Exact time', 'midnight' => 'Midnight']],
    )->assertSee('<option value="midnight" selected', false)
        ->assertDontSee('<option value="exact" selected', false);
});

it('opens a modal only for its own name', function () {
    $this->blade('<x-ui.modal name="add-staff">Body</x-ui.modal>')
        ->assertSee("name: 'add-staff'", false)
        ->assertSee('Body');
});

it('keeps a danger toast until it is dismissed', function () {
    $this->blade('<x-ui.toast />')
        ->assertSee("if (toast.variant !== 'danger')", false)
        ->assertSee('aria-label="Dismiss"', false);
});

it('shows a toast the previous page left in the session', function () {
    $this->withSession(['toast' => 'Plan saved.'])
        ->blade('<x-ui.toast />')
        ->assertSee("x-init=\"add('Plan saved.')\"", false);
});

it('marks only the tabs that hold an error', function () {
    $view = $this->blade(
        '<x-ui.tabs :tabs="$tabs" :invalid="[\'speed\']">Body</x-ui.tabs>',
        ['tabs' => ['general' => 'General', 'speed' => 'Speed']],
    );

    $view->assertSeeInOrder(['General', 'Speed', '(has errors)']);
    expect(substr_count((string) $view, '(has errors)'))->toBe(1);
});

it('folds a menu group away unless one of its pages is open', function () {
    $this->blade('<x-ui.nav-group name="network" heading="Network" collapsible>Links</x-ui.nav-group>')
        ->assertSee("x-data=\"navGroup('network', false)\"", false)
        ->assertSee('aria-expanded="false"', false)
        ->assertSee('x-cloak', false);

    $this->blade('<x-ui.nav-group name="network" heading="Network" collapsible :active="true">Links</x-ui.nav-group>')
        ->assertSee("x-data=\"navGroup('network', true)\"", false)
        ->assertSee('aria-expanded="true"', false)
        ->assertDontSee('x-cloak', false);
});

it('marks the label of a required field with an asterisk', function (string $component) {
    $this->blade("<x-ui.{$component} name=\"fullname\" label=\"Full name\" required />")
        ->assertSee('Full name<span class="text-red-600 dark:text-red-400" aria-hidden="true"> *</span>', false);

    $this->blade("<x-ui.{$component} name=\"company\" label=\"Company\" :required=\"false\" />")
        ->assertDontSee('aria-hidden="true"> *</span>', false);
})->with(['input', 'select', 'textarea']);

it('keeps an English-only field in English and warns while non-English text is typed', function () {
    $this->blade('<x-ui.input name="username" label="Username" english />')
        ->assertSee('lang="en"', false)
        ->assertSee('spellcheck="false"', false)
        ->assertSee('x-on:input="notEnglish =', false)
        ->assertSee('Only English letters, digits and symbols: FreeRADIUS cannot read other characters.');

    $this->blade('<x-ui.input name="company" label="Company" />')
        ->assertDontSee('lang="en"', false)
        ->assertDontSee('notEnglish', false);
});
