<?php

/**
 * Show Livewire Component — Detailseite eines Diaries.
 *
 * Eigenschaften (Knoten mit Vererbung, Owner, Agent) plus read-only Einträge-Timeline
 * (Kind-Komponente diary.entries-feed).
 */

namespace Platform\Diary\Livewire;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Core\Livewire\Concerns\HasPageContext;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Diary\Enums\DiaryStatus;
use Platform\Diary\Exceptions\DiaryException;
use Platform\Diary\Livewire\Concerns\LinksDiariesToNodes;
use Platform\Diary\Models\Diary;
use Platform\Diary\Services\DiaryService;

class Show extends Component
{
    use HasPageContext, LinksDiariesToNodes;

    public Diary $diary;

    public bool $showEdit = false;
    public array $form = [];

    public function mount(Diary $diary): void
    {
        Gate::authorize('view', $diary);
        $this->diary = $diary;
    }

    protected function pageContext(): ?Model
    {
        return $this->diary;
    }

    protected function pageContextOptions(): array
    {
        return [
            'subject' => $this->diary->title,
            'url' => route('diary.diaries.show', $this->diary),
            'source' => 'diary.diary.view',
            'capabilities' => ['manage_channels' => true, 'threads' => false],
            'meta' => [
                'status' => $this->diary->status?->value,
                'created_at' => $this->diary->created_at,
            ],
        ];
    }

    #[On('updateSidebar')]
    public function refreshDiary(): void
    {
        $this->diary->refresh();
    }

    public function openEdit(): void
    {
        Gate::authorize('update', $this->diary);
        $this->form = [
            'title' => $this->diary->title,
            'description' => $this->diary->description,
        ];
        $this->resetValidation();
        $this->showEdit = true;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->diary);

        $this->validate([
            'form.title' => 'required|string|max:255',
            'form.description' => 'nullable|string',
        ]);

        try {
            $this->diary = app(DiaryService::class)->update($this->diary, $this->form);
        } catch (DiaryException $e) {
            $this->addError('form.title', $e->getMessage());

            return;
        }

        $this->showEdit = false;
        $this->dispatch('updateSidebar');
    }

    public function activate(): void
    {
        Gate::authorize('update', $this->diary);

        try {
            $this->diary = app(DiaryService::class)->activate($this->diary);
        } catch (DiaryException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->dispatch('updateSidebar');
    }

    public function archive(): void
    {
        Gate::authorize('update', $this->diary);

        $this->diary = app(DiaryService::class)->archive($this->diary);
        $this->dispatch('updateSidebar');
    }

    public function deleteDiary()
    {
        Gate::authorize('delete', $this->diary);

        app(DiaryService::class)->delete($this->diary);
        $this->dispatch('updateSidebar');

        return $this->redirect(route('diary.dashboard'), navigate: true);
    }

    protected function afterLinkChange(): void
    {
        $this->cancelLinking();
        $this->diary->refresh();
        $this->dispatch('updateSidebar');
    }

    public function render()
    {
        $diary = $this->diary;
        $assignments = app(DiaryService::class)->nodeAssignments($diary);
        $entities = $assignments === []
            ? collect()
            : OrganizationEntity::with('type:id,name')->whereIn('id', array_keys($assignments))->get()->keyBy('id');

        $nodes = collect($assignments)->map(fn ($inherit, $entityId) => [
            'entity' => $entities->get($entityId),
            'inherit' => $inherit,
        ])->filter(fn ($n) => $n['entity'])->values();

        return view('diary::livewire.show', [
            'diary' => $diary,
            'nodes' => $nodes,
            'linkCandidates' => $this->linkCandidates(),
        ])->layout('platform::layouts.app');
    }
}
