<?php

namespace App\Livewire;

use App\Support\Catalog;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Header search. Queries the database (translated names and summaries)
 * instead of shipping a JSON index to the browser.
 */
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

    public function render()
    {
        return view('livewire.header-search');
    }
}
