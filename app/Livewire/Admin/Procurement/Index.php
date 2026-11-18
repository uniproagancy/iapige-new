<?php

namespace App\Livewire\Admin\Procurement;

use App\Models\User;
use App\Services\Procurement\ProcurementBoard;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'supplier', except: '')]
    public string $supplierId = '';

    #[Url(except: 'outstanding')]
    public string $status = 'outstanding';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Locked]
    public array $reviewManifest = [];

    #[Locked]
    public string $reviewText = '';

    #[Locked]
    public string $reviewSupplier = '';

    #[Locked]
    public ?int $editingItemId = null;

    #[Locked]
    public string $editingHash = '';

    #[Locked]
    public string $editingModel = '';

    #[Locked]
    public bool $editingWasSent = false;

    public array $form = [];

    public string $newSupplierId = '';

    public string $notice = '';

    public function mount(): void
    {
        $this->actor();
    }

    public function updated(string $property): void
    {
        $this->actor();
        if (in_array($property, ['search', 'supplierId', 'status', 'from', 'to'], true)) {
            $this->closeReview();
            $this->closeEdit();
            $this->notice = '';
        }
    }

    public function clearFilters(): void
    {
        $this->actor();
        $this->search = $this->supplierId = $this->from = $this->to = '';
        $this->status = 'outstanding';
        $this->closeReview();
        $this->closeEdit();
        $this->notice = '';
    }

    public function chooseSupplier(string $id): void
    {
        $this->actor();
        abort_unless($id === '' || (ctype_digit($id) && strlen($id) <= 12), 404);
        $this->supplierId = $id;
        $this->closeReview();
        $this->closeEdit();
        $this->notice = '';
    }

    public function refreshBoard(): void
    {
        $this->actor();
        $this->closeReview();
        $this->closeEdit();
        $this->notice = '';
    }

    public function reviewGroup(string $key): string
    {
        $board = $this->board();
        $group = collect($board['groups'])->firstWhere('key', $key);
        abort_unless($group, 404);
        $this->closeEdit();
        $this->reviewManifest = $group['manifest'] ?? [];
        $this->reviewText = (string) ($group['copy_text'] ?? '');
        $this->reviewSupplier = (string) $group['name'];
        $this->notice = '';
        $this->resetValidation();

        return $this->reviewText;
    }

    public function reviewRow(string $key): string
    {
        [$group, $row] = $this->findRow($key);
        $this->closeEdit();
        $this->reviewText = (string) ($row['copy_text'] ?? '');
        $this->reviewSupplier = (string) $group['name'];
        $this->reviewManifest = (($row['status'] ?? '') === 'unsent' || ($row['type'] ?? '') === 'withdraw')
            ? [['key' => $row['key'], 'expected_hash' => $row['expected_hash']]] : [];
        $this->notice = '';
        $this->resetValidation();

        return $this->reviewText;
    }

    public function closeReview(): void
    {
        $this->actor();
        $this->reviewManifest = [];
        $this->reviewText = $this->reviewSupplier = '';
        $this->resetValidation();
    }

    public function markSent(): void
    {
        $actor = $this->actor();
        abort_unless($this->reviewManifest !== [] && $this->reviewText !== '', 422);
        app(ProcurementBoard::class)->markSent($actor, $this->reviewManifest);
        $this->closeReview();
        $this->notice = __('procurement.sent_saved');
    }

    public function markRowOrdered(string $key, string $expectedHash): void
    {
        $this->markCurrentOrdered([['key' => $key, 'expected_hash' => $expectedHash]]);
    }

    public function markModelOrdered(array $manifest): void
    {
        $this->markCurrentOrdered($manifest);
    }

    private function markCurrentOrdered(array $manifest): void
    {
        $actor = $this->actor();
        abort_unless($manifest !== [] && count($manifest) <= ProcurementBoard::LIMIT, 422);
        foreach ($manifest as $entry) {
            abort_unless(is_array($entry) && isset($entry['key']) && is_string($entry['key'])
                && preg_match('/\Acurrent:[1-9][0-9]*\z/D', $entry['key']), 422);
        }
        // The rendered hashes bind the exact rows the owner saw, independently of copying.
        app(ProcurementBoard::class)->markSent($actor, $manifest);
        $this->closeReview();
        $this->closeEdit();
        $this->notice = __('procurement.sent_saved');
    }

    public function editRow(string $key): void
    {
        [, $row] = $this->findRow($key);
        abort_unless(($row['type'] ?? 'current') === 'current', 404);
        $this->closeReview();
        $this->editingItemId = (int) $row['item_id'];
        $this->editingHash = (string) $row['expected_hash'];
        $this->editingModel = (string) $row['model'];
        $this->editingWasSent = $row['status'] === 'sent';
        $this->newSupplierId = isset($row['supplier_id']) ? (string) $row['supplier_id'] : '';
        $this->form = [
            'status' => $row['status'],
            'note' => $row['note'] ?? '',
            'native_sku' => $row['native_sku'] ?? '',
            'issue_qty' => $row['issue_qty'] ?? '',
        ];
        $this->notice = '';
        $this->resetValidation();
    }

    public function closeEdit(): void
    {
        $this->actor();
        $this->editingItemId = null;
        $this->editingHash = $this->editingModel = $this->newSupplierId = '';
        $this->editingWasSent = false;
        $this->form = [];
        $this->resetValidation();
    }

    public function saveItem(): void
    {
        $actor = $this->actor();
        abort_unless($this->editingItemId !== null, 404);
        $this->validate([
            'form.status' => ['required', 'in:unsent,sent,confirmed,received,issue'],
            'form.note' => ['nullable', 'string', 'max:2000'],
            'form.native_sku' => ['nullable', 'string', 'max:191'],
            'form.issue_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $values = $this->form;
        $values['issue_qty'] = $values['issue_qty'] === '' ? null : $values['issue_qty'];
        app(ProcurementBoard::class)->updateItem($actor, $this->editingItemId, $this->editingHash, $values);
        $this->closeEdit();
        $this->notice = __('procurement.saved');
    }

    public function changeSupplier(): void
    {
        $actor = $this->actor();
        abort_unless($this->editingItemId !== null, 404);
        $this->validate(['newSupplierId' => ['required', 'integer', 'min:1', 'exists:suppliers,id']]);
        app(ProcurementBoard::class)->changeSupplier($actor, $this->editingItemId, $this->editingHash, (int) $this->newSupplierId);
        $this->closeEdit();
        $this->notice = __('procurement.supplier_saved');
    }

    public function acknowledge(string $key, string $expectedHash): void
    {
        $actor = $this->actor();
        [, $row] = $this->findRow($key);
        abort_unless(($row['type'] ?? '') === 'withdraw', 404);
        app(ProcurementBoard::class)->acknowledge($actor, $key, $expectedHash);
        $this->notice = __('procurement.acknowledged');
    }

    protected function actor(): User
    {
        $authenticated = auth()->user();
        abort_unless($authenticated instanceof User, 403);
        $current = User::query()->find($authenticated->id);
        abort_unless($current?->isAdmin(), 403);

        return $current;
    }

    protected function board(): array
    {
        return app(ProcurementBoard::class)->board($this->actor(), [
            'q' => mb_substr($this->search, 0, 120),
            'supplier_id' => $this->supplierId,
            'status' => $this->status,
            'date_from' => $this->from,
            'date_to' => $this->to,
        ]);
    }

    protected function findRow(string $key): array
    {
        abort_unless(strlen($key) <= 128, 404);
        foreach ($this->board()['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                if ($row['key'] === $key) {
                    return [$group, $row];
                }
            }
        }
        abort(404);
    }

    public function render()
    {
        $board = $this->board();

        return view('livewire.admin.procurement.index', ['board' => $board])
            ->layout('layouts.admin', ['title' => __('procurement.title')]);
    }
}
