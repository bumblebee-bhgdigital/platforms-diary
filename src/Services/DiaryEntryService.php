<?php

namespace Platform\Diary\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\User;
use Platform\Diary\Enums\DiaryEntryType;
use Platform\Diary\Enums\DiaryEntryVisibility;
use Platform\Diary\Exceptions\DiaryException;
use Platform\Diary\Models\Diary;
use Platform\Diary\Models\DiaryEntry;

/**
 * Use-Cases rund um Diary-Einträge. Autorisiert NICHT (Gate/Entry-Methoden an der Grenze),
 * validiert nur Fachregeln — wie DiaryService.
 */
class DiaryEntryService
{
    public function create(User $author, Diary $diary, array $data): DiaryEntry
    {
        $content = trim((string) ($data['content'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));
        if ($content === '' && $title === '') {
            throw new DiaryException('Titel oder Inhalt ist erforderlich.');
        }

        return DB::transaction(function () use ($author, $diary, $data, $title, $content) {
            $entry = DiaryEntry::create([
                'team_id' => $diary->team_id,
                'diary_id' => $diary->id,
                'entry_at' => $data['entry_at'] ?? now(),
                'title' => $title !== '' ? $title : null,
                'content' => $content !== '' ? $content : null,
                'type' => $this->type($data['type'] ?? null),
                'visibility' => $this->visibility($data['visibility'] ?? null) ?? DiaryEntryVisibility::PRIVATE,
                'author_user_id' => $author->id,
                'created_by_user_id' => $author->id,
                'tags' => $this->tags($data['tags'] ?? null),
            ]);
            if (! empty($data['links'])) {
                $this->setLinks($entry, $data['links']);
            }

            return $entry->refresh();
        });
    }

    public function update(DiaryEntry $entry, array $data): DiaryEntry
    {
        return DB::transaction(function () use ($entry, $data) {
            $attrs = [];
            foreach (['title', 'content'] as $f) {
                if (array_key_exists($f, $data)) {
                    $v = trim((string) $data[$f]);
                    $attrs[$f] = $v !== '' ? $v : null;
                }
            }
            if (! empty($data['entry_at'])) {
                $attrs['entry_at'] = $data['entry_at'];
            }
            if (array_key_exists('type', $data)) {
                $attrs['type'] = $this->type($data['type']);
            }
            if (array_key_exists('visibility', $data)) {
                $attrs['visibility'] = $this->visibility($data['visibility']) ?? DiaryEntryVisibility::PRIVATE;
            }
            if (array_key_exists('tags', $data)) {
                $attrs['tags'] = $this->tags($data['tags']);
            }
            if ($attrs !== []) {
                $entry->update($attrs);
            }
            if (array_key_exists('links', $data)) {
                $this->setLinks($entry, (array) $data['links']);
            }
            if ($entry->title === null && $entry->content === null) {
                throw new DiaryException('Titel oder Inhalt ist erforderlich.');
            }

            return $entry->refresh();
        });
    }

    public function delete(DiaryEntry $entry): void
    {
        $entry->delete();
    }

    /** Ersetzt alle Verweise. links = [{type, id}], type ist ein Morph-Alias oder Klassenname. */
    public function setLinks(DiaryEntry $entry, array $links): void
    {
        $rows = [];
        foreach ($links as $link) {
            $type = (string) ($link['type'] ?? '');
            $id = (int) ($link['id'] ?? 0);
            if ($type === '' || $id <= 0) {
                throw new DiaryException('Jeder Verweis braucht type (Morph-Alias) und id.');
            }
            $rows[$type . ':' . $id] = ['linkable_type' => $type, 'linkable_id' => $id];
        }

        $entry->links()->delete();
        foreach ($rows as $row) {
            $entry->links()->create($row);
        }
    }

    private function type(mixed $value): ?DiaryEntryType
    {
        if ($value === null || $value === '') {
            return null;
        }

        return DiaryEntryType::tryFrom((string) $value)
            ?? throw new DiaryException('type muss einer von ' . implode(', ', DiaryEntryType::values()) . ' sein.');
    }

    private function visibility(mixed $value): ?DiaryEntryVisibility
    {
        if ($value === null || $value === '') {
            return null;
        }

        return DiaryEntryVisibility::tryFrom((string) $value)
            ?? throw new DiaryException('visibility muss einer von ' . implode(', ', DiaryEntryVisibility::values()) . ' sein.');
    }

    /** @return string[]|null */
    private function tags(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $tags = is_array($value) ? $value : explode(',', (string) $value);
        $tags = array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), $tags), fn ($t) => $t !== '')));

        return $tags === [] ? null : $tags;
    }
}
