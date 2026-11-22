{{--
    Einträge eines Diaries — read-only (nx-Design-System).
    Einträge entstehen per MCP; hier: Kennzahlen · Schreibrhythmus · Rückblick · Filter · Timeline.
    Farbe ist bedeutungstragend: Tonfarbe = Art des Eintrags (DiaryEntryType::tone()), Heatmap = Schreibmenge.
--}}

<div class="space-y-6">
    @if($stats['total'] === 0)
        <x-nx-card>
            <x-nx-empty icon="heroicon-o-book-open">
                Noch keine Einträge. Einträge entstehen über MCP (<code>diary.entries.POST</code>) — durch dich oder den verantwortlichen Agenten.
            </x-nx-empty>
        </x-nx-card>
    @else
        {{-- Kennzahlen --}}
        <x-nx-stat-grid :cols="4">
            <x-nx-stat label="Einträge" :value="$stats['total']" icon="heroicon-o-book-open"
                       :hint="$stats['first'] ? 'seit ' . $stats['first']->locale('de')->isoFormat('D. MMM YYYY') : null" />
            <x-nx-stat label="Schreibtage" :value="$stats['days']" icon="heroicon-o-calendar-days" />
            <x-nx-stat label="Aktuelle Serie" :value="$stats['streak'] . ' ' . ($stats['streak'] === 1 ? 'Tag' : 'Tage')"
                       icon="heroicon-o-fire" :accent="$stats['streak'] > 0 ? 'var(--nx-tone-amber)' : null"
                       :hint="'Längste: ' . $stats['longest'] . ' ' . ($stats['longest'] === 1 ? 'Tag' : 'Tage')" />
            <x-nx-stat label="Zuletzt" :value="$stats['last']?->locale('de')->diffForHumans(short: true) ?? '–'" icon="heroicon-o-clock"
                       :hint="$stats['last']?->locale('de')->isoFormat('dd, D. MMM · HH:mm')" />
        </x-nx-stat-grid>

        {{-- Schreibrhythmus (Heatmap, letzte 26 Wochen) --}}
        <x-nx-card>
            <div class="mb-3 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    @svg('heroicon-o-chart-bar-square', 'w-4 h-4 text-[color:var(--nx-faint)]')
                    <span class="text-sm font-semibold text-[color:var(--nx-text)]">Schreibrhythmus</span>
                    <span class="text-xs text-[color:var(--nx-faint)]">letzte 26 Wochen</span>
                </div>
                <div class="flex items-center gap-1 text-[10px] text-[color:var(--nx-faint)]">
                    weniger
                    @foreach([0, 1, 2, 3, 4] as $lvl)
                        <span class="inline-block rounded-[2px]" style="width:10px;height:10px;{{ $lvl === 0 ? 'background:var(--nx-accent-soft)' : 'background:var(--nx-accent);opacity:' . (0.2 + $lvl * 0.2) }}"></span>
                    @endforeach
                    mehr
                </div>
            </div>
            <div class="overflow-x-auto">
                <div class="inline-flex flex-col gap-1">
                    <div class="flex gap-[3px] pl-6 text-[10px] text-[color:var(--nx-faint)]">
                        @foreach($heatmap['weeks'] as $w => $week)
                            <span style="width:11px" class="whitespace-nowrap overflow-visible">{{ $heatmap['months'][$w] ?? '' }}</span>
                        @endforeach
                    </div>
                    <div class="flex gap-[3px]">
                        <div class="flex flex-col gap-[3px] pr-1 text-[9px] leading-[11px] text-[color:var(--nx-faint)]" style="width:20px">
                            @foreach(['Mo', '', 'Mi', '', 'Fr', '', ''] as $wd)
                                <span style="height:11px">{{ $wd }}</span>
                            @endforeach
                        </div>
                        @foreach($heatmap['weeks'] as $week)
                            <div class="flex flex-col gap-[3px]">
                                @foreach($week as $cell)
                                    <span class="block rounded-[2px]"
                                          title="{{ $cell['date']->locale('de')->isoFormat('dd, D. MMM YYYY') }}: {{ $cell['count'] }} {{ $cell['count'] === 1 ? 'Eintrag' : 'Einträge' }}"
                                          style="width:11px;height:11px;{{ $cell['future'] ? 'background:transparent' : ($cell['level'] === 0 ? 'background:var(--nx-accent-soft)' : 'background:var(--nx-accent);opacity:' . (0.2 + $cell['level'] * 0.2)) }}"></span>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-nx-card>

        {{-- Rückblick --}}
        @if($flashback)
            @php
                $fbAt = \Carbon\CarbonImmutable::parse($flashback->entry_at)->locale('de');
                $fbText = \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags(\Illuminate\Support\Str::markdown((string) $flashback->content)))), 260);
            @endphp
            <x-nx-callout variant="neutral" icon="heroicon-o-arrow-uturn-left"
                          :title="($fbAt->day === now()->day ? 'An diesem Tag · ' : 'Rückblick · ') . $fbAt->diffForHumans() . ' · ' . $fbAt->isoFormat('D. MMMM YYYY')">
                @if($flashback->title)<span class="font-medium">{{ $flashback->title }}</span>@if($fbText) — @endif @endif
                <span class="italic">{{ $fbText }}</span>
            </x-nx-callout>
        @endif

        {{-- Filter --}}
        <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <div class="w-full sm:w-64">
                    <x-nx-input-text name="search" size="sm" placeholder="In Einträgen suchen …" wire:model.live.debounce.300ms="search" />
                </div>
                @foreach($typeCounts as $row)
                    @php $active = $type === $row['type']->value; @endphp
                    <button type="button" wire:click="filterType('{{ $row['type']->value }}')" wire:key="type-chip-{{ $row['type']->value }}"
                            @class([
                                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors',
                                'border-[color:var(--nx-accent)] bg-[color:var(--nx-accent)] text-[color:var(--nx-on-accent)]' => $active,
                                'border-[color:var(--nx-line)] text-[color:var(--nx-muted)] hover:bg-[color:var(--nx-hover)]' => ! $active,
                            ])>
                        <span class="h-2 w-2 rounded-full" style="background:{{ $row['type']->tone() }}"></span>
                        {{ $row['type']->label() }}
                        <span class="tabular-nums opacity-70">{{ $row['count'] }}</span>
                    </button>
                @endforeach
                @if($filtersActive)
                    <button type="button" wire:click="resetFilters" class="inline-flex items-center gap-1 text-xs text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]">
                        @svg('heroicon-o-x-mark', 'w-3.5 h-3.5') Filter zurücksetzen
                    </button>
                @endif
            </div>
            @if($topTags)
                <div class="flex flex-wrap items-center gap-1.5">
                    @svg('heroicon-o-hashtag', 'w-3.5 h-3.5 text-[color:var(--nx-faint)]')
                    @foreach($topTags as $t => $n)
                        <button type="button" wire:click="filterTag(@js($t))" wire:key="tag-chip-{{ md5($t) }}"
                                @class([
                                    'rounded-[6px] px-1.5 py-0.5 text-xs transition-colors',
                                    'bg-[color:var(--nx-accent)] text-[color:var(--nx-on-accent)]' => $tag === $t,
                                    'bg-[color:var(--nx-accent-soft)] text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]' => $tag !== $t,
                                ])>
                            {{ $t }} <span class="tabular-nums opacity-60">{{ $n }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Timeline --}}
        @if($groups === [])
            <x-nx-card>
                <x-nx-empty icon="heroicon-o-magnifying-glass">
                    Keine Einträge für diese Filter.
                    <x-slot name="action"><x-nx-button variant="secondary" size="sm" wire:click="resetFilters">Filter zurücksetzen</x-nx-button></x-slot>
                </x-nx-empty>
            </x-nx-card>
        @else
            <div class="space-y-8">
                @foreach($groups as $month)
                    <section wire:key="month-{{ $month['label'] }}" class="space-y-4">
                        <div class="flex items-center gap-3">
                            <h3 class="m-0 text-xs font-semibold uppercase tracking-wider text-[color:var(--nx-muted)]">{{ $month['label'] }}</h3>
                            <div class="h-px flex-1 bg-[color:var(--nx-line)]"></div>
                        </div>

                        @foreach($month['days'] as $day)
                            @php $d = $day['date']->locale('de'); @endphp
                            <div wire:key="day-{{ $d->format('Y-m-d') }}" class="flex gap-4">
                                {{-- Datum --}}
                                <div class="w-12 shrink-0 pt-3 text-center">
                                    <div class="text-[10px] font-medium uppercase tracking-wide text-[color:var(--nx-faint)]">{{ $d->isoFormat('dd') }}</div>
                                    <div @class(['text-2xl font-semibold leading-none tabular-nums', 'text-[color:var(--nx-text)]' => ! $d->isToday(), 'text-[color:var(--nx-tone-amber)]' => $d->isToday()])>{{ $d->day }}</div>
                                    @if($d->isToday())<div class="mt-1 text-[10px] text-[color:var(--nx-tone-amber)]">heute</div>@endif
                                </div>

                                {{-- Einträge des Tages --}}
                                <div class="min-w-0 flex-1 space-y-3 border-l border-[color:var(--nx-line)] pl-4">
                                    @foreach($day['entries'] as $entry)
                                        @php
                                            $tone = $entry->type?->tone() ?? 'var(--nx-line-strong)';
                                            $isAgent = $entry->author?->type === 'ai_user';
                                            $entryBlocks = $blocks[$entry->id] ?? [];
                                            $long = mb_strlen((string) $entry->content) > 700;
                                        @endphp
                                        <article wire:key="entry-{{ $entry->id }}" class="relative">
                                            {{-- Punkt auf der Zeitleiste --}}
                                            <span class="absolute -left-[21px] top-4 h-2.5 w-2.5 rounded-full ring-2 ring-[color:var(--nx-surface)]" style="background:{{ $tone }}"></span>

                                            <x-nx-card>
                                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-[color:var(--nx-faint)]">
                                                    @if($entry->type)
                                                        <span class="inline-flex items-center gap-1 font-medium" style="color:{{ $tone }}">
                                                            @svg($entry->type->icon(), 'w-3.5 h-3.5') {{ $entry->type->label() }}
                                                        </span>
                                                        <span>·</span>
                                                    @endif
                                                    <span class="tabular-nums">{{ \Carbon\CarbonImmutable::parse($entry->entry_at)->format('H:i') }}</span>
                                                    @if($entry->author)
                                                        <span>·</span>
                                                        <span class="inline-flex items-center gap-1.5">
                                                            <x-nx-avatar size="sm" :name="$entry->author->name" :src="$entry->author->avatarUrl()" />
                                                            <span class="text-[color:var(--nx-muted)]">{{ $entry->author->name }}</span>
                                                            @if($isAgent)
                                                                <span class="inline-flex items-center gap-0.5" title="Von einem Agenten geschrieben">@svg('heroicon-o-cpu-chip', 'w-3.5 h-3.5') Agent</span>
                                                            @endif
                                                        </span>
                                                    @endif
                                                    <span class="ml-auto inline-flex items-center gap-1"
                                                          title="{{ $entry->visibility === \Platform\Diary\Enums\DiaryEntryVisibility::PRIVATE ? 'Privat: nur Autor und Diary-Owner' : 'Knoten: alle mit Zugriff auf das Diary' }}">
                                                        @svg($entry->visibility === \Platform\Diary\Enums\DiaryEntryVisibility::PRIVATE ? 'heroicon-o-lock-closed' : 'heroicon-o-users', 'w-3.5 h-3.5')
                                                        {{ $entry->visibility?->label() }}
                                                    </span>
                                                </div>

                                                @if($entry->title)
                                                    <h4 class="mb-0 mt-2 text-base font-semibold text-[color:var(--nx-text)]">{{ $entry->title }}</h4>
                                                @endif

                                                @if($entryBlocks)
                                                    <div x-data="{ open: {{ $long ? 'false' : 'true' }} }" class="mt-2">
                                                        <div :class="open ? '' : 'max-h-64 overflow-hidden'">
                                                            <x-nx-content :blocks="$entryBlocks" class="text-sm" />
                                                        </div>
                                                        @if($long)
                                                            <button type="button" x-on:click="open = !open"
                                                                    class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]">
                                                                <span x-text="open ? 'Weniger anzeigen' : 'Weiterlesen'"></span>
                                                                <span x-bind:class="open ? 'rotate-180' : ''" class="inline-flex transition-transform">@svg('heroicon-o-chevron-down', 'w-3.5 h-3.5')</span>
                                                            </button>
                                                        @endif
                                                    </div>
                                                @endif

                                                @if(! empty($entry->tags) || $entry->links->isNotEmpty())
                                                    <div class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-[color:var(--nx-line)] pt-3">
                                                        @foreach($entry->tags ?? [] as $t)
                                                            <button type="button" wire:click="filterTag(@js($t))"
                                                                    class="rounded-[6px] bg-[color:var(--nx-accent-soft)] px-1.5 py-0.5 text-xs text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]">#{{ $t }}</button>
                                                        @endforeach
                                                        @foreach($entry->links as $link)
                                                            <span class="inline-flex items-center gap-1 rounded-[6px] border border-[color:var(--nx-line)] px-1.5 py-0.5 text-xs text-[color:var(--nx-muted)]">
                                                                @svg('heroicon-o-link', 'w-3 h-3') {{ $linkLabels[$link->linkable_type . ':' . $link->linkable_id] ?? $link->linkable_type }}
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </x-nx-card>
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </section>
                @endforeach

                <div class="flex flex-col items-center gap-2 pt-2">
                    <span class="text-xs text-[color:var(--nx-faint)] tabular-nums">
                        {{ min($limit, $filteredTotal) }} von {{ $filteredTotal }} {{ $filteredTotal === 1 ? 'Eintrag' : 'Einträgen' }}
                    </span>
                    @if($hasMore)
                        <x-nx-button variant="secondary" size="sm" wire:click="loadMore">Ältere Einträge laden</x-nx-button>
                    @endif
                </div>
            </div>
        @endif
    @endif
</div>
