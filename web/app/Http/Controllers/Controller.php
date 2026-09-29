<?php

namespace App\Http\Controllers;

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

        return is_string($input) && Carbon::canBeCreatedFromFormat($input, 'Y-m-d')
            ? Carbon::createFromFormat('Y-m-d', $input)->startOfDay()
            : now()->startOfDay();
    }
}
