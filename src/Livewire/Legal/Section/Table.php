<?php

namespace Nawasara\AsnPortal\Livewire\Legal\Section;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nawasara\AsnPortal\Models\LegalDocument;
use Nawasara\AsnPortal\Support\LegalDocumentSchedule;
use Nawasara\AuthPrimitives\Attributes\RequiresSudo;
use Nawasara\AuthPrimitives\Traits\WithSudo;

/**
 * Drafts and versions of the portal's privacy policy and terms of service.
 *
 * Drafts are freely editable. Publishing is a separate permission behind
 * sudo, because a published version binds every civil servant and can never
 * be changed or deleted (see LegalDocument). A replacement version must take
 * effect at least 14 days after it is published, as the documents promise.
 */
class Table extends Component
{
    use WithSudo;

    public const TYPE_LABELS = [
        LegalDocument::TYPE_PRIVACY => 'Kebijakan Privasi',
        LegalDocument::TYPE_TERMS => 'Persyaratan Layanan',
    ];

    public const STATUS_LABELS = [
        'draft' => 'Draf',
        'scheduled' => 'Terjadwal',
        'current' => 'Berlaku',
        'superseded' => 'Digantikan',
    ];

    /** Wall-clock times are entered and shown in WIB; stored as UTC. */
    private const TIMEZONE = 'Asia/Jakarta';

    #[Url(except: '')]
    public string $typeFilter = '';

    #[Url(except: '')]
    public string $statusFilter = '';

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $publishingId = null;

    #[Locked]
    public ?int $viewingId = null;

    public string $type = LegalDocument::TYPE_PRIVACY;
    public string $version = '';
    public string $title = '';
    public string $body = '';
    public ?string $change_summary = null;

    /** Datetime-local value (WIB) chosen in the publish dialog. */
    public string $effectiveAtInput = '';

    #[Computed]
    public function rows(): Collection
    {
        $documents = LegalDocument::query()->orderBy('type')->orderByDesc('created_at')->get();
        $statuses = $this->statuses($documents);

        return $documents
            ->each(fn (LegalDocument $d) => $d->setAttribute('status', $statuses[$d->id]))
            ->when(in_array($this->typeFilter, LegalDocument::TYPES, true), fn ($c) => $c->where('type', $this->typeFilter))
            ->when(array_key_exists($this->statusFilter, self::STATUS_LABELS), fn ($c) => $c->where('status', $this->statusFilter))
            ->values();
    }

    #[Computed]
    public function viewing(): ?LegalDocument
    {
        return $this->viewingId ? LegalDocument::find($this->viewingId) : null;
    }

    #[Computed]
    public function publishing(): ?LegalDocument
    {
        return $this->publishingId ? LegalDocument::find($this->publishingId) : null;
    }

    public function create(): void
    {
        $this->authorize('asn-portal.content.manage');

        $this->resetForm();
        $this->dispatch('modal-open:legal-document-form');
    }

    public function edit(int $id): void
    {
        $this->authorize('asn-portal.content.manage');

        $document = $this->draftOrFail($id);

        $this->resetForm();
        $this->editingId = $document->id;
        $this->fill($document->only(['type', 'version', 'title', 'body', 'change_summary']));

        $this->dispatch('modal-open:legal-document-form');
    }

    public function save(): void
    {
        $this->authorize('asn-portal.content.manage');

        $data = $this->validate([
            'type' => ['required', Rule::in(LegalDocument::TYPES)],
            'version' => ['required', 'string', 'max:20',
                Rule::unique('nawasara_asn_portal_legal_documents', 'version')
                    ->where('type', $this->type)
                    ->ignore($this->editingId)],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:200000'],
            'change_summary' => ['nullable', 'string', 'max:2000'],
        ], [
            'version.unique' => 'Versi ini sudah ada untuk dokumen tersebut.',
        ]);

        if ($this->editingId) {
            $this->draftOrFail($this->editingId)->update($data);
        } else {
            LegalDocument::create($data);
        }

        $this->dispatch('modal-close:legal-document-form');
        $this->dispatch('toast', type: 'success', message: 'Draf tersimpan.');

        unset($this->rows);
    }

    public function view(int $id): void
    {
        $this->authorize('asn-portal.content.view');

        $this->viewingId = LegalDocument::findOrFail($id)->id;
        unset($this->viewing);

        $this->dispatch('modal-open:legal-document-view');
    }

