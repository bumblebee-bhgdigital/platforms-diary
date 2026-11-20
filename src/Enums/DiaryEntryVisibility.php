<?php

namespace Platform\Diary\Enums;

/**
 * Sichtbarkeit eines Eintrags.
 *
 * private (Default): nur Autor und Diary-Owner. node: alle mit Lesezugriff auf das Diary
 * (also auf die verknüpften Knoten).
 */
enum DiaryEntryVisibility: string
{
    case PRIVATE = 'private';
    case NODE = 'node';

    public function label(): string
    {
        return match ($this) {
            self::PRIVATE => 'Privat',
            self::NODE => 'Knoten',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn (self $v) => $v->value, self::cases());
    }
}
