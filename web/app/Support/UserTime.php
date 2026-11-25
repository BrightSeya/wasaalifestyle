<?php

namespace App\Support;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;

final class UserTime
{
    public static function timezone(User $user): string
    {
        return $user->timezone ?: 'Africa/Nairobi';
    }

    public static function local(User $user, DateTimeInterface|string $value): Carbon
    {
        $date = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse($value, 'UTC');

        return $date->setTimezone(self::timezone($user));
    }

    public static function utc(User $user, DateTimeInterface|string $value): Carbon
    {
        $date = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse($value, self::timezone($user));

        return $date->setTimezone('UTC');
    }
}
