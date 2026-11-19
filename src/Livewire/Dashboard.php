<?php

/**
 * Dashboard Livewire Component — Übersicht aller Diaries des Teams.
 *
 * Zeigt die für den User sichtbaren Diaries der Team-Familie (Sichtbarkeit via Diary::visibleTo),
 * Kennzahl-Kacheln und das Anlegen neuer Diaries. Verknüpfung mit Knoten: Sidebar bzw. Detailseite.
 */

namespace Platform\Diary\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Organization\Services\EntityDimensionBridge;
use Platform\Diary\Enums\DiaryStatus;
use Platform\Diary\Exceptions\DiaryException;
use Platform\Diary\Livewire\Concerns\ResolvesTeamFamily;
use Platform\Diary\Models\Diary;
use Platform\Diary\Models\DiaryNode;
use Platform\Diary\Services\DiaryService;

class Dashboard extends Component
{
    use ResolvesTeamFamily;

    public string $statusFilter = '';

    public bool $showCreate = false;
    public array $form = [
        'title' => '',
        'description' => '',
    ];

    /**
     * Dispatch comms-Event
     *
     * Wird nach dem Rendern aufgerufen (Kommunikation/Notifications).
     */
    public function rendered()
    {
        $this->dispatch('comms', [
            'model' => null,
            'modelId' => null,
            'subject' => 'Diaries',
            'description' => 'Übersicht aller Diaries',
            'url' => route('diary.dashboard'),
            'source' => 'diary.dashboard',
            'recipients' => [],
            'meta' => ['view_type' => 'dashboard'],
        ]);
    }

    /** Liste aktualisieren, wenn Diaries (z. B. in der Sidebar) verknüpft/gelöst werden. */
    #[On('updateSidebar')]
    public function refreshList(): void
    {
    }

    public function openCreate(): void
    {
        Gate::authorize('create', Diary::class);
        $this->reset('form');
        $this->resetValidation();
        $this->showCreate = true;
    }

    public function create()
    {
        Gate::authorize('create', Diary::class);

        $this->validate([
            'form.title' => 'required|string|max:255',
            'form.description' => 'nullable|string',
        ]);

        try {
            $diary = app(DiaryService::class)->create(Auth::user(), Auth::user()->currentTeam, $this->form);
        } catch (DiaryException $e) {
            $this->addError('form.title', $e->getMessage());

            return null;
        }

        $this->showCreate = false;
        $this->dispatch('updateSidebar');

        return $this->redirect(route('diary.diaries.show', $diary), navigate: true);
    }

    public function render()
    {
        $user = Auth::user();
        $teamIds = $this->relevantTeamIds($user);

        $all = Diary::query()
            ->with(['owner:id,name', 'responsibleAgent:id,name', 'team:id,name'])
            ->whereIn('team_id', $teamIds)
            ->visibleTo($user)
            ->orderBy('title')
            ->get();

        // Knoten je Diary (eine Abfrage); inherit_down aus den Zuordnungs-Einstellungen
        $nodesByDiary = [];
        $links = EntityDimensionBridge::linksForLinkables([Diary::MORPH_ALIAS], $all->pluck('id')->all(), false);
        $entityNames = OrganizationEntity::whereIn('id', $links->pluck('entity_id')->filter()->unique()->all())->pluck('name', 'id');
        $inherit = DiaryNode::query()->whereIn('diary_id', $all->pluck('id')->all())->get()
            ->mapWithKeys(fn ($r) => [$r->diary_id . ':' . $r->entity_id => (bool) $r->inherit_down]);
        foreach ($links as $link) {
            if ($link->entity_id) {
                $nodesByDiary[$link->linkable_id][] = [
                    'id' => (int) $link->entity_id,
                    'name' => $entityNames[$link->entity_id] ?? '—',
                    'inherit' => $inherit[$link->linkable_id . ':' . $link->entity_id] ?? false,
                ];
            }
        }

        $diaries = $this->statusFilter !== ''
            ? $all->filter(fn ($s) => $s->status->value === $this->statusFilter)->values()
            : $all;

        $stats = [
            'total' => $all->count(),
            'active' => $all->where('status', DiaryStatus::ACTIVE)->count(),
            'draft' => $all->where('status', DiaryStatus::DRAFT)->count(),
            'unlinked' => $all->filter(fn ($s) => empty($nodesByDiary[$s->id]))->count(),
        ];

        return view('diary::livewire.dashboard', [
            'diaries' => $diaries,
            'nodesByDiary' => $nodesByDiary,
            'stats' => $stats,
            'multiTeam' => count($teamIds) > 1,
            'statusOptions' => array_map(fn (DiaryStatus $s) => ['value' => $s->value, 'label' => $s->label()], DiaryStatus::cases()),
        ])->layout('platform::layouts.app');
    }
}
