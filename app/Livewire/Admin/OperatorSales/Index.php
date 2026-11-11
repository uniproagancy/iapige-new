<?php

namespace App\Livewire\Admin\OperatorSales;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Services\Orders\OperatorSalesReport;
use Carbon\CarbonImmutable;
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

    #[Url(except: '')]
    public string $operator = '';

    #[Locked]
    public string $appliedFrom = '';

    #[Locked]
    public string $appliedTo = '';

    #[Locked]
    public string $appliedOperator = '';

    public function mount(): void
    {
        $this->owner();
        $today = CarbonImmutable::now('Asia/Tbilisi');
        $this->from = $this->from === '' ? $today->startOfMonth()->toDateString() : $this->from;
        $this->to = $this->to === '' ? $today->endOfMonth()->toDateString() : $this->to;
        try {
            $this->applyValidatedFilters();
        } catch (ValidationException $exception) {
            $errors = $exception->validator->errors();
            $this->useCurrentMonth();
            $this->setErrorBag($errors);
        }
    }

    protected function owner(): User
    {
        $user = auth()->user();
        abort_unless($user && $user->isAdmin(), 403);

        return $user;
    }

    protected function validateFilters(): ?int
    {
        $this->owner();
        $this->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'operator' => ['nullable', 'integer', 'min:0', 'regex:/\A(?:0|[1-9][0-9]*)\z/'],
        ]);
        if ($this->operator !== '' && $this->operator !== '0') {
            $recorded = User::whereKey((int) $this->operator)->exists()
                || OrderEvent::where('type', 'status')->where('to', Order::COMPLETED)
                    ->where('user_id', (int) $this->operator)->exists();
            if (! $recorded) {
                throw ValidationException::withMessages(['operator' => __('operator_sales.invalid_operator')]);
            }
        }

        return $this->operator === '' ? null : (int) $this->operator;
    }

    public function applyFilters(): void
    {
        $this->owner();
        $this->applyValidatedFilters();
        $this->resetPage();
    }

    protected function applyValidatedFilters(): void
    {
        $this->validateFilters();
        $this->appliedFrom = $this->from;
        $this->appliedTo = $this->to;
        $this->appliedOperator = $this->operator;
    }

    protected function useCurrentMonth(): void
    {
        $today = CarbonImmutable::now('Asia/Tbilisi');
        $this->from = $this->appliedFrom = $today->startOfMonth()->toDateString();
        $this->to = $this->appliedTo = $today->endOfMonth()->toDateString();
        $this->operator = $this->appliedOperator = '';
    }

    public function resetFilters(): void
    {
        $this->owner();
        $this->useCurrentMonth();
        $this->resetValidation();
        $this->resetPage();
    }

    public function selectOperator(string $operator): void
    {
        $this->owner();
        $this->operator = $operator;
        $this->applyValidatedFilters();
        $this->resetPage();
    }

    public function render()
    {
        $this->owner();
        // An invalid draft must not prevent Livewire from rendering its validation errors.
        if ($this->appliedFrom === '' || $this->appliedTo === '') {
            $this->useCurrentMonth();
        }
        $operatorId = $this->appliedOperator === '' ? null : (int) $this->appliedOperator;
        $report = app(OperatorSalesReport::class);
        $data = $report->build($this->appliedFrom, $this->appliedTo, $operatorId);
        $operators = $data['operators']->keyBy(fn ($row) => (string) ($row->operator_id ?? 0));
        foreach (User::withRole(User::OPERATOR)->orderBy('name')->get(['id', 'name']) as $user) {
            if (! $operators->has((string) $user->id)) {
                $operators->put((string) $user->id, (object) [
                    'operator_id' => $user->id, 'operator_name' => $user->name, 'operator_missing' => false,
                ]);
            }
        }
        $orders = $report->query($this->appliedFrom, $this->appliedTo, $operatorId)->paginate(25);

        return view('livewire.admin.operator-sales.index', [
            'summary' => $data['summary'],
            'totals' => $data['totals'],
            'operators' => $operators->sortBy(fn ($row) => mb_strtolower((string) $row->operator_name))->values(),
            'orders' => $orders,
        ])->layout('layouts.admin', ['title' => __('operator_sales.title')]);
    }
}
