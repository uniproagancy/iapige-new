<?php

namespace App\Livewire\Forms;

use Livewire\Attributes\Validate;
use Livewire\Component;

class Newsletter extends Component
{
    #[Validate('required|email|max:120')]
    public string $email = '';

    public bool $done = false;

    public function submit(): void
    {
        $this->validate();

        // TODO: persist to newsletter_subscribers
        $this->done = true;
    }

    public function render()
    {
        return view('livewire.forms.newsletter');
    }
}
