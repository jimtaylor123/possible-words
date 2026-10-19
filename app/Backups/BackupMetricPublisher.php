<?php

namespace App\Backups;

use Aws\CloudWatch\CloudWatchClient;
use RuntimeException;
use Throwable;

class BackupMetricPublisher
{
    public function publish(string $name, float $value): void
    {
        try {
            $this->client()->putMetricData([
                'Namespace' => 'PossibleWords/Backups',
                'MetricData' => [[
                    'MetricName' => $name,
                    'Value' => $value,
                    'Unit' => 'Count',
                ]],
            ]);
        } catch (Throwable $exception) {
            // Missing or misconfigured credentials must never fail a backup run
            // or the freshness check: the CloudWatch alarms treat absent metrics
            // as breaching, so an operator is still notified.
            report(new RuntimeException("Unable to publish backup metric [$name].", 0, $exception));
        }
    }

    protected function client(): CloudWatchClient
    {
        return new CloudWatchClient([
            'version' => 'latest',
            'region' => config('backups.aws_region'),
        ]);
    }
}
