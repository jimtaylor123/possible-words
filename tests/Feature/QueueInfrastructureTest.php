<?php

use App\Events\DefinitionCreated;
use App\Events\DefinitionUpdated;
use App\Events\DefinitionVoted;
use App\Jobs\GenerateDefinitionExampleSentence;
use App\Jobs\GenerateWordDefinition;
use App\Models\Definition;
use App\Models\Word;
use App\Notifications\AppNotification;
use Symfony\Component\Yaml\Yaml;

describe('queue routing and infrastructure', function () {
    test('AI jobs and broadcasts use their configured destinations', function () {
        config()->set('queue.queues.definitions', 'https://sqs.example.test/definitions');
        config()->set('queue.queues.broadcasts', 'https://sqs.example.test/broadcasts');

        $word = Word::factory()->create();
        $definition = new Definition(['word_id' => $word->id]);
        $definition->id = 1;

        expect((new GenerateWordDefinition($word->id))->queue)->toBe('https://sqs.example.test/definitions')
            ->and((new GenerateDefinitionExampleSentence($definition->id))->queue)->toBe('https://sqs.example.test/definitions')
            ->and((new DefinitionCreated($definition))->broadcastQueue())->toBe('https://sqs.example.test/broadcasts')
            ->and((new DefinitionUpdated($definition))->broadcastQueue())->toBe('https://sqs.example.test/broadcasts')
            ->and((new DefinitionVoted($definition, true))->broadcastQueue())->toBe('https://sqs.example.test/broadcasts')
            ->and((new AppNotification(['message' => 'Hello']))->toBroadcast($word)->queue)->toBe('https://sqs.example.test/broadcasts');
    });

    test('production defines valid Lift queue workers and a tag-filtered budget', function () {
        $serverless = Yaml::parseFile(base_path('serverless.yml'));

        expect($serverless['plugins'])->toContain('serverless-lift')
            ->and($serverless['provider']['environment'])->toMatchArray([
                'QUEUE_CONNECTION' => 'sqs',
                'CACHE_STORE' => 'database',
                'SQS_QUEUE' => '${construct:default.queueUrl}',
                'SQS_BROADCASTS_QUEUE' => '${construct:broadcasts.queueUrl}',
                'SQS_DEFINITIONS_QUEUE' => '${construct:definitions.queueUrl}',
            ]);

        foreach (['default' => [2, 45], 'broadcasts' => [2, 15], 'definitions' => [2, 60]] as $name => [$concurrency, $timeout]) {
            expect($serverless['constructs'][$name]['type'])->toBe('queue')
                // Lift renders this as an AWS/SQS ApproximateNumberOfMessagesVisible
                // alarm for the construct's DLQ, with an email SNS subscription.
                ->and($serverless['constructs'][$name]['alarm'])->toBe('jimtayloremail@gmail.com')
                ->and($serverless['constructs'][$name]['maxConcurrency'])->toBe($concurrency)
                ->and($serverless['constructs'][$name]['batchSize'])->toBe(1)
                ->and($serverless['constructs'][$name]['worker'])->toMatchArray([
                    'handler' => 'Bref\\LaravelBridge\\Queue\\QueueHandler',
                    'runtime' => 'php-84',
                    'timeout' => $timeout,
                    'environment' => '${self:custom.applicationEnvironment}',
                ]);
        }

        $budget = $serverless['resources']['Resources']['ApplicationMonthlyBudget']['Properties'];
        $budgetNotificationThresholds = array_column(
            array_column($budget['NotificationsWithSubscribers'], 'Notification'),
            'Threshold',
        );

        expect($serverless['constructs']['definitions']['worker'])->not->toHaveKey('reservedConcurrency')
            ->and($serverless['provider']['tags'])->toBe(['Application' => 'possiblewords'])
            ->and($budget['Budget']['BudgetLimit'])->toBe(['Amount' => '${self:custom.budgetAmount}', 'Unit' => 'USD'])
            ->and($budget['Budget']['CostFilters'])->toBe(['TagKeyValue' => ['user:Application$possiblewords']])
            ->and($budgetNotificationThresholds)->toBe([10, 30, 50, 100])
            ->and($budget['ResourceTags'])->toBe([['Key' => 'Application', 'Value' => 'possiblewords']]);

        foreach ($serverless['resources']['extensions'] as $resource) {
            expect($resource['Properties']['Tags'])->toContain(['Key' => 'Application', 'Value' => 'possiblewords']);
        }
    });

    test('production formats word-batch alarms before emailing them', function () {
        $serverless = Yaml::parseFile(base_path('serverless.yml'));

        expect($serverless['functions']['alarmNotifier'])->toMatchArray([
            'handler' => 'alerts/alarm-notifier.handler',
            'runtime' => 'nodejs20.x',
            'timeout' => 30,
            'environment' => ['ALERT_EMAIL_TOPIC_ARN' => ['Ref' => 'WordBatchAlarmEmailTopic']],
        ])
            ->and($serverless['resources']['Resources']['WordBatchAlarmTopic']['Properties'])->not->toHaveKey('Subscription')
            ->and($serverless['resources']['Resources']['WordBatchAlarmEmailTopic']['Properties']['Subscription'])->toBe([
                ['Endpoint' => 'jimtayloremail@gmail.com', 'Protocol' => 'email'],
            ])
            ->and($serverless['resources']['Resources']['ArtisanLambdaErrorsAlarm']['Properties'])->toMatchArray([
                'AlarmName' => 'possiblewords-${sls:stage}-artisan-errors',
                'AlarmDescription' => 'The scheduled Artisan runner failed; the alert includes its recent error detail.',
            ])
            // Serverless generates AlarmNotifierLambdaFunction from functions.alarmNotifier.
            ->and($serverless['resources']['Resources']['AlarmNotifierSubscription']['Properties'])->toMatchArray([
                'TopicArn' => ['Ref' => 'WordBatchAlarmTopic'],
                'Protocol' => 'lambda',
                'Endpoint' => ['Fn::GetAtt' => ['AlarmNotifierLambdaFunction', 'Arn']],
            ]);
    });

    test('local example configuration defaults to the database queue', function () {
        expect(file_get_contents(base_path('.env.example')))->toContain('QUEUE_CONNECTION=database')
            ->and(config('queue.queues'))->toBe([
                'broadcasts' => 'broadcasts',
                'definitions' => 'definitions',
            ]);
    });
});
