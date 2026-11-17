<?php

namespace App\Livewire\Admin\Warranties;

use App\Livewire\Admin\Warranties\Concerns\AuthorizesWarranties;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryItem;
use App\Models\Supplier;
use App\Models\WarrantyCertificate;
use App\Services\Warranties\WarrantyCertificateManager;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use AuthorizesWarranties;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'supplier', except: '')]
    public string $supplierId = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public string $orderSearch = '';

    #[Locked]
    public ?int $selectedDeliveryId = null;

    public array $unitIndexes = [];

    public function mount(): void
    {
        $actor = $this->warrantyActor();
        $orderId = filter_var(request()->query('order'), FILTER_VALIDATE_INT);
        if ($orderId !== false && $orderId > 0) {
            $delivery = OrderDelivery::query()->visibleTo($actor)->where('order_id', $orderId)->first();
            if ($delivery !== null) {
                $this->selectDelivery($delivery->id);
            }
        }
    }

    public function updated(string $property): void
    {
        $this->warrantyActor();
        if (in_array($property, ['search', 'status', 'supplierId', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function selectDelivery(int $id): void
    {
        $actor = $this->warrantyActor();
        $delivery = OrderDelivery::query()->visibleTo($actor)->with('items')->findOrFail($id);
        $this->selectedDeliveryId = $delivery->id;
        $this->unitIndexes = $delivery->items->mapWithKeys(fn (OrderDeliveryItem $item) => [$item->id => 1])->all();
        $this->orderSearch = '';
        $this->resetValidation();
    }

    public function clearDelivery(): void
    {
        $this->warrantyActor();
        $this->selectedDeliveryId = null;
        $this->unitIndexes = [];
        $this->resetValidation();
    }

    public function prepare(int $itemId): void
    {
        $actor = $this->warrantyActor();
        $item = OrderDeliveryItem::query()
            ->where('order_delivery_id', $this->selectedDeliveryId)
            ->where('is_active', true)
            ->whereHas('delivery', fn (Builder $query) => $query->visibleTo($actor))
            ->findOrFail($itemId);
        $this->validate([
            'unitIndexes.'.$itemId => ['required', 'integer', 'min:1', 'max:'.$item->qty],
        ], [], ['unitIndexes.'.$itemId => __('warranty.unit_index')]);

        $certificate = app(WarrantyCertificateManager::class)->prepare($item, (int) $this->unitIndexes[$itemId], $actor);
        $this->redirectRoute('admin.warranties.show', ['certificate' => $certificate->id], navigate: true);
    }

    protected function certificates(): Builder
    {
        $query = WarrantyCertificate::query()->with(['supplier:id,name,code', 'order:id,number']);
        $term = mb_substr(trim($this->search), 0, 120);
        if ($term !== '') {
            // Customer fields on certificates are encrypted; search the related order instead.
            $query->where(fn (Builder $where) => $where->where('number', 'like', '%'.$term.'%')
                ->orWhereHas('order', fn (Builder $order) => $order
                    ->where(fn (Builder $values) => $values->where('number', 'like', '%'.$term.'%')
                        ->orWhere('name', 'like', '%'.$term.'%')
                        ->orWhere('phone', 'like', '%'.$term.'%'))));
        }
        if (in_array($this->status, ['draft', 'issued', 'revoked'], true)) {
            $query->where('status', $this->status);
        }
        if (ctype_digit($this->supplierId)) {
            $query->where('supplier_id', (int) $this->supplierId);
        }
        foreach (['from' => '>=', 'to' => '<='] as $property => $operator) {
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $this->{$property})) {
                $query->whereDate('delivery_date', $operator, $this->{$property});
            }
        }

        return $query->orderByDesc('id');
    }

    public function render()
    {
        $actor = $this->warrantyActor();
        $delivery = $this->selectedDeliveryId === null ? null : OrderDelivery::query()
            ->visibleTo($actor)->with(['items.supplier', 'order:id,number'])->find($this->selectedDeliveryId);
        $orderMatches = collect();
        if (mb_strlen(trim($this->orderSearch)) >= 2) {
            $term = mb_substr(trim($this->orderSearch), 0, 120);
            $orderMatches = OrderDelivery::query()->visibleTo($actor)->with('order:id,number')
                ->where(fn (Builder $query) => $query->where('name', 'like', '%'.$term.'%')
                    ->orWhere('phone', 'like', '%'.$term.'%')
                    ->orWhereHas('order', fn (Builder $order) => $order->where('number', 'like', '%'.$term.'%')))
                ->orderByDesc('id')->limit(8)->get();
        }

        return view('livewire.admin.warranties.index', [
            'certificates' => $this->certificates()->paginate(25),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'code']),
            'delivery' => $delivery,
            'orderMatches' => $orderMatches,
            'isOwner' => $actor->isAdmin(),
        ])->layout('layouts.admin', ['title' => __('warranty.title')]);
    }
}
