<?php

/**
 * EntriesFeed Livewire Component — read-only Tagebuch-Ansicht eines Diaries.
 *
 * Einträge kommen per MCP (diary.entries.*); hier werden sie nur gelesen:
 * Kennzahlen (Einträge, Schreibtage, Streak), Schreib-Heatmap, Rückblick,
 * Filter (Art/Tag/Suche) und eine nach Monat/Tag gruppierte Timeline mit gerendertem Markdown.
 * Sichtbarkeit pro Eintrag über DiaryEntry::visibleTo (wie in den Tools).
 */

namespace Platform\Diary\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Core\Content\ContentParser;
use Platform\Diary\Enums\DiaryEntryType;
use Platform\Diary\Models\Diary;
use Platform\Diary\Models\DiaryEntry;

class EntriesFeed extends Component
{
    private const PAGE = 30;
    private const HEATMAP_WEEKS = 26;

    public Diary $diary;

    #[Url(as: 'art')]
    public string $type = '';

    #[Url(as: 'tag')]
    public string $tag = '';

    #[Url(as: 'q')]
    public string $search = '';

    public int $limit = self::PAGE;

    public function mount(Diary $diary): void
    {
        Gate::authorize('view', $diary);
        $this->diary = $diary;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'tag', 'search'], true)) {
            $this->limit = self::PAGE;
        }
    }

    public function filterType(string $type): void
    {
        $this->type = $this->type === $type ? '' : $type;
        $this->limit = self::PAGE;
    }

    public function filterTag(string $tag): void
    {
        $this->tag = $this->tag === $tag ? '' : $tag;
        $this->limit = self::PAGE;
    }

    public function resetFilters(): void
    {
        $this->reset('type', 'tag', 'search');
        $this->limit = self::PAGE;
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE;
    }

    private function baseQuery()
    {
        return DiaryEntry::query()
            ->where('diary_id', $this->diary->id)
            ->visibleTo(Auth::user());
    }

    public function render()
    {
        // Leichtgewichtige Gesamtsicht (ohne Inhalt) für Kennzahlen, Heatmap und Filter-Zähler
        $all = $this->baseQuery()->get(['id', 'entry_at', 'type', 'tags']);

        $query = $this->baseQuery()->with('author:id,name,type,avatar');
        if ($this->type !== '') {
            $this->type === 'none' ? $query->whereNull('type') : $query->where('type', $this->type);
        }
        if ($this->tag !== '') {
            $query->whereJsonContains('tags', $this->tag);
        }
        if (trim($this->search) !== '') {
            $term = '%' . trim($this->search) . '%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('content', 'like', $term));
        }
        $filteredTotal = (clone $query)->count();
        $entries = $query->with('links')->orderByDesc('entry_at')->orderByDesc('id')->take($this->limit)->get();

        $parser = app(ContentParser::class);
        $blocks = $entries->mapWithKeys(fn (DiaryEntry $e) => [$e->id => $e->content ? $parser->parse($e->content)['blocks'] : []]);

        $filtersActive = $this->type !== '' || $this->tag !== '' || trim($this->search) !== '';

        return view('diary::livewire.entries-feed', [
            'groups' => $this->groupByMonthAndDay($entries),
            'blocks' => $blocks,
            'linkLabels' => $this->linkLabels($entries),
            'filteredTotal' => $filteredTotal,
            'hasMore' => $filteredTotal > $entries->count(),
            'filtersActive' => $filtersActive,
            'stats' => $this->stats($all),
            'heatmap' => $this->heatmap($all),
            'typeCounts' => $this->typeCounts($all),
            'topTags' => $this->topTags($all),
            'flashback' => $filtersActive ? null : $this->flashback(),
        ]);
    }

    /** @return array<int, array{label: string, days: array<int, array{date: CarbonImmutable, entries: Collection}>}> */
    private function groupByMonthAndDay(Collection $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $at = CarbonImmutable::parse($entry->entry_at);
            $m = $at->format('Y-m');
            $d = $at->format('Y-m-d');
            $groups[$m]['label'] ??= $at->locale('de')->isoFormat('MMMM YYYY');
            $groups[$m]['days'][$d]['date'] ??= $at->startOfDay();
            $groups[$m]['days'][$d]['entries'][] = $entry;
        }

        return array_values(array_map(fn ($g) => ['label' => $g['label'], 'days' => array_values($g['days'])], $groups));
    }

    private function stats(Collection $all): array
    {
        $days = $all->map(fn ($e) => CarbonImmutable::parse($e->entry_at)->format('Y-m-d'))->unique()->sort()->values();
        $daySet = array_flip($days->all());

        // Streak: zusammenhängende Schreibtage bis heute (oder gestern, damit der Tag noch "offen" ist)
        $streak = 0;
        $cursor = CarbonImmutable::today();
        if (! isset($daySet[$cursor->format('Y-m-d')])) {
            $cursor = $cursor->subDay();
        }
        while (isset($daySet[$cursor->format('Y-m-d')])) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        // Längste Serie
        $longest = 0;
        $run = 0;
        $prev = null;
        foreach ($days as $day) {
            $date = CarbonImmutable::parse($day);
            $run = $prev && $prev->addDay()->isSameDay($date) ? $run + 1 : 1;
            $longest = max($longest, $run);
            $prev = $date;
        }

        $last = $all->max('entry_at');

        return [
            'total' => $all->count(),
            'days' => $days->count(),
            'streak' => $streak,
            'longest' => $longest,
            'last' => $last ? CarbonImmutable::parse($last) : null,
            'first' => $days->first() ? CarbonImmutable::parse($days->first()) : null,
        ];
    }

    /** Wochen-Spalten (Mo–So) der letzten HEATMAP_WEEKS Wochen mit Level 0–4 je Tag. */
    private function heatmap(Collection $all): array
    {
        $counts = $all->countBy(fn ($e) => CarbonImmutable::parse($e->entry_at)->format('Y-m-d'));
        $max = max(1, (int) $counts->max());
        $today = CarbonImmutable::today();
        $start = $today->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(self::HEATMAP_WEEKS - 1);

        $weeks = [];
        $months = [];
        for ($w = 0; $w < self::HEATMAP_WEEKS; $w++) {
            $col = [];
            for ($d = 0; $d < 7; $d++) {
                $date = $start->addDays($w * 7 + $d);
                $n = (int) ($counts[$date->format('Y-m-d')] ?? 0);
                $col[] = [
                    'date' => $date,
                    'count' => $n,
                    'future' => $date->greaterThan($today),
                    'level' => $n === 0 ? 0 : (int) min(4, max(1, ceil($n / $max * 4))),
                ];
            }
            $first = $start->addDays($w * 7);
            // Monatslabel in der ersten Woche des Monats (erste Spalte nur, wenn der Monat dort noch ≥ 2 Wochen läuft)
            if ($first->day <= 7 || ($w === 0 && $first->day <= $first->daysInMonth - 14)) {
                $months[$w] = $first->locale('de')->isoFormat('MMM');
            }
            $weeks[] = $col;
        }

        return ['weeks' => $weeks, 'months' => $months];
    }

    private function typeCounts(Collection $all): array
    {
        $counts = $all->countBy(fn ($e) => $e->type?->value ?? 'none');

        return collect(DiaryEntryType::cases())
            ->map(fn (DiaryEntryType $t) => ['type' => $t, 'count' => (int) ($counts[$t->value] ?? 0)])
            ->filter(fn ($row) => $row['count'] > 0)
            ->values()
            ->all();
    }

    private function topTags(Collection $all, int $limit = 12): array
    {
        return $all->flatMap(fn ($e) => $e->tags ?? [])
            ->countBy()
            ->sortDesc()
            ->take($limit)
            ->all();
    }

    /**
     * Rückblick: ein älterer Eintrag (≥ 7 Tage). Bevorzugt "an diesem Tag" in früheren Monaten/Jahren,
     * sonst ein pro Tag stabil gewählter Eintrag.
     */
    private function flashback(): ?DiaryEntry
    {
        $today = CarbonImmutable::today();
        $older = $this->baseQuery()->where('entry_at', '<', $today->subDays(7));

        $onThisDay = (clone $older)->whereDay('entry_at', $today->day)->orderByDesc('entry_at')->first();
        if ($onThisDay) {
            return $onThisDay;
        }

        $count = (clone $older)->count();
        if ($count === 0) {
            return null;
        }

        return (clone $older)->orderBy('id')->skip(crc32($today->format('Y-m-d')) % $count)->first();
    }

    /** Anzeigenamen der Verweise (best effort; unbekannte Morph-Typen bleiben "Typ #id"). */
    private function linkLabels(Collection $entries): array
    {
        $labels = [];
        foreach ($entries->flatMap->links as $link) {
            $key = $link->linkable_type . ':' . $link->linkable_id;
            $name = null;
            try {
                $model = $link->linkable;
                if ($model) {
                    $name = method_exists($model, 'getDisplayName') ? $model->getDisplayName() : ($model->name ?? $model->title ?? null);
                }
            } catch (\Throwable) {
                // Morph-Typ nicht auflösbar -> Fallback
            }
            $labels[$key] = $name ?: (class_basename($link->linkable_type) . ' #' . $link->linkable_id);
        }

        return $labels;
    }
}
