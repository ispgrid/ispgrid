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
