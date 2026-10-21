<?php

use App\Billing\Pdf\ReceiptView;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Models\Payment;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One payment's receipt as it prints, with Download PDF and Print. A removed payment's receipt still opens, marked
 * removed.
 */
new #[Title('Receipt')] class extends Component
{
    use AuthorizesEveryRequest;

    #[Locked]
    public int $paymentId;

    #[Locked]
    public int $accountId;

    #[Locked]
    public int $invoiceId;

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
};
