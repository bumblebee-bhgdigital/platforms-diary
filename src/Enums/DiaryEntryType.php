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

    /** Heroicon je Art (Timeline, Filter-Chips). */
    public function icon(): string
    {
        return match ($this) {
            self::NOTE => 'heroicon-o-pencil',
            self::DECISION => 'heroicon-o-scale',
            self::INSIGHT => 'heroicon-o-light-bulb',
            self::REFLECTION => 'heroicon-o-sparkles',
            self::IDEA => 'heroicon-o-rocket-launch',
        };
    }

    /** nx-Tonfarbe je Art (bedeutungstragend: die Art eines Eintrags auf einen Blick). */
    public function tone(): string
    {
        return match ($this) {
            self::NOTE => 'var(--nx-tone-slate)',
            self::DECISION => 'var(--nx-tone-indigo)',
            self::INSIGHT => 'var(--nx-tone-amber)',
            self::REFLECTION => 'var(--nx-tone-violet)',
            self::IDEA => 'var(--nx-tone-teal)',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
