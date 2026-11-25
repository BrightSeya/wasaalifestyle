<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleCalendarReauthorizationRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Google Calendar access has expired, been revoked, or is missing Google Tasks permission. Reconnect the account in Settings.');
    }
}
