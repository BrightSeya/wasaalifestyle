<?php

namespace App\Http\Controllers;

use App\Support\UserTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

abstract class Controller
{
    /**
     * The ?date=YYYY-MM-DD query value as a date, or today if missing or invalid.
     */
    protected function dateFromQuery(Request $request): Carbon
    {
        $input = $request->query('date');
        $timezone = UserTime::timezone($request->user());

        return is_string($input) && Carbon::canBeCreatedFromFormat($input, 'Y-m-d')
            ? Carbon::createFromFormat('Y-m-d', $input, $timezone)->startOfDay()
            : Carbon::now($timezone)->startOfDay();
    }
}
