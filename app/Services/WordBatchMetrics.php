<?php

namespace App\Services;

use Aws\CloudWatch\CloudWatchClient;
use Illuminate\Support\Facades\Log;

class WordBatchMetrics
{
    public function emit(string $name, float $value = 1): void
    {
        Log::info('Word batch metric', ['metric' => $name, 'value' => $value]);
        if (! app()->environment('production')) {
            return;
        }
        try {
            (new CloudWatchClient(['version' => 'latest', 'region' => config('services.ses.region')]))->putMetricData([
                'Namespace' => 'PossibleWords/WordBatches',
                'MetricData' => [['MetricName' => $name, 'Value' => $value, 'Unit' => 'Count']],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Word batch metric could not be sent.', ['metric' => $name, 'exception' => $exception]);
        }
    }
}
