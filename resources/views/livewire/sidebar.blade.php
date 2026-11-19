{{--
    Sidebar View (nx-Design-System) — Diaries nach Knoten gruppiert, Unverknüpfte separat.
    Struktur wie planner::livewire.sidebar. Nur var(--nx-*) Tokens.
    Ein Diary kann unter mehreren Knoten erscheinen.
--}}

<div>
    <a x-show="!collapsed" href="{{ route('diary.dashboard') }}" wire:navigate
       class="block p-3 text-sm italic text-[var(--nx-text)] uppercase border-b border-[var(--nx-line)] mb-2 hover:text-[var(--nx-accent)]">
        Diary
    </a>

    {{-- Eingeklappt: Einstieg in die Übersicht --}}
    <div x-show="collapsed" class="px-2 py-2 border-b border-[var(--nx-line)]">
        <a href="{{ route('diary.dashboard') }}" wire:navigate title="Diaries"
           class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('diary.*') ? 'bg-[var(--nx-accent)]/10 text-[var(--nx-accent)]' : 'text-[var(--nx-text)] hover:bg-[var(--nx-bg)]' }}">
            @svg('heroicon-o-book-open', 'w-5 h-5')
        </a>
    </div>

    <div class="mt-2" x-show="!collapsed">
        {{-- Knoten-Gruppen (Entity-Typ -> Baum -> Diaries) --}}
        @foreach($entityTypeGroups as $typeGroup)
            <x-ui-sidebar-list wire:key="sg-type-group-{{ $typeGroup['type_id'] }}" :label="$typeGroup['type_name']">
                @foreach($typeGroup['entities'] as $entityNode)
                    @include('diary::livewire.partials.sidebar-entity-node', [
                        'node' => $entityNode,
                        'typeIcon' => $typeGroup['type_icon'] ?? null,
                        'linkingDiaryId' => $linkingDiaryId,
                        'linkingAnchor' => $linkingAnchor,
                        'linkInherit' => $linkInherit,
                        'linkCandidates' => $linkCandidates,
                    ])
                @endforeach
            </x-ui-sidebar-list>
        @endforeach

        {{-- Nicht verknüpfte Diaries --}}
        @if($unlinkedDiaries->isNotEmpty())
            <x-ui-sidebar-list label="Unverknüpft">
                @foreach($unlinkedDiaries as $diary)
                    @include('diary::livewire.partials.sidebar-diary-row', [
                        'diary' => $diary, 'nodeId' => null, 'inherit' => false,
                        'linkingDiaryId' => $linkingDiaryId, 'linkingAnchor' => $linkingAnchor, 'linkInherit' => $linkInherit, 'linkCandidates' => $linkCandidates,
                    ])
                @endforeach
            </x-ui-sidebar-list>
        @endif

        @if($entityTypeGroups->isEmpty() && $unlinkedDiaries->isEmpty())
            <div class="px-3 py-1 text-xs text-[var(--nx-muted)]">Keine Diaries</div>
        @endif

        {{-- Showcase (Gerüst-Beispiel) bleibt erreichbar --}}
        <x-ui-sidebar-list label="Entwicklung">
            <x-ui-sidebar-item :href="route('diary.showcase')">
                @svg('heroicon-o-swatch', 'w-4 h-4 text-[var(--nx-text)]')
                <span class="ml-2 text-sm">nx-Showcase</span>
            </x-ui-sidebar-item>
        </x-ui-sidebar-list>
    </div>
</div>