    public function preparePublish(int $id): void
    {
        $this->authorize('asn-portal.legal.publish');

        $document = $this->draftOrFail($id);

        $this->publishingId = $document->id;
        $this->effectiveAtInput = Carbon::createFromTimestamp(
            $this->earliestEffectiveAt($document->type), self::TIMEZONE
        )->format('Y-m-d\TH:i');
        $this->resetErrorBag();
        unset($this->publishing);

        $this->dispatch('modal-open:legal-document-publish');
    }

    #[RequiresSudo(reason: 'menerbitkan dokumen hukum Portal ASN')]
    public function publish(): void
    {
        $this->authorize('asn-portal.legal.publish');

        $document = $this->draftOrFail((int) $this->publishingId);

        $this->validate(['effectiveAtInput' => ['required', 'date_format:Y-m-d\TH:i']], [
            'effectiveAtInput.required' => 'Isi tanggal berlaku.',
        ]);

        // Parsed as WIB and converted to UTC before saving; without ->utc()
        // Eloquent stores the wall-clock time and the document takes effect
        // seven hours late.
        $effectiveAt = Carbon::createFromFormat('Y-m-d\TH:i', $this->effectiveAtInput, self::TIMEZONE)->utc();
        $earliest = $this->earliestEffectiveAt($document->type);

        // One minute of slack: the default value is computed when the dialog
        // opens, so publishing a minute later must not reject it.
        if ($effectiveAt->getTimestamp() < $earliest - 60) {
            $this->addError('effectiveAtInput', 'Versi pengganti paling cepat berlaku '
                .LegalDocumentSchedule::NOTICE_DAYS.' hari setelah diterbitkan, yaitu '
                .Carbon::createFromTimestamp($earliest, self::TIMEZONE)->translatedFormat('j F Y H:i').' WIB.');

            return;
        }

        $document->update([
            'effective_at' => $effectiveAt,
            'published_at' => now(),
            'published_by' => auth()->id(),
        ]);

        $this->publishingId = null;
        $this->dispatch('modal-close:legal-document-publish');
        $this->dispatch('toast', type: 'success', message: 'Dokumen diterbitkan.');

        unset($this->rows);
    }

    /** Only drafts can be deleted; a published version is permanent. */
    #[RequiresSudo(reason: 'menghapus draf dokumen hukum Portal ASN')]
    public function delete(int $id): void
    {
        $this->authorize('asn-portal.content.manage');

        $this->draftOrFail($id)->delete();

        $this->dispatch('toast', type: 'success', message: 'Draf dihapus.');

        unset($this->rows);
    }

    /** Pre-fill a new draft from a published version, to write the next one. */
    public function duplicate(int $id): void
    {
        $this->authorize('asn-portal.content.manage');

        $source = LegalDocument::findOrFail($id);

        $this->resetForm();
        $this->fill($source->only(['type', 'title', 'body']));

        $this->dispatch('modal-open:legal-document-form');
    }

    private function draftOrFail(int $id): LegalDocument
    {
        $document = LegalDocument::findOrFail($id);

        abort_if($document->isPublished(), 403, 'Dokumen yang sudah diterbitkan tidak bisa diubah.');

        return $document;
    }

    private function earliestEffectiveAt(string $type): int
    {
        $hasPublished = LegalDocument::query()->where('type', $type)->whereNotNull('published_at')->exists();

        return LegalDocumentSchedule::earliestEffectiveAt($hasPublished, time());
    }

    /**
     * Status of every row, computed per type with the same schedule the API
     * uses, so the panel and the portal never disagree on what is in force.
     *
     * @return array<int, string>
     */
    private function statuses(Collection $documents): array
    {
        $statuses = [];

        foreach ($documents->groupBy('type') as $versions) {
            $published = $versions->filter(fn (LegalDocument $d) => $d->isPublished());
            $schedule = LegalDocumentSchedule::resolve(
                $published->map(fn (LegalDocument $d) => ['id' => $d->id, 'effective_at' => $d->effective_at?->getTimestamp()]),
                time(),
            );
            $currentId = $schedule['current']['id'] ?? null;

            foreach ($versions as $d) {
                $statuses[$d->id] = match (true) {
                    ! $d->isPublished() => 'draft',
                    $d->id === $currentId => 'current',
                    $d->effective_at !== null && $d->effective_at->isFuture() => 'scheduled',
                    default => 'superseded',
                };
            }
        }

        return $statuses;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'type', 'version', 'title', 'body', 'change_summary']);
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('nawasara-asn-portal::livewire.pages.legal.section.table', [
            'typeLabels' => self::TYPE_LABELS,
            'statusLabels' => self::STATUS_LABELS,
            'timezone' => self::TIMEZONE,
        ]);
    }
}
