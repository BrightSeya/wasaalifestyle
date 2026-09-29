<?php

namespace App\Enums;

// Must match the category one-hot columns used by the ML slot recommender.
enum Category: string
{
    case Work = 'work';
    case Exercise = 'exercise';
    case Leisure = 'leisure';
    case Errand = 'errand';
    case Social = 'social';
    case Rest = 'rest';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
