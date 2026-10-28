export async function handler(event) {
    const dependencies = await awsDependencies();

    return createNotifier(dependencies)(event);
}

export function createNotifier({ recentErrorsForLogGroup, publish }) {
    return async (event) => {
        await Promise.all((event.Records ?? []).map(async ({ Sns: { Message } }) => {
            const alarm = JSON.parse(Message);
            const state = alarm.NewStateValue;
            const functionName = alarm.Trigger?.Dimensions?.find(({ name }) => name === 'FunctionName')?.value;
            const logGroup = functionName ? `/aws/lambda/${functionName}` : null;
            const errors = state === 'ALARM' && logGroup ? await recentErrorsForLogGroup(logGroup, alarm.StateChangeTime) : [];
            const subject = `[Possible Words] ${state}: ${alarm.AlarmDescription ?? alarm.AlarmName}`;

            await publish({
                Subject: subject.slice(0, 100),
                Message: formatAlert(alarm, logGroup, errors),
            });
        }));
    };
}

let dependencies;

async function awsDependencies() {
    dependencies ??= Promise.all([
        importAwsSdk('@aws-sdk/client-cloudwatch-logs'),
        importAwsSdk('@aws-sdk/client-sns'),
    ]).then(([{ CloudWatchLogsClient, FilterLogEventsCommand }, { PublishCommand, SNSClient }]) => {
        const logs = new CloudWatchLogsClient({});
        const sns = new SNSClient({});

        return {
            recentErrorsForLogGroup: (logGroup, stateChangeTime) => recentErrors(logs, FilterLogEventsCommand, logGroup, stateChangeTime),
            publish: (message) => sns.send(new PublishCommand({ TopicArn: process.env.ALERT_EMAIL_TOPIC_ARN, ...message })),
        };
    });

    return dependencies;
}

function importAwsSdk(packageName) {
    return import(/* @vite-ignore */ packageName);
}

export async function recentErrors(logs, FilterLogEventsCommand, logGroupName, stateChangeTime) {
    const parsedStateChangeTime = Date.parse(stateChangeTime);
    const changedAt = Number.isNaN(parsedStateChangeTime) ? Date.now() : parsedStateChangeTime;
    const response = await logs.send(new FilterLogEventsCommand({
        logGroupName,
        startTime: changedAt - 10 * 60 * 1000,
        endTime: changedAt + 60 * 1000,
        limit: 1000,
    }));

    return summarizeErrors((response.events ?? []).map(({ message }) => message?.trim()));
}

export function summarizeErrors(messages) {
    return messages
        .filter((message) => message && /\b(?:ERROR|Exception|Invoke Error|Task timed out|Fatal error)\b/i.test(message))
        .map(compactError)
        .filter((message, index, entries) => entries.indexOf(message) === index)
        .slice(0, 3);
}

export function compactError(message) {
    const relevantLine = message.split('\n').find((line) => /\b(?:ERROR|Exception|Invoke Error|Task timed out|Fatal error)\b/i.test(line));

    return (relevantLine ?? message).replace(/\s+/g, ' ').slice(0, 700);
}

export function formatAlert(alarm, logGroup, errors) {
    const region = process.env.AWS_REGION ?? alarm.Region;
    const lines = [
        'Possible Words production alert',
        '',
        `Status: ${alarm.NewStateValue}`,
        `Alarm: ${alarm.AlarmName}`,
        `When: ${alarm.StateChangeTime}`,
        `Reason: ${alarm.NewStateReason}`,
    ];

    if (alarm.AlarmDescription) {
        lines.splice(4, 0, `What it means: ${alarm.AlarmDescription}`);
    }

    if (errors.length > 0) {
        lines.push('', 'Recent error detail:', ...errors.map((error) => `- ${error}`));
    } else if (alarm.NewStateValue === 'ALARM' && logGroup) {
        lines.push('', 'No matching error line was available yet. Open the log group below; CloudWatch log ingestion can lag briefly.');
    }

    if (logGroup) {
        lines.push('', `Logs: ${logGroupUrl(region, logGroup)}`);
    }

    lines.push(`Alarm: https://${region}.console.aws.amazon.com/cloudwatch/home?region=${region}#alarmsV2:alarm/${encodeURIComponent(alarm.AlarmName)}`);

    return lines.join('\n');
}

function logGroupUrl(region, logGroup) {
    return `https://${region}.console.aws.amazon.com/cloudwatch/home?region=${region}#logsV2:log-groups/log-group/${encodeURIComponent(logGroup)}`;
}
