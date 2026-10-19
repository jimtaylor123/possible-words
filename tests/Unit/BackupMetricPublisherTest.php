<?php

use App\Backups\BackupMetricPublisher;
use Aws\CloudWatch\CloudWatchClient;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Illuminate\Foundation\Exceptions\Handler;

/** @return BackupMetricPublisher publisher whose CloudWatch client is the given mock */
function metricPublisherFor(CloudWatchClient $client): BackupMetricPublisher
{
    return new class($client) extends BackupMetricPublisher
    {
        public function __construct(private readonly CloudWatchClient $client) {}

        protected function client(): CloudWatchClient
        {
            return $this->client;
        }
    };
}

describe('backup metric publisher', function () {
    test('Given a successful CloudWatch call, it publishes the metric under the backup namespace', function () {
        $client = mock(CloudWatchClient::class);
        $client->shouldReceive('putMetricData')->once()->with([
            'Namespace' => 'PossibleWords/Backups',
            'MetricData' => [['MetricName' => 'BackupVerified', 'Value' => 1, 'Unit' => 'Count']],
        ])->andReturn(new Result([]));

        metricPublisherFor($client)->publish('BackupVerified', 1);
    });

    test('Given CloudWatch rejects the call, it reports the failure without breaking the caller', function () {
        $exception = new AwsException(
            'Missing credentials',
            new Command('PutMetricData'),
            ['code' => 'NoCredentialProviders'],
        );
        $client = mock(CloudWatchClient::class);
        $client->shouldReceive('putMetricData')->andThrow($exception);

        $handler = \Mockery::mock(Handler::class);
        $handler->shouldReceive('report')->once()->withArgs(fn (Throwable $reported): bool => str_contains(
            $reported->getMessage(),
            'Unable to publish backup metric [BackupFreshness]',
        ) && $reported->getPrevious() === $exception);
        app()->instance(Handler::class, $handler);

        metricPublisherFor($client)->publish('BackupFreshness', 0);
    });
});
