<?php

namespace App\Livewire\Admin\Callbacks;

use App\Models\CallbackRequest;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Callback requests, worked from the top.
 *
 * The screen opens on what nobody has answered yet, because a lead goes cold
 * in hours and this list is the only place it exists.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'new')]
    public string $status = 'new';

    /** the request opened in place */
    public ?int $open = null;
    public string $note = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
            $this->open = null;
        }
    }

    public function render()
    {
        return view('livewire.admin.callbacks.index', [
            'requests' => CallbackRequest::query()
                ->with(['product' => fn ($q) => $q->withTranslation(), 'user', 'handler'])
                ->when($this->status, fn ($q) => $q->where('status', $this->status))
                ->when($this->search, function ($q) {
                    $term = trim($this->search);
                    $digits = preg_replace('/\D/', '', $term);

                    $q->where(fn ($w) => $w
                        ->where('name', 'like', "%{$term}%")
                        ->when($digits !== '', fn ($p) => $p->orWhere('phone', 'like', "%{$digits}%")));
                })
                ->latest('id')
                ->paginate(25),
            'counts'   => $this->counts(),
            'statuses' => [
                CallbackRequest::NEW    => __('admin.cb_new'),
                CallbackRequest::CALLED => __('admin.cb_called'),
                CallbackRequest::CLOSED => __('admin.cb_closed'),
            ],
        ])->layout('layouts.admin', ['title' => __('admin.callbacks')]);
    }

    /** @return array<string, int> */
    protected function counts(): array
    {
        return CallbackRequest::select('status', DB::raw('count(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();
    }

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
        $this->note = $this->open ? (string) CallbackRequest::find($id)?->note : '';
    }

    /**
     * Marks a request as handled.
     *
     * Who answered and when is recorded, because a customer who says "somebody
     * called me" deserves an answer better than "which somebody".
     */
    public function setStatus(int $id, string $status): void
    {
        $request = CallbackRequest::findOrFail($id);

        $request->update([
            'status'     => $status,
            'note'       => $this->note ?: $request->note,
            'handled_by' => auth()->id(),
            'handled_at' => now(),
        ]);

        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function saveNote(int $id): void
    {
        CallbackRequest::whereKey($id)->update(['note' => $this->note ?: null]);

        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function delete(int $id): void
    {
        CallbackRequest::whereKey($id)->delete();

        $this->open = null;
        $this->dispatch('toast', message: __('admin.deleted'));
    }
}
