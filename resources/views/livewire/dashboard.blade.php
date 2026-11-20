{{--
    Übersicht aller Diaries (nx-Design-System).
    Shell: x-ui-page*; Inhalt: x-nx-* + var(--nx-*). Datenform "gleichförmige Datensätze" -> Tabelle.
--}}

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Diaries" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Diaries', 'icon' => 'book-open'],
        ]">
            @can('create', \Platform\Diary\Models\Diary::class)
                <x-nx-button variant="primary" size="sm" wire:click="openCreate">
                    @svg('heroicon-o-plus', 'w-4 h-4')
                    <span>Neu</span>
                </x-nx-button>
            @endcan
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="contained" spacing="space-y-6">
        <x-nx-stat-grid :cols="4">
            <x-nx-stat label="Diaries gesamt" :value="$stats['total']" icon="heroicon-o-book-open" />
            <x-nx-stat label="Aktiv" :value="$stats['active']" icon="heroicon-o-check-circle" accent="var(--nx-success)" />
            <x-nx-stat label="Entwurf" :value="$stats['draft']" icon="heroicon-o-pencil-square" />
            <x-nx-stat label="Unverknüpft" :value="$stats['unlinked']" icon="heroicon-o-link-slash" accent="var(--nx-warning)" />
        </x-nx-stat-grid>

        <x-nx-section icon="heroicon-o-list-bullet" title="Alle Diaries" :hint="$diaries->count()"
                      description="Ein Diary ist ein persönliches Tagebuch und kann an mehreren Knoten hängen; die Vererbung nach unten ist pro Zuordnung wählbar.">
            <x-slot name="action">
                <x-nx-input-select name="statusFilter" size="sm" :options="$statusOptions" nullable="true" nullLabel="Alle Status" wire:model.live="statusFilter" />
            </x-slot>

            @if($diaries->isEmpty())
                <x-nx-card>
                    <x-nx-empty icon="heroicon-o-book-open">
                        Noch keine Diaries{{ $statusFilter !== '' ? ' in diesem Status' : '' }}.
                        @can('create', \Platform\Diary\Models\Diary::class)
                            <x-slot name="action">
                                <x-nx-button variant="secondary" size="sm" wire:click="openCreate">Erstes Diary anlegen</x-nx-button>
                            </x-slot>
                        @endcan
                    </x-nx-empty>
                </x-nx-card>
            @else
                <x-nx-card flush>
                    <x-nx-table>
                        <x-nx-table-header>
                            <x-nx-table-header-cell>Titel</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Knoten</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Status</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Owner</x-nx-table-header-cell>
                            <x-nx-table-header-cell align="right">Agent</x-nx-table-header-cell>
                        </x-nx-table-header>
                        <x-nx-table-body>
                            @foreach($diaries as $diary)
                                <x-nx-table-row wire:key="diary-row-{{ $diary->id }}" :href="route('diary.diaries.show', $diary)">
                                    <x-nx-table-cell>
                                        {{ $diary->title }}
                                        @if($multiTeam && $diary->team)
                                            <span class="ml-1 text-xs text-[color:var(--nx-muted)]">{{ $diary->team->name }}</span>
                                        @endif
                                    </x-nx-table-cell>
                                    <x-nx-table-cell>
                                        @forelse($nodesByDiary[$diary->id] ?? [] as $node)
                                            <span class="text-xs">{{ $node['name'] }}@if($node['inherit']) <span title="Wirkt auch an Unterknoten" class="text-[color:var(--nx-muted)]">↓</span>@endif</span>@if(! $loop->last), @endif
                                        @empty
                                            <span class="text-xs text-[color:var(--nx-muted)]">Unverknüpft</span>
                                        @endforelse
                                    </x-nx-table-cell>
                                    <x-nx-table-cell><x-nx-badge :variant="$diary->status->badgeVariant()" dot>{{ $diary->status->label() }}</x-nx-badge></x-nx-table-cell>
                                    <x-nx-table-cell>{{ $diary->owner?->name ?? '–' }}</x-nx-table-cell>
                                    <x-nx-table-cell align="right">{{ $diary->responsibleAgent?->name ?? '–' }}</x-nx-table-cell>
                                </x-nx-table-row>
                            @endforeach
                        </x-nx-table-body>
                    </x-nx-table>
                </x-nx-card>
            @endif
        </x-nx-section>
    </x-ui-page-container>

    {{-- Die Modul-Sidebar (Diaries nach Knoten) kommt aus diary.sidebar (Shell), wie im Planner. --}}

    {{-- Anlegen --}}
    <x-nx-modal wire:model="showCreate" size="md">
        <x-slot name="header">Diary anlegen</x-slot>
        <div class="space-y-4">
            <x-nx-input-text name="form.title" label="Titel" wire:model="form.title" required />
            <x-nx-input-textarea name="form.description" label="Beschreibung" wire:model="form.description" rows="3" />
            <p class="text-xs text-[color:var(--nx-muted)]">Das Diary startet als Entwurf. Knoten-Verknüpfung und Aktivierung folgen auf der Detailseite bzw. in der Sidebar.</p>
        </div>
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <x-nx-button variant="ghost" wire:click="$set('showCreate', false)">Abbrechen</x-nx-button>
                <x-nx-button variant="primary" wire:click="create">Anlegen</x-nx-button>
            </div>
        </x-slot>
    </x-nx-modal>
</x-ui-page>
