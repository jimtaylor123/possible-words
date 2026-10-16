import { afterEach, describe, expect, it, vi } from 'vitest';

const originalAudioContext = window.AudioContext;

afterEach(() => {
    window.AudioContext = originalAudioContext;
    vi.resetModules();
});

describe('notification ping', () => {
    it('resumes suspended audio before playing the ping', async () => {
        const context = {
            state: 'suspended',
            resume: vi.fn().mockImplementation(async () => {
                context.state = 'running';
            }),
            createOscillator: vi.fn(() => ({
                frequency: {
                    setValueAtTime: vi.fn(),
                    exponentialRampToValueAtTime: vi.fn(),
                },
                connect: vi.fn().mockReturnThis(),
                start: vi.fn(),
                stop: vi.fn(),
            })),
            createGain: vi.fn(() => ({
                gain: {
                    setValueAtTime: vi.fn(),
                    exponentialRampToValueAtTime: vi.fn(),
                },
                connect: vi.fn().mockReturnThis(),
            })),
            currentTime: 0,
            destination: {},
        };
        window.AudioContext = vi.fn(function AudioContext() { return context; });
        const { playNotificationPing } = await import('./notificationPing');

        playNotificationPing();
        await Promise.resolve();

        expect(context.resume).toHaveBeenCalledOnce();
        expect(context.createOscillator).toHaveBeenCalledOnce();
    });
});
