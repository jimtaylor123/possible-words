import { describe, expect, it, vi } from 'vitest';
import { createNotifier, formatAlert, recentErrors, summarizeErrors } from './alarm-notifier.mjs';

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
});
