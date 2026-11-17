<?php

namespace App\Livewire\Admin\Warranties;

use App\Livewire\Admin\Warranties\Concerns\AuthorizesWarranties;
use App\Services\Warranties\WarrantyServiceDirectory;
use Livewire\Component;

class Services extends Component
{
    use AuthorizesWarranties;

    public string $supplierCode = '';

    public function render()
    {
        $actor = $this->warrantyActor();
        $directory = app(WarrantyServiceDirectory::class)->all();
        $suppliers = collect(array_merge($directory['suppliers'], $directory['unlinked_suppliers'] ?? []))->where('excluded', false);
        $selected = $suppliers->filter(fn ($supplier) => $this->supplierCode === '' || $supplier['code'] === $this->supplierCode)
            ->map(function ($supplier) {
                $groups = [];
                foreach ($supplier['routes'] as $route) {
                    $route['official_terms'] = app(WarrantyServiceDirectory::class)->officialTerms($route);
                    $signature = hash('sha256', json_encode([$route['service_name'], $route['service_phone'],
                        $route['service_address'], $route['conditions'], $route['official_terms']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    if (! isset($groups[$signature])) {
                        $groups[$signature] = $route + ['scopes' => []];
                    }
                    $groups[$signature]['scopes'][] = $route['scope_label_ka'];
                }
                return $supplier + ['groups' => $groups];
            });

        return view('livewire.admin.warranties.services', ['suppliers' => $suppliers, 'selected' => $selected,
            'isOwner' => $actor->isAdmin()])->layout('layouts.admin', ['title' => __('warranty.service_directory')]);
    }
}
