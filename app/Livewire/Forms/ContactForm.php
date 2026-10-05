<?php

namespace App\Livewire\Forms;

use Livewire\Attributes\Validate;
use Livewire\Component;

class ContactForm extends Component
{
    #[Validate('required|string|min:2|max:80')]
    public string $name = '';

    #[Validate('required|string|min:9|max:30')]
    public string $phone = '';

    #[Validate('required|string|max:2000')]
    public string $message = '';

    public string $topic = '';
    public array $topics = [];
    public bool $sent = false;

    public function mount(array $topics = []): void
    {
        $this->topics = $topics;
        $this->topic = $topics[0] ?? '';
    }

    public function chooseTopic(string $topic): void
    {
        $this->topic = $topic;
    }

    public function submit(): void
    {
        $this->validate();

        // TODO: store in contact_messages / notify the team once that table exists
        $this->sent = true;
        $this->reset(['name', 'phone', 'message']);
    }

    public function render()
    {
        return view('livewire.forms.contact-form');
    }
}
