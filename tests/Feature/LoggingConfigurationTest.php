<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LoggingConfigurationTest extends TestCase
{
    public function test_logging_channel_resolves_and_logs_without_errors(): void
    {
        Log::info('Health and reliability logging test entry');

        $this->assertTrue(true);
    }
}
