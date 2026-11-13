<?php

namespace App\Livewire\Admin\Deliveries;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryItem;
use App\Models\OrderEvent;
use App\Models\PaymentMethod;
use App\Models\ProductOffer;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Delivery\OrderDeliveryManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $date = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: false)]
    public bool $allDates = false;

    #[Locked]
    public bool $courierPreview = false;

    #[Locked]
    public ?int $activeId = null;

    #[Locked]
    public string $modal = '';

    #[Locked]
    public ?int $expectedCashAmount = null;

    #[Locked]
    public ?string $correctionVersion = null;

    public string $correctionTarget = 'not_picked_up';

    public array $form = [];

    public array $itemForms = [];

    public string $newDate = '';

    public string $reason = '';

    public array $workingDays = [];

    public function mount(): void
    {
        $this->courierPreview = request()->routeIs('admin.deliveries.courier-preview');
        $this->actor();
        if (! $this->validDate($this->date)) {
            $this->date = $this->manager()->nextDate();
        }
        if (trim($this->search) !== '') {
            $this->updatedSearch();
        }
    }

    protected function actor(): User
    {
        $user = auth()->user();
        abort_unless($user && ($user->isStaff() || $user->isCourier()), 403);
        abort_if($this->courierPreview && ! $user->isAdmin(), 403);

        return $user;
    }

    protected function staff(): User
    {
        $user = $this->actor();
        abort_unless($user->isStaff() && ! $this->courierPreview, 403);

        return $user;
    }

    protected function manager(): OrderDeliveryManager
    {
        return app(OrderDeliveryManager::class);
    }

    protected function validDate(string $date): bool
    {
        return Validator::make(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->passes();
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, 100);
        if (trim($this->search) !== '') {
            $this->allDates = true;
            $this->status = 'all';
        }
        $this->resetPage();
    }

    public function updatedDate(): void
    {
        $this->validateOnly('date', ['date' => 'required|date_format:Y-m-d']);
        $this->allDates = false;
        $this->resetPage();
    }

    public function updatedAllDates(): void
    {
        $this->resetPage();
    }

    public function setStatus(string $status): void
    {
        $user = $this->actor();
        abort_unless(in_array($status, ['active', 'held', 'delivered', 'cancelled', 'all'], true), 422);
        abort_if($status === 'held' && (! $user->isStaff() || $this->courierPreview), 403);
        $this->status = $status;
        $this->resetPage();
    }

    public function shiftDate(int $direction): void
    {
        $this->actor();
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $this->validateOnly('date', ['date' => 'required|date_format:Y-m-d']);
        $date = CarbonImmutable::parse($this->date, 'Asia/Tbilisi');
        $days = $this->manager()->weekdays();
        for ($step = 0; $step < 7; $step++) {
            $date = $date->addDays($direction);
            if (in_array($date->dayOfWeek, $days, true)) {
                break;
            }
        }
        $this->date = $date->toDateString();
        $this->allDates = false;
        $this->resetPage();
    }

    protected function baseQuery(): Builder
    {
        $this->actor();
        $term = mb_substr(trim($this->search), 0, 100);
        $digits = preg_replace('/\D/', '', $term);

        return OrderDelivery::query()->visibleTo($this->actor())
            ->when($this->courierPreview, fn (Builder $query) => $query->forCourierBoard())
            ->when(! $this->allDates && $this->validDate($this->date), fn ($q) => $q->whereDate('delivery_date', $this->date))
            ->when($term !== '', function ($q) use ($term, $digits) {
                $q->where(function ($search) use ($term, $digits) {
                    $search->where('name', 'like', '%'.$term.'%')
                        ->orWhere('phone', 'like', '%'.$term.'%')
                        ->orWhereHas('order', fn ($order) => $order->where('number', 'like', '%'.$term.'%'));
                    if ($digits !== '') {
                        $search->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', '') LIKE ?", ['%'.$digits.'%']);
                    }
                });
            });
    }

    protected function findDelivery(int $id): OrderDelivery
    {
        return OrderDelivery::query()->visibleTo($this->actor())
            ->when($this->courierPreview, fn (Builder $query) => $query->forCourierBoard())
            ->with(['items', 'courier', 'order.completedBy.user:id,name'])->findOrFail($id);
    }

    protected function open(string $modal, OrderDelivery $delivery): void
    {
        abort_if($this->courierPreview, 403);
        $this->resetValidation();
        $this->activeId = $delivery->id;
        $this->modal = $modal;
        $this->reason = '';
        $this->expectedCashAmount = null;
        $this->correctionVersion = null;
        $this->correctionTarget = 'not_picked_up';
    }

    public function openEdit(int $id): void
    {
        $this->staff();
        $delivery = $this->findDelivery($id);
        abort_unless($delivery->isEditable(), 422);
        $this->open('edit', $delivery);
        $this->form = Arr::only($delivery->toArray(), ['name', 'phone', 'address', 'courier_id', 'notes']);
        if ($delivery->payment === 'transfer') {
            $this->form['payment_bank'] = $delivery->payment_bank;
        }
        $this->form['delivery_date'] = $delivery->delivery_date->toDateString();
        $this->itemForms = $delivery->items->mapWithKeys(fn ($item) => [$item->id => Arr::only($item->toArray(), [
            'supplier_id', 'supplier_name', 'supplier_sku', 'warehouse', 'pickup_address',
        ])])->all();
    }

    public function updatedItemForms(mixed $value, string $path): void
    {
        $this->staff();
        if (! preg_match('/\A([0-9]+)\.supplier_id\z/', $path, $matches) || $this->modal !== 'edit' || ! $this->activeId) {
            return;
        }
        $item = $this->findDelivery($this->activeId)->items->firstWhere('id', (int) $matches[1]);
        abort_unless($item && isset($this->itemForms[$item->id]), 404);
        $supplier = $value ? Supplier::find((int) $value) : null;
        $this->itemForms[$item->id]['supplier_name'] = $supplier?->name ?? '';
        $this->itemForms[$item->id]['supplier_sku'] = '';
        $this->itemForms[$item->id]['warehouse'] = '';
        $this->itemForms[$item->id]['pickup_address'] = '';
        if ($supplier && $item->product_id) {
            $offers = ProductOffer::where('product_id', $item->product_id)->where('supplier_id', $supplier->id)->get(['external_id']);
            if ($offers->count() === 1) {
                $this->itemForms[$item->id]['supplier_sku'] = (string) $offers->first()->external_id;
            }
        }
    }

    public function saveEdit(): void
    {
        $user = $this->staff();
        abort_unless($this->modal === 'edit' && $this->activeId, 422);
        $this->findDelivery($this->activeId);
        $this->validate([
            'form.name' => 'required|string|min:2|max:255',
            'form.phone' => 'required|string|min:9|max:32',
            'form.address' => 'required|string|min:5|max:255',
            'form.delivery_date' => 'required|date_format:Y-m-d',
            'form.courier_id' => 'nullable|integer|exists:users,id',
            'form.notes' => 'nullable|string|max:2000',
            'form.payment_bank' => 'nullable|string|max:120',
            'itemForms' => 'required|array|max:100',
            'itemForms.*' => 'required|array',
            'itemForms.*.supplier_id' => 'nullable|integer|exists:suppliers,id',
            'itemForms.*.supplier_name' => 'nullable|string|max:191',
            'itemForms.*.supplier_sku' => 'nullable|string|max:191',
            'itemForms.*.warehouse' => 'nullable|string|max:255',
            'itemForms.*.pickup_address' => 'nullable|string|max:500',
        ]);
        $attributes = Arr::only($this->form, ['name', 'phone', 'address', 'delivery_date', 'courier_id', 'notes', 'payment_bank']);
        $attributes['courier_id'] = ($attributes['courier_id'] ?? null) ?: null;
        $items = array_map(function ($values) {
            $values = Arr::only($values, ['supplier_id', 'supplier_name', 'supplier_sku', 'warehouse', 'pickup_address']);
            $values['supplier_id'] = ($values['supplier_id'] ?? null) ?: null;

            return $values;
        }, $this->itemForms);
        $delivery = $this->manager()->updateDelivery($this->activeId, $attributes, $items, $user);
        if (! $this->allDates) {
            $this->date = $delivery->delivery_date->toDateString();
        }
        $this->saved();
    }

    public function openPostpone(int $id): void
    {
        $this->staff();
        $delivery = $this->findDelivery($id);
        abort_unless($delivery->isEditable(), 422);
        $this->open('postpone', $delivery);
        $date = CarbonImmutable::parse($delivery->delivery_date)->addDay();
        while (! in_array($date->dayOfWeek, $this->manager()->weekdays(), true)) {
            $date = $date->addDay();
        }
        $this->newDate = $date->toDateString();
    }

    public function openComment(int $id): void
    {
        $this->staff();
        $this->open('comment', $this->findDelivery($id));
    }

    public function openCancel(int $id): void
    {
        $this->staff();
        $delivery = $this->findDelivery($id);
        abort_unless($delivery->isEditable(), 422);
        $this->open('cancel', $delivery);
    }

    public function openHistory(int $id): void
    {
        $this->actor();
        abort_if($this->courierPreview, 403);
        $this->open('history', $this->findDelivery($id));
    }

    public function openPickedUp(int $id): void
    {
        $this->actor();
        abort_if($this->courierPreview, 403);
        $delivery = $this->findDelivery($id);
        abort_unless($delivery->canPickUp(), 422);
        $this->open('picked-up', $delivery);
    }

    public function openCorrection(int $id): void
    {
        abort_unless($this->actor()->isAdmin() && ! $this->courierPreview, 403);
        $delivery = $this->findDelivery($id);
        abort_unless($delivery->canCorrectActions() && $delivery->order->status !== Order::CANCELLED, 422);
        $this->open('correction', $delivery);
        $this->correctionVersion = $this->manager()->actionCorrectionVersion($delivery);
        $this->correctionTarget = $delivery->status === OrderDelivery::DELIVERED && $delivery->isPickedUp()
            ? 'picked_up' : 'not_picked_up';
    }

    public function saveCorrection(): void
    {
        $user = $this->actor();
        abort_unless($user->isAdmin() && ! $this->courierPreview, 403);
        abort_unless($this->modal === 'correction' && $this->activeId && $this->correctionVersion !== null, 422);
        $this->findDelivery($this->activeId);
        $this->validate([
            'correctionTarget' => 'required|in:not_picked_up,picked_up',
            'reason' => 'required|string|min:2|max:2000',
        ]);
        $this->manager()->correctActions($this->activeId, $this->correctionTarget, $this->reason, $user, $this->correctionVersion);
        $this->status = 'all';
        $this->saved();
    }

    public function openCashReceipt(int $id): void
    {
        $user = $this->actor();
        abort_if($this->courierPreview, 403);
        $delivery = $this->findDelivery($id);
        abort_unless($delivery->status === OrderDelivery::DELIVERED && $delivery->requiresCashReceipt(), 422);
        $amount = $this->manager()->cashAmountCents($id, $user);
        $this->open('cash-received', $delivery);
        $this->expectedCashAmount = $amount;
    }

    public function openDelivered(int $id): void
    {
        $user = $this->actor();
        abort_if($this->courierPreview, 403);
        $delivery = $this->findDelivery($id);
        abort_unless($delivery->canDeliver() && $delivery->isPickedUp(), 422);
        if ($delivery->requiresCashReceipt()) {
            $amount = $this->manager()->cashAmountCents($id, $user);
            $this->open('cash-delivered', $delivery);
            $this->expectedCashAmount = $amount;
        } else {
            $this->open('delivered', $delivery);
        }
    }

    public function saveAction(): void
    {
        $user = $this->actor();
        abort_if($this->courierPreview, 403);
        abort_unless($this->activeId && in_array($this->modal, ['postpone', 'comment', 'cancel', 'picked-up', 'delivered', 'cash-delivered', 'cash-received'], true), 422);
        $delivery = $this->findDelivery($this->activeId);
        abort_if($this->modal === 'cash-received' && $delivery->status !== OrderDelivery::DELIVERED, 422);
        if (in_array($this->modal, ['delivered', 'cash-delivered'], true) && ! $delivery->isPickedUp()) {
            throw ValidationException::withMessages(['delivery' => __('delivery.errors.pickup_required')]);
        }
        if ($this->modal === 'picked-up') {
            $this->manager()->markPickedUp($this->activeId, $user);
            $this->status = 'all';
        } elseif (in_array($this->modal, ['cash-delivered', 'cash-received'], true)) {
            abort_unless($this->expectedCashAmount !== null, 422);
            $this->manager()->markCashDelivered($this->activeId, $user, $this->expectedCashAmount);
            $this->status = 'all';
        } elseif ($this->modal === 'delivered') {
            $this->manager()->markDelivered($this->activeId, $user);
            $this->status = 'all';
        } else {
            $this->staff();
            $this->validate(['reason' => 'required|string|min:2|max:2000']);
            if ($this->modal === 'postpone') {
                $this->validate(['newDate' => 'required|date_format:Y-m-d']);
                $delivery = $this->manager()->postpone($this->activeId, $this->newDate, $this->reason, $user);
                $this->date = $delivery->delivery_date->toDateString();
                $this->allDates = false;
                $this->status = 'active';
            } elseif ($this->modal === 'cancel') {
                $this->manager()->cancel($this->activeId, $this->reason, $user);
                $this->status = 'all';
            } else {
                $this->manager()->addComment($this->activeId, $this->reason, $user);
            }
        }
        $this->saved();
    }

    public function openSchedule(): void
    {
        abort_unless($this->actor()->isAdmin() && ! $this->courierPreview, 403);
        $this->resetValidation();
        $this->modal = 'schedule';
        $this->activeId = null;
        $this->expectedCashAmount = null;
        $this->workingDays = $this->manager()->weekdays();
    }

    public function saveSchedule(): void
    {
        $user = $this->actor();
        abort_unless($user->isAdmin() && ! $this->courierPreview && $this->modal === 'schedule', 403);
        $this->validate(['workingDays' => 'required|array|min:1|max:7', 'workingDays.*' => 'required|integer|between:0,6|distinct']);
        $this->manager()->saveWeekdays(array_map('intval', $this->workingDays), $user);
        $this->saved();
    }

    public function closeModal(): void
    {
        $this->actor();
        $this->modal = '';
        $this->activeId = null;
        $this->expectedCashAmount = null;
        $this->correctionVersion = null;
        $this->resetValidation();
        $this->reset(['form', 'itemForms', 'reason', 'newDate', 'correctionTarget']);
    }

    protected function saved(): void
    {
        $this->closeModal();
        $this->dispatch('toast', message: __('delivery.saved'));
    }

    public function render()
    {
        $user = $this->actor();
        $isStaff = $user->isStaff() && ! $this->courierPreview;
        if (! in_array($this->status, ['active', 'held', 'delivered', 'cancelled', 'all'], true)
            || ($this->status === 'held' && ! $isStaff)) {
            $this->status = 'all';
        }
        if (! $this->validDate($this->date)) {
            $this->date = $this->manager()->nextDate();
        }
        $base = $this->baseQuery();
        $counts = (clone $base)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $heldCount = (clone $base)->held()->count();
        $counts['active'] = max(0, (int) ($counts['active'] ?? 0) - $heldCount);
        $counts['held'] = $heldCount;
        $query = (clone $base)
            ->when($this->status === 'held', fn (Builder $query) => $query->held())
            ->when($this->status === 'active', fn (Builder $query) => $query->where('status', OrderDelivery::ACTIVE)
                ->whereNotIn('id', OrderDelivery::query()->held()->select('id')))
            ->when(in_array($this->status, ['delivered', 'cancelled'], true), fn (Builder $query) => $query->where('status', $this->status))
            ->with(['items', 'courier:id,name', 'order.completedBy.user:id,name'])
            ->orderBy(OrderDeliveryItem::select('supplier_name')->whereColumn('order_delivery_id', 'order_deliveries.id')->where('is_active', true)->orderBy('supplier_name')->limit(1))
            ->orderBy('delivery_date')
            ->orderBy('id');
        $deliveries = $query->paginate(20);
        $groups = [];
        foreach ($deliveries as $index => $delivery) {
            $bySupplier = $delivery->items->sortBy(fn ($item) => mb_strtolower($item->supplier_name.'|'.$item->warehouse))
                ->groupBy(fn ($item) => trim((string) $item->supplier_name) ?: __('delivery.unassigned_supplier'));
            foreach ($bySupplier as $supplier => $items) {
                $groups[$supplier][] = ['delivery' => $delivery, 'items' => $items, 'ordinal' => $deliveries->firstItem() + $index];
            }
            if ($bySupplier->isEmpty()) {
                $groups[__('delivery.unassigned_supplier')][] = ['delivery' => $delivery, 'items' => collect(), 'ordinal' => $deliveries->firstItem() + $index];
            }
        }
        uksort($groups, fn ($a, $b) => strnatcasecmp($a, $b));
        $ordinals = [];
        $nextOrdinal = $deliveries->firstItem() ?? 1;
        foreach ($groups as $supplier => $entries) {
            foreach ($entries as $index => $entry) {
                $id = $entry['delivery']->id;
                if (! isset($ordinals[$id])) {
                    $ordinals[$id] = $nextOrdinal++;
                }
                $groups[$supplier][$index]['ordinal'] = $ordinals[$id];
            }
        }
        $selected = null;
        if ($this->activeId) {
            try {
                $selected = $this->findDelivery($this->activeId);
            } catch (ModelNotFoundException) {
                $this->closeModal();
            }
        }
        $events = $selected && $this->modal === 'history'
            ? OrderEvent::where('order_id', $selected->order_id)->where('type', 'like', 'delivery_%')->with('user:id,name')->latest('id')->limit(100)->get()
            : collect();
        $comments = OrderEvent::whereIn('order_id', $deliveries->pluck('order_id'))->where('type', 'delivery_comment')
            ->with('user:id,name')->latest('id')->limit(100)->get()->groupBy('order_id');

        return view('livewire.admin.deliveries.index', [
            'deliveries' => $deliveries, 'groups' => $groups, 'counts' => $counts,
            'selectedDelivery' => $selected, 'events' => $events, 'comments' => $comments,
            'isStaff' => $isStaff, 'isOwner' => $user->isAdmin() && ! $this->courierPreview,
            'isPreview' => $this->courierPreview,
            'weekdays' => $this->manager()->weekdays(),
            'suppliers' => $isStaff ? Supplier::orderBy('name')->get(['id', 'name']) : collect(),
            'couriers' => $isStaff ? User::withRole(User::COURIER)->orderBy('name')->get(['id', 'name']) : collect(),
            'methods' => PaymentMethod::withTranslation()->get()->keyBy('code'),
        ])->layout($isStaff ? 'layouts.admin' : 'layouts.courier', [
            'title' => __('delivery.title'), 'courierPreview' => $this->courierPreview,
        ]);
    }
}
