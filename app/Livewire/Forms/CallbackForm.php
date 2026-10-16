<?php

namespace App\Livewire\Forms;

use App\Jobs\SendSmsJob;
use App\Models\CallbackRequest;
use App\Services\Facebook\Pixel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CallbackForm extends Component
{
    public ?int $productId = null;

    #[Validate('required|string|min:2|max:80')]
    public string $name = '';

    #[Validate('required|string|min:9|max:32')]
    public string $phone = '';

    #[Validate('nullable|string|max:500')]
    public string $comment = '';

    public bool $sent = false;

    public function mount(?int $productId = null): void
    {
        $this->productId = $productId;

        if ($user = Auth::user()) {
            $this->name = (string) $user->name;
            $this->phone = (string) ($user->phone ?? '');
        }
    }

    public function send(): void
    {
        $this->validate();

        $key = 'callback:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('phone', __('product.callback_too_many'));

            return;
        }

        RateLimiter::hit($key, 3600);

        $pixel = app(Pixel::class);

        $lead = $pixel->lead([
            'phone' => $this->phone,
            'name' => $this->name,
        ]);

        $browser = $pixel->browserData();

        CallbackRequest::create([
            'user_id' => Auth::id(),
            'product_id' => $this->productId,
            'name' => $this->name,
            'phone' => $this->phone,
            'comment' => $this->comment ?: null,
            'page' => url()->previous(),

            'lead_event_id' => $lead['id'] ?? null,
            'fbp' => $browser['fbp'] ?? null,
            'fbc' => $browser['fbc'] ?? null,
            'ip' => $browser['client_ip_address'] ?? null,
            'user_agent' => $browser['client_user_agent'] ?? null,
            'source_url' => url()->previous(),
            'consented' => $pixel->consented(),
        ]);

        SendSmsJob::dispatch($this->phone, __('sms.callback'));

        $this->sent = true;

        $this->dispatch('pixel', $lead);

        $this->reset('comment');

        $this->dispatch('toast', message: __('product.callback_sent'));
    }

    public function again(): void
    {
        $this->sent = false;
    }

    public function render()
    {
        return view('livewire.forms.callback-form');
    }
}
