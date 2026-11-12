<?php

namespace Tests\Feature;

use Tests\TestCase;

class NoShowDatasetPreparationCommandTest extends TestCase
{
    public function test_real_data_preparation_requires_explicit_approval_confirmation(): void
    {
        $this->artisan('noshow:prepare-dataset')
            ->expectsOutput('Stopped: confirm institutional/supervisor approval before processing real user outcomes.')
            ->assertExitCode(1);
    }
}
