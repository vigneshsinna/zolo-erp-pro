<?php

declare(strict_types=1);

namespace App\Ai\Telemetry;

interface TelemetrySink
{
    public function send(ModelTelemetry $event): void;
}
