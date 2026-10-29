<?php

namespace Nawasara\AsnPortal\Livewire\Help\Section;

use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\AsnPortal\Models\HelpItem;
use Nawasara\AuthPrimitives\Attributes\RequiresSudo;
use Nawasara\AuthPrimitives\Traits\WithSudo;

/**
 * Manage the portal's FAQ and "Tidak bisa masuk?" entries.
 */
class Table extends Component
{
    use WithSudo;

    public const KIND_LABELS = [
        HelpItem::KIND_FAQ => 'Pertanyaan umum',
        HelpItem::KIND_SIGN_IN_HELP => 'Tidak bisa masuk',
    ];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $kindFilter = '';

    #[Url(except: '')]
    public string $statusFilter = '';

    #[Locked]
    public ?int $editingId = null;

    public string $slug = '';
    public string $kind = HelpItem::KIND_FAQ;
    public string $question = '';
    public string $answer = '';
    public ?string $action_label = null;
    public ?string $action_url = null;
    public int $sort_order = 0;

    #[Computed]
    public function rows()
    {
        return HelpItem::query()
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($w) => $w->where('question', 'like', $term)->orWhere('answer', 'like', $term));
            })
            ->when(in_array($this->kindFilter, HelpItem::KINDS, true), fn ($q) => $q->where('kind', $this->kindFilter))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('is_active', $this->statusFilter === 'active'))
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function create(): void
    {
        $this->authorize('asn-portal.content.manage');

        $this->resetForm();
        $this->sort_order = ((int) HelpItem::max('sort_order')) + 10;

        $this->dispatch('modal-open:help-item-form');
    }

    public function edit(int $id): void
    {
        $this->authorize('asn-portal.content.manage');

        $item = HelpItem::findOrFail($id);

        $this->resetForm();
        $this->editingId = $item->id;
        $this->fill($item->only(['slug', 'kind', 'question', 'answer', 'action_label', 'action_url', 'sort_order']));

        $this->dispatch('modal-open:help-item-form');
    }

    public function save(): void
    {
        $this->authorize('asn-portal.content.manage');

        foreach (['action_label', 'action_url'] as $field) {
            $this->{$field} = $this->{$field} === null || trim($this->{$field}) === '' ? null : trim($this->{$field});
        }

        $data = $this->validate([
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('nawasara_asn_portal_help_items', 'slug')->ignore($this->editingId)],
            'kind' => ['required', Rule::in(HelpItem::KINDS)],
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:5000'],
            'action_label' => ['nullable', 'required_with:action_url', 'string', 'max:80'],
            'action_url' => ['nullable', 'url:https', 'max:500'],
            'sort_order' => ['integer', 'min:0', 'max:99999'],
        ], [
            'slug.regex' => 'Slug hanya huruf kecil, angka, dan tanda hubung.',
            'action_label.required_with' => 'Isi teks tombol bila ada alamat tombol.',
            'action_url.url' => 'Alamat tombol harus diawali https://.',
        ]);

        if ($this->editingId) {
            HelpItem::findOrFail($this->editingId)->update($data);
        } else {
            HelpItem::create($data + ['is_active' => true]);
        }

        $this->dispatch('modal-close:help-item-form');
        $this->dispatch('toast', type: 'success', message: 'Bantuan tersimpan.');

        unset($this->rows);
    }

    public function toggle(int $id): void
    {
        $this->authorize('asn-portal.content.manage');

        $item = HelpItem::findOrFail($id);
        $item->update(['is_active' => ! $item->is_active]);

        unset($this->rows);
    }

    #[RequiresSudo(reason: 'menghapus bantuan Portal ASN')]
    public function delete(int $id): void
    {
        $this->authorize('asn-portal.content.manage');

        HelpItem::findOrFail($id)->delete();

        $this->dispatch('toast', type: 'success', message: 'Bantuan dihapus.');

        unset($this->rows);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'slug', 'kind', 'question', 'answer', 'action_label', 'action_url', 'sort_order']);
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('nawasara-asn-portal::livewire.pages.help.section.table', [
            'kindLabels' => self::KIND_LABELS,
        ]);
    }
}
