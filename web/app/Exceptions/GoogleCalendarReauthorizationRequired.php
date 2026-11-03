<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleCalendarReauthorizationRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Google Calendar access has expired or been revoked. Reconnect the account in Settings.');
    }
}
