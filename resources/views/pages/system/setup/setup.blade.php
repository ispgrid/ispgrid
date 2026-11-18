<section class="mx-auto grid w-full max-w-4xl grid-cols-1 gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <x-ui.heading level="1" size="xl">{{ __('Set up ISPGrid') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('A few steps so the panel is ready for your first customer. Skip any step: everything here can be changed later in the settings.') }}</x-ui.text>
        </div>
        <x-ui.button variant="ghost" size="sm" wire:click="finish" wire:confirm="{{ __('Skip the rest of the setup? You can do all of it later in the settings.') }}">{{ __('Skip setup') }}</x-ui.button>
    </div>

    <ol class="flex flex-wrap gap-2 text-sm" aria-label="{{ __('Steps') }}">
        @foreach ($this->steps as $key => $label)
            <li @class([
                'rounded-full px-3 py-1',
                'bg-ink text-white dark:bg-signal dark:text-ink' => $step === $key,
                'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200' => $step !== $key && in_array($key, $done, true),
                'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $step !== $key && ! in_array($key, $done, true),
            ]) @if ($step === $key) aria-current="step" @endif>{{ $loop->iteration }}. {{ $label }}</li>
        @endforeach
    </ol>

    @if ($step === 'region')
        <form wire:submit="saveRegion" class="grid grid-cols-1 gap-6">
            <x-ui.card class="grid grid-cols-1 gap-5">
                <div>
                    <x-ui.heading>{{ __('Country and ISP') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('The country fills the currency, dates, phone numbers and ID labels below. Check them before saving.') }}</x-ui.text>
                </div>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.select wire:model.live="country" :label="__('Country')" :options="$this->countries" required />
                    <x-ui.input wire:model="isp_name" maxlength="100" :label="__('ISP name')" :description="__('On the notice page, the Selfcare portal and invoices.')" />
                    <x-ui.select wire:model="locale" :label="__('Panel language')" :options="$this->languages" />
                    <x-ui.select wire:model="date_format" :label="__('Date format')" :options="$this->dateFormats" />
                </div>
            </x-ui.card>

            <x-ui.card class="grid grid-cols-1 gap-5">
                <x-ui.heading>{{ __('Money') }}</x-ui.heading>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-4">
                    <x-ui.input wire:model="currency_code" maxlength="3" :label="__('Currency code')" />
                    <x-ui.input wire:model="currency_symbol" maxlength="8" :label="__('Symbol')" />
                    <x-ui.input wire:model="currency_decimals" type="number" min="0" max="3" :label="__('Decimals')" />
                    <x-ui.select wire:model="number_grouping" :label="__('Number grouping')" :options="$this->groupings" />
                </div>
            </x-ui.card>

            <x-ui.card class="grid grid-cols-1 gap-5">
                <x-ui.heading>{{ __('Customer details') }}</x-ui.heading>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                    <x-ui.input wire:model="phone_country_code" :label="__('Phone country code')" placeholder="+91" />
                    <x-ui.input wire:model="phone_digits" type="number" min="4" max="15" :label="__('Phone number digits')" :description="__('Without the country code.')" />
                    <x-ui.input wire:model="phone_digits_min" type="number" min="4" max="15" :label="__('Shortest phone number')" :description="__('Leave empty when every number has the full length.')" />
                    <x-ui.input wire:model="tax_id_label" :label="__('Tax ID label')" :description="__('Leave empty to hide the field.')" />
                    <x-ui.input wire:model="business_id_label" :label="__('Business ID label')" :description="__('Leave empty to hide the field.')" />
                </div>
            </x-ui.card>

            <div class="flex flex-wrap justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="skip">{{ __('Skip this step') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save and continue') }}</x-ui.button>
            </div>
        </form>
    @elseif ($step === 'nas')
        <x-ui.card class="grid grid-cols-1 gap-5">
            <div>
                <x-ui.heading>{{ __('First NAS') }}</x-ui.heading>
                <x-ui.text muted class="mt-1">{{ __('The MikroTik router your PPPoE customers connect to. FreeRADIUS answers only the NAS added here.') }}</x-ui.text>
            </div>

            @if ($nasId === null)
                <form wire:submit="saveNas" class="grid grid-cols-1 gap-5">
                    <x-ui.input wire:model.live.debounce.400ms="shortname" maxlength="32" english :label="__('NAS name')" placeholder="office-nas" required :description="$this->usesTunnel ? __('Letters, digits, - and _ only.') : null" />

                    @if ($this->usesTunnel)
                        <div class="grid grid-cols-1 gap-2">
                            <x-ui.text>{{ $this->routers === 'wireguard'
                                ? __('1. On this server, run as root (it adds the NAS to the WireGuard tunnel and prints the commands for the NAS):')
                                : __('1. On this server, run as root (it adds the NAS to the L2TP/IPsec tunnel and prints the commands for the NAS):') }}</x-ui.text>
                            @if ($this->peerCommand)
                                <pre class="rounded-lg bg-zinc-100 p-3 text-sm break-all whitespace-pre-wrap dark:bg-zinc-800" data-command>{{ $this->peerCommand }}</pre>
                            @else
                                <x-ui.text muted>{{ __('Type the NAS name above to see the command.') }}</x-ui.text>
                            @endif
                            <x-ui.text>{{ __('2. Paste the commands it prints on the NAS. In the /radius line, put the secret below in place of THE-SECRET-OF-THIS-NAS-IN-ISPGRID.') }}</x-ui.text>
                            <x-ui.text>{{ __('3. Type the tunnel address the script gave the NAS, and save.') }}</x-ui.text>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                        <x-ui.input wire:model="nasname" :label="$this->usesTunnel ? __('Tunnel address of the NAS') : __('IP address of the NAS')" placeholder="{{ $this->usesTunnel ? '10.250.0.2' : '192.168.88.1' }}" required :description="$this->usesTunnel ? null : __('The address the NAS sends RADIUS from.')" />
                        <x-ui.input wire:model="secret" english :label="__('Secret')" required />
                        <x-ui.input wire:model="routeros_version" :label="__('RouterOS version')" placeholder="7.19.4" required :description="__('As /system resource print shows it.')" />
                    </div>
                    <div class="flex flex-wrap justify-between gap-3">
                        <x-ui.button variant="ghost" wire:click="back">{{ __('Back') }}</x-ui.button>
                        <div class="flex flex-wrap gap-3">
                            <x-ui.button variant="secondary" wire:click="skip">{{ __('Skip this step') }}</x-ui.button>
                            <x-ui.button type="submit">{{ __('Save NAS') }}</x-ui.button>
                        </div>
                    </div>
                </form>
            @else
                <x-ui.alert variant="success">{{ __('NAS :name saved.', ['name' => $shortname]) }}</x-ui.alert>
                <x-ui.text>{{ $this->usesTunnel
                    ? __('Paste this on the NAS too, so PPPoE logins go to RADIUS (the /radius lines came from the tunnel script, with the secret :secret):', ['secret' => $secret])
                    : __('Paste these commands on the NAS:') }}</x-ui.text>
                <pre class="rounded-lg bg-zinc-100 p-3 text-sm break-all whitespace-pre-wrap dark:bg-zinc-800" data-command>{{ implode("\n", $this->radiusCommands) }}</pre>
                <div class="flex justify-end">
                    <x-ui.button wire:click="skip">{{ __('Continue') }}</x-ui.button>
                </div>
            @endif
        </x-ui.card>
    @elseif ($step === 'pools')
        <form wire:submit="savePools" class="grid grid-cols-1 gap-6">
            <x-ui.card class="grid grid-cols-1 gap-5">
                <div>
                    <x-ui.heading>{{ __('Customers\' pool') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('The addresses PPPoE customers get. The pool lives on the NAS; the panel only sends its name.') }}</x-ui.text>
                </div>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model.live.debounce.400ms="pool_name" english maxlength="64" :label="__('Pool name')" required />
                    <x-ui.input wire:model.live.debounce.400ms="pool_range" :label="__('IP addresses')" placeholder="10.10.0.0/22" required :description="__('A subnet like 10.10.0.0/22 or a range like 10.10.0.2-10.10.3.254.')" />
                </div>
                @if ($this->poolCommands !== [])
                    <x-ui.text>{{ __('If this pool does not exist on the NAS yet (the first command lists nothing), paste the second. Use the NAT line only if this NAS gives customers the internet; its internet port must be in the WAN interface list.') }}</x-ui.text>
                    <pre class="rounded-lg bg-zinc-100 p-3 text-sm break-all whitespace-pre-wrap dark:bg-zinc-800" data-command>{{ implode("\n", $this->poolCommands) }}</pre>
                    @if ($this->localAddress)
                        <x-ui.text muted>{{ __('The NAS keeps :address for its own end of the PPPoE link (local address in the PPP profile), so it is left out of the pool.', ['address' => $this->localAddress]) }}</x-ui.text>
                    @endif
                    <x-ui.alert variant="warning">{{ __('Then connect one test customer and check that these addresses can reach the internet.') }}</x-ui.alert>
                @endif
            </x-ui.card>

            <x-ui.card class="grid grid-cols-1 gap-5">
                <div>
                    <x-ui.heading>{{ __('Disabled pool') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('Expired and disabled customers get an address from this pool and see the notice page instead of the internet.') }}</x-ui.text>
                </div>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model.live.debounce.400ms="disabled_pool_name" english maxlength="64" :label="__('Pool name')" required />
                    <x-ui.input wire:model.live.debounce.400ms="disabled_range" :label="__('IP addresses')" placeholder="172.16.250.0/24" required />
                </div>
                @if ($this->disabledCommands !== [])
                    <x-ui.text>{{ __('If this pool does not exist on the NAS yet, paste the pool line, then the rules that send these customers to the notice page on :ip:8088.', ['ip' => $this->radiusIp]) }}</x-ui.text>
                    <pre class="rounded-lg bg-zinc-100 p-3 text-sm break-all whitespace-pre-wrap dark:bg-zinc-800" data-command>{{ implode("\n", $this->disabledCommands) }}</pre>
                    <x-ui.alert variant="warning">{{ __('These addresses must not reach the internet, so this pool gets no NAT rule of its own: from one of them, a web page on http:// should show the notice page and nothing else should open.') }}</x-ui.alert>
                @endif
            </x-ui.card>

            <div class="flex flex-wrap justify-between gap-3">
                <x-ui.button variant="ghost" wire:click="back">{{ __('Back') }}</x-ui.button>
                <div class="flex flex-wrap gap-3">
                    <x-ui.button variant="secondary" wire:click="skip">{{ __('Skip this step') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Save and continue') }}</x-ui.button>
                </div>
            </div>
        </form>
    @elseif ($step === 'plan')
        <form wire:submit="savePlan">
            <x-ui.card class="grid grid-cols-1 gap-5">
                <div>
                    <x-ui.heading>{{ __('First plan') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('More plans, burst, data limits and time slots are on the Plans page.') }}</x-ui.text>
                </div>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model="plan_name" maxlength="255" :label="__('Plan name')" placeholder="Home 50" required />
                    <x-ui.input wire:model="price" inputmode="decimal" :label="__('Price (:currency)', ['currency' => $this->currencyCode])" required />
                    <x-ui.input wire:model="download_speed" english :label="__('Download speed')" placeholder="50M" required :description="__('A whole number with k or M, e.g. 512k or 50M.')" />
                    <x-ui.input wire:model="upload_speed" english :label="__('Upload speed')" placeholder="50M" required />
                    <x-ui.input wire:model="validity" type="number" min="1" :label="__('Validity (days)')" required />
                </div>
                <div class="flex flex-wrap justify-between gap-3">
                    <x-ui.button variant="ghost" wire:click="back">{{ __('Back') }}</x-ui.button>
                    <div class="flex flex-wrap gap-3">
                        <x-ui.button variant="secondary" wire:click="skip">{{ __('Skip this step') }}</x-ui.button>
                        <x-ui.button type="submit">{{ __('Save and continue') }}</x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        </form>
    @elseif ($step === 'india')
        <form wire:submit="saveIndia" class="grid grid-cols-1 gap-6">
            <x-ui.card class="grid grid-cols-1 gap-5">
                <div>
                    <x-ui.heading>{{ __('GST invoices') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('GST at 18% with SAC 998422: CGST and SGST for customers in your state, IGST for others.') }}</x-ui.text>
                </div>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model="company_name" maxlength="150" :label="__('Legal name')" required />
                    <x-ui.input wire:model.live.blur="gstin" maxlength="15" :label="__('GSTIN')" placeholder="07ABCDE1234F1Z5" :description="__('Leave empty if you are not registered for GST.')" />
                    <x-ui.input wire:model="address_line1" maxlength="150" :label="__('Address')" />
                    <x-ui.input wire:model="city" maxlength="60" :label="__('City')" />
                    <x-ui.select wire:model="gst_state" :label="__('State')" :options="$this->gstStates" :placeholder="__('Choose a state')" required />
                    <x-ui.input wire:model="postal_code" inputmode="numeric" maxlength="6" :label="__('PIN code')" />
                </div>
                <x-ui.checkbox wire:model="invoicing" :label="__('Make invoices for recharges')" />
            </x-ui.card>

            <x-ui.card class="grid grid-cols-1 gap-5">
                <div>
                    <x-ui.heading>{{ __('UPI') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('Customers pay to this UPI ID and send a screenshot. Online gateways (Paytm, PhonePe, Razorpay, Cashfree, PayU, Easebuzz) are in Online payments.') }}</x-ui.text>
                </div>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model="upi_id" maxlength="60" :label="__('UPI ID')" placeholder="linkio@okhdfcbank" />
                    <x-ui.input wire:model="upi_number" maxlength="20" :label="__('UPI number')" />
                </div>
            </x-ui.card>

            <x-ui.card class="grid grid-cols-1 gap-5">
                <div>
                    <x-ui.heading>{{ __('Licence records') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('The DoT licence asks ISPs to keep IPDR records and customer documents. Check the period your licence states.') }}</x-ui.text>
                </div>
                <x-ui.checkbox wire:model="keep_two_years" :label="__('Keep session history for 2 years (730 days)')" />
                <x-ui.text>{{ __('IPDR reports and customer documents (KYC) are add-ons. To install them, run on this server:') }}</x-ui.text>
                <pre class="rounded-lg bg-zinc-100 p-3 text-sm break-all whitespace-pre-wrap dark:bg-zinc-800" data-command>{{ implode("\n", $this->indiaAddons) }}</pre>
            </x-ui.card>

            <div class="flex flex-wrap justify-between gap-3">
                <x-ui.button variant="ghost" wire:click="back">{{ __('Back') }}</x-ui.button>
                <div class="flex flex-wrap gap-3">
                    <x-ui.button variant="secondary" wire:click="skip">{{ __('Skip this step') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Save and continue') }}</x-ui.button>
                </div>
            </div>
        </form>
    @else
        <x-ui.card class="grid grid-cols-1 gap-5">
            <x-ui.heading>{{ __('Ready') }}</x-ui.heading>
            @if ($this->summary !== [])
                <x-ui.text>{{ __('Saved: :steps.', ['steps' => implode(', ', $this->summary)]) }}</x-ui.text>
            @else
                <x-ui.text>{{ __('Every step was skipped. Nothing was changed.') }}</x-ui.text>
            @endif
            <x-ui.text muted>{{ __('Next, when you need them:') }}</x-ui.text>
            <ul class="grid list-disc grid-cols-1 gap-1 ps-5 text-sm">
                @foreach ($this->nextPages as $route => $label)
                    <li><button type="button" class="text-left underline underline-offset-2 hover:no-underline" wire:click="finish('{{ $route }}')">{{ $label }}</button></li>
                @endforeach
            </ul>
            <x-ui.text muted>{{ __('Opening one of these finishes the setup.') }}</x-ui.text>
            <div class="flex flex-wrap justify-between gap-3">
                <x-ui.button variant="ghost" wire:click="back">{{ __('Back') }}</x-ui.button>
                <x-ui.button wire:click="finish">{{ __('Finish setup') }}</x-ui.button>
            </div>
        </x-ui.card>
    @endif
</section>
