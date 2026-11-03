<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleCalendarServiceUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Google Calendar could not be reached. Please try again shortly.');
    }
}
