<?php

namespace App\Livewire\Forms;

use App\Models\CallbackRequest;
use App\Models\Product;
use App\Services\Facebook\Pixel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * "Call me back" on a product page.
 *
 * The form asks for a name and a number and nothing else: every extra field
 * costs enquiries, and the operator can ask the rest on the phone.
 */
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

        /*
         * One request a minute from an address, five an hour. A shop that gets
         * flooded with fake leads stops answering the real ones.
         */
        $key = 'callback:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('phone', __('product.callback_too_many'));

            return;
        }

        RateLimiter::hit($key, 3600);

        CallbackRequest::create([
            'user_id' => Auth::id(),
            'product_id' => $this->productId,
            'name' => $this->name,
            'phone' => $this->phone,
            'comment' => $this->comment ?: null,
            'page' => url()->previous(),
        ]);

        $this->sent = true;

        // a phone number left on purpose is the definition of a lead
        $this->dispatch('pixel', app(Pixel::class)->lead([
            'phone' => $this->phone,
            'name' => $this->name,
        ]));

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
