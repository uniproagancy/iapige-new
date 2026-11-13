<?php

namespace App\Livewire\Admin\Accounting;

use App\Models\User;
use App\Services\Accounting\DeliveryAccounting;
use App\Services\Accounting\DeliveryAccountingExport;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Locked]
    public string $appliedFrom = '';

    #[Locked]
    public string $appliedTo = '';

    public function mount(): void
    {
        $this->owner();
        $date = CarbonImmutable::now('Asia/Tbilisi')->toDateString();
        $this->from = $this->from === '' ? $date : $this->from;
        $this->to = $this->to === '' ? $this->from : $this->to;
        try {
            $this->applyValidatedFilters();
        } catch (ValidationException $exception) {
            $this->useToday();
            $this->setErrorBag($exception->validator->errors());
        }
    }

    protected function owner(): User
    {
        $user = auth()->user();
        abort_unless($user && $user->isAdmin(), 403);

        return $user;
    }

    protected function applyValidatedFilters(): void
    {
        $this->owner();
        $this->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        app(DeliveryAccounting::class)->validatePeriod($this->from, $this->to);
        $this->appliedFrom = $this->from;
        $this->appliedTo = $this->to;
    }

    protected function validationAttributes(): array
    {
        return ['from' => __('accounting.from'), 'to' => __('accounting.to')];
    }

    protected function useToday(): void
    {
        $date = CarbonImmutable::now('Asia/Tbilisi')->toDateString();
        $this->from = $this->to = $this->appliedFrom = $this->appliedTo = $date;
    }

    public function applyFilters(): void
    {
        $this->owner();
        $this->applyValidatedFilters();
        $this->resetPage();
    }

    public function today(): void
    {
        $this->owner();
        $this->useToday();
        $this->resetValidation();
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->owner();
        $this->today();
    }

    public function download()
    {
        $user = $this->owner();

        return app(DeliveryAccountingExport::class)->download($this->appliedFrom, $this->appliedTo, $user);
    }

    public function render()
    {
        $user = $this->owner();
        if ($this->appliedFrom === '' || $this->appliedTo === '') {
            $this->useToday();
        }
        $data = app(DeliveryAccounting::class)->data($this->appliedFrom, $this->appliedTo, $user);
        $page = $this->getPage();
        $orders = new LengthAwarePaginator($data['orders']->forPage($page, 25)->values(), $data['orders']->count(), 25, $page, [
            'path' => request()->url(), 'query' => request()->query(),
        ]);

        return view('livewire.admin.accounting.index', [
            ...$data, 'orders' => $orders,
        ])->layout('layouts.admin', ['title' => __('accounting.title')]);
    }
}
