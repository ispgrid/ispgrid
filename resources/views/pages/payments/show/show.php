<?php

use App\Actions\Billing\EditPayment;
use App\Billing\Pdf\ReceiptView;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One payment's receipt as it prints, with Download PDF and Print. A removed payment's receipt still opens, marked
 * removed. Staff who record payments can correct its method, reference, received at and note (Edit payment). The
 * note is for staff: it shows here, never on the PDF.
 */
new #[Title('Receipt')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    #[Locked]
    public int $paymentId;

    #[Locked]
    public int $accountId;

    #[Locked]
    public int $invoiceId;

    public string $edit_method_id = '';

    public string $edit_reference = '';

    public string $edit_paid_at = '';

    public string $edit_note = '';

    public function mount(int $payment): void
    {
        $this->authorizeAccess();
        $found = Payment::withTrashed()->findOrFail($payment);
        $this->paymentId = $found->id;
        $this->accountId = $found->account_id;
        $this->invoiceId = $found->invoice_id;
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('view-invoices'), 403);
    }

    /**
     * Every string the receipt prints (the same as its PDF).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function printed(): array
    {
        return ReceiptView::for(Payment::withTrashed()->findOrFail($this->paymentId));
    }

    /**
     * The staff note, shown on this page only.
     */
    #[Computed]
    public function note(): ?string
    {
        return Payment::withTrashed()->findOrFail($this->paymentId)->note;
    }

    /**
     * Edit payment: for staff who record payments, on a payment not removed, of a customer not deleted.
     */
    #[Computed]
    public function canEdit(): bool
    {
        return Gate::allows('record-payments')
            && Payment::query()->whereKey($this->paymentId)->exists()
            && Account::query()->whereKey($this->accountId)->exists();
    }

    /**
     * The payment methods turned on, plus this payment's own method when it has since been turned off.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function editMethods(): array
    {
        $current = Payment::withTrashed()->findOrFail($this->paymentId)->payment_method_id;

        return PaymentMethod::query()->where(fn ($query) => $query->where('active', true)->orWhere('id', $current))
            ->orderBy('position')->orderBy('id')->pluck('name', 'id')->all();
    }

    public function openEditPayment(): void
    {
        abort_unless($this->canEdit, 403);
        $payment = Payment::query()->findOrFail($this->paymentId);
        $this->resetValidation();
        $this->edit_method_id = (string) $payment->payment_method_id;
        $this->edit_reference = (string) $payment->reference;
        $this->edit_paid_at = $payment->paid_at->format('Y-m-d\TH:i');
        $this->edit_note = (string) $payment->note;
        $this->dispatch('modal-open', name: 'edit-payment');
    }

    public function editPayment(EditPayment $edit): void
    {
        unset($this->canEdit);
        abort_unless($this->canEdit, 403);
        $this->edit_reference = trim($this->edit_reference);
        $this->edit_note = trim($this->edit_note);
        $this->validate([
            'edit_method_id' => ['required', Rule::in(array_map(strval(...), array_keys($this->editMethods)))],
            'edit_reference' => ['nullable', 'string', 'max:100'],
            'edit_paid_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:now'],
            'edit_note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'edit_method_id' => __('method'),
            'edit_reference' => __('reference'),
            'edit_paid_at' => __('received at'),
            'edit_note' => __('note'),
        ]);

        $edit->handle(
            $this->paymentId,
            (int) $this->edit_method_id,
            $this->edit_reference,
            Carbon::createFromFormat('!Y-m-d\TH:i', $this->edit_paid_at) ?: now(),
            $this->edit_note,
        );

        unset($this->printed, $this->editMethods, $this->note);
        $this->dispatch('modal-close', name: 'edit-payment');
        $this->toast(__('Payment changed.'));
    }
};
