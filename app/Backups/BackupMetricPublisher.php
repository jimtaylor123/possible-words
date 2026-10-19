<?php

namespace App\Backups;

use Aws\CloudWatch\CloudWatchClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupMetricPublisher
{
    public function publish(string $name, float $value): void
    {
        try {
            (new CloudWatchClient([
                'version' => 'latest',
                'region' => config('backups.aws_region'),
            ]))->putMetricData([
                'Namespace' => 'PossibleWords/Backups',
                'MetricData' => [[
                    'MetricName' => $name,
                    'Value' => $value,
                    'Unit' => 'Count',
                ]],
            ]);
        } catch (Throwable $exception) {
            Log::error('Unable to publish backup CloudWatch metric; continuing without it.', [
                'metric' => $name,
                'exception' => $exception,
            ]);
        }
    }
}
