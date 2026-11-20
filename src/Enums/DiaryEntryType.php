<?php

namespace Platform\Diary\Enums;

/** Art eines Diary-Eintrags (optional). */
enum DiaryEntryType: string
{
    case NOTE = 'note';
    case DECISION = 'decision';
    case INSIGHT = 'insight';
    case REFLECTION = 'reflection';
    case IDEA = 'idea';

    public function label(): string
    {
        return match ($this) {
            self::NOTE => 'Notiz',
            self::DECISION => 'Entscheidung',
            self::INSIGHT => 'Erkenntnis',
            self::REFLECTION => 'Reflexion',
            self::IDEA => 'Idee',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
