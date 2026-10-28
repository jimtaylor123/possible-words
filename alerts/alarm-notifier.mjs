import { CloudWatchLogsClient, FilterLogEventsCommand } from '@aws-sdk/client-cloudwatch-logs';
import { PublishCommand, SNSClient } from '@aws-sdk/client-sns';

const logs = new CloudWatchLogsClient({});
const sns = new SNSClient({});

export async function handler(event) {
    await Promise.all(event.Records.map(async ({ Sns: { Message } }) => {
        const alarm = JSON.parse(Message);
        const state = alarm.NewStateValue;
        const functionName = alarm.Trigger?.Dimensions?.find(({ name }) => name === 'FunctionName')?.value;
        const logGroup = functionName ? `/aws/lambda/${functionName}` : null;
        const errors = state === 'ALARM' && logGroup ? await recentErrors(logGroup, alarm.StateChangeTime) : [];
        const subject = `[Possible Words] ${state}: ${alarm.AlarmDescription ?? alarm.AlarmName}`;

        await sns.send(new PublishCommand({
            TopicArn: process.env.ALERT_EMAIL_TOPIC_ARN,
            Subject: subject.slice(0, 100),
            Message: formatAlert(alarm, logGroup, errors),
        }));
    }));
}

async function recentErrors(logGroupName, stateChangeTime) {
    const changedAt = Date.parse(stateChangeTime);
    const response = await logs.send(new FilterLogEventsCommand({
        logGroupName,
        startTime: changedAt - 10 * 60 * 1000,
        endTime: changedAt + 60 * 1000,
        limit: 1000,
    }));

    return (response.events ?? [])
        .map(({ message }) => message?.trim())
        .filter((message) => message && /\b(?:ERROR|Exception|Invoke Error|Task timed out|Fatal error)\b/i.test(message))
        .map(compactError)
        .filter((message, index, messages) => messages.indexOf(message) === index)
        .slice(0, 3);
}

function compactError(message) {
    const relevantLine = message.split('\n').find((line) => /\b(?:ERROR|Exception|Invoke Error|Task timed out|Fatal error)\b/i.test(line));

    return (relevantLine ?? message).replace(/\s+/g, ' ').slice(0, 700);
}

function formatAlert(alarm, logGroup, errors) {
    const region = alarm.Region ?? process.env.AWS_REGION;
    const lines = [
        'Possible Words production alert',
        '',
        `Status: ${alarm.NewStateValue}`,
        `Alarm: ${alarm.AlarmName}`,
        `What it means: ${alarm.AlarmDescription}`,
        `When: ${alarm.StateChangeTime}`,
        `Reason: ${alarm.NewStateReason}`,
    ];

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
