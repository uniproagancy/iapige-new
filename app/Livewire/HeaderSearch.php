<?php

namespace App\Livewire;

use App\Support\Catalog;
use Livewire\Attributes\Computed;
use Livewire\Component;

class HeaderSearch extends Component
{
    public string $q = '';

    #[Computed]
    public function results(): array
    {
        $term = trim($this->q);

        return mb_strlen($term) < 2 ? [] : Catalog::search($term, 5);
    }

    public function clear(): void
    {
        $this->q = '';
    }

    public function showAll()
    {
        $term = trim($this->q);

        if (mb_strlen($term) < 2) {
            return null;
        }

        return $this->redirect(route('catalog', ['q' => $term]), navigate: true);
    }

    public function render()
    {
        return view('livewire.header-search');
    }
}
