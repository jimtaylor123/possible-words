<?php

namespace App\Backups;

use Aws\CloudWatch\CloudWatchClient;

class BackupMetricPublisher
{
    public function publish(string $name, float $value): void
    {
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
    }
}
