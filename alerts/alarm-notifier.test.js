import { describe, expect, it, vi } from 'vitest';
import { createNotifier, formatAlert, handler, recentErrors, summarizeErrors } from './alarm-notifier.mjs';

describe('alarm notifier', () => {
    it('publishes a clear alert with its recent error detail', async () => {
        const recentErrorsForLogGroup = vi.fn().mockResolvedValue(['ERROR Turso stream not found']);
        const publish = vi.fn().mockResolvedValue();
        const notify = createNotifier({ recentErrorsForLogGroup, publish });

        await notify({
            Records: [{
                Sns: {
                    Message: JSON.stringify({
                        NewStateValue: 'ALARM',
                        AlarmName: 'possiblewords-prod-artisan-errors',
                        AlarmDescription: 'The scheduled Artisan runner failed.',
                        StateChangeTime: '2026-10-28T16:44:51.178Z',
                        NewStateReason: 'Lambda error',
                        Region: 'EU (Ireland)',
                        Trigger: { Dimensions: [{ name: 'FunctionName', value: 'possiblewords-prod-artisan' }] },
                    }),
                },
            }],
        });

        expect(recentErrorsForLogGroup).toHaveBeenCalledWith('/aws/lambda/possiblewords-prod-artisan', '2026-10-28T16:44:51.178Z');
        expect(publish).toHaveBeenCalledWith(expect.objectContaining({
            Subject: '[Possible Words] ALARM: The scheduled Artisan runner failed.',
            Message: expect.stringContaining('ERROR Turso stream not found'),
        }));
    });

    it('does not display undefined when an alarm has no description', () => {
        expect(formatAlert({
            NewStateValue: 'OK', AlarmName: 'example', StateChangeTime: 'now', NewStateReason: 'recovered', Region: 'eu-west-1',
        }, null, [])).not.toContain('undefined');
    });

    it('uses the Lambda region rather than the alarm display-region in console links', () => {
        const alert = formatAlert({
            NewStateValue: 'OK', AlarmName: 'example', StateChangeTime: 'now', NewStateReason: 'recovered', Region: 'EU (Ireland)',
        }, '/aws/lambda/example', []);

        expect(alert).toContain('https://eu-west-1.console.aws.amazon.com');
        expect(alert).not.toContain('EU (Ireland)');
    });

    it('compacts duplicate error lines', () => {
        expect(summarizeErrors([
            'ERROR database unavailable\ntrace',
            'ERROR database unavailable\ntrace',
            'INFO completed',
        ])).toEqual(['ERROR database unavailable']);
    });

    it('uses the current time when an alarm timestamp is malformed', async () => {
        class FilterLogEventsCommand {
            constructor(input) {
                this.input = input;
            }
        }
        const logs = { send: vi.fn().mockResolvedValue({ events: [] }) };

        await recentErrors(logs, FilterLogEventsCommand, '/aws/lambda/example', 'not-a-date');

        const [{ input }] = logs.send.mock.calls.map(([command]) => command);
        expect(input.startTime).toEqual(expect.any(Number));
        expect(Number.isNaN(input.startTime)).toBe(false);
    });

    it('handles an empty log group without throwing', async () => {
        class FilterLogEventsCommand {
            constructor(input) {
                this.input = input;
            }
        }

        await expect(recentErrors({ send: vi.fn().mockResolvedValue({}) }, FilterLogEventsCommand, '/aws/lambda/example', '2026-10-28T16:44:51.178Z'))
            .resolves.toEqual([]);
    });

    it('exercises the Lambda handler with injected AWS dependencies', async () => {
        const publish = vi.fn().mockResolvedValue();
        const loadDependencies = vi.fn().mockResolvedValue({ recentErrorsForLogGroup: vi.fn().mockResolvedValue([]), publish });

        await handler({
            Records: [{
                Sns: { Message: JSON.stringify({ NewStateValue: 'OK', AlarmName: 'example', StateChangeTime: 'now', NewStateReason: 'recovered' }) },
            }],
        }, undefined, loadDependencies);

        expect(loadDependencies).toHaveBeenCalledOnce();
        expect(publish).toHaveBeenCalledWith(expect.objectContaining({ Subject: '[Possible Words] OK: example' }));
    });

    it('logs and ignores malformed SNS messages', async () => {
        const publish = vi.fn();
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});

        await createNotifier({ recentErrorsForLogGroup: vi.fn(), publish })({ Records: [{ Sns: { Message: 'not-json' } }] });

        expect(publish).not.toHaveBeenCalled();
        expect(error).toHaveBeenCalledWith('Ignoring invalid CloudWatch alarm SNS message.');
        error.mockRestore();
    });
});
