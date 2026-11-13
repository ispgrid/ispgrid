<?php

use App\Actions\Customers\ChangePppoePassword;
use App\Enums\ServiceType;
use App\Http\Middleware\SelfcareSession;
use App\Models\AppSetting;
use App\Models\SelfcareAccount;
use App\Support\CustomerRules;
use App\Support\PublicPage;
use App\Support\Selfcare;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Livewire\Component;

/**
 * The customer's own PPPoE password, while the "Customers can change PPPoE password" switch is on. Asks for the
 * selfcare password again, checks the new one with the staff form's rule, and warns that the router needs it.
 */
new class extends Component
{
    public string $selfcare_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->account();
    }

    public function save(): void
    {
        $account = $this->account();
        $digits = (int) AppSetting::valueOf('phone_digits', '10');

        $this->validate(
            [
                'selfcare_password' => ['required', 'string'],
                'password' => [...CustomerRules::rules(false, false, false, $digits)['password'], 'confirmed'],
            ],
            Arr::only(CustomerRules::messages($digits), ['password.regex', 'password.not_regex']),
            ['password' => __('new PPPoE password')],
        );

        // The password the customer logged in with, which after a mobile login may be another connection's.
        Selfcare::confirmPassword(SelfcareSession::loginAccount() ?? abort(403), $this->selfcare_password, 'selfcare_password', __('The selfcare password is wrong.'));

        app(ChangePppoePassword::class)->handle($account, $this->password);

        session()->flash('selfcare_message', __('Your PPPoE password was changed. Put it in your router.'));
        $this->redirectRoute('selfcare.dashboard');
    }

    public function render(): View
    {
        return $this->view()->layout('layouts::public', [...PublicPage::data(), 'title' => __('PPPoE password')]);
    }

    /**
     * The logged-in customer, only a PPPoE one and only while the switch is on (else not found).
     */
    private function account(): SelfcareAccount
    {
        $account = SelfcareSession::account() ?? abort(403);
        abort_unless(Selfcare::canChangePppoePassword() && $account->service_type === ServiceType::Ppp, 404);

        return $account;
    }
};
