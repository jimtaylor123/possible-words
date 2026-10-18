import { describe, expect, it } from 'vitest';
import { echoOptions } from './echo';

describe('echoOptions', () => {
    it('uses a non-TLS localhost Reverb connection when Reverb is configured', () => {
        expect(echoOptions({
            VITE_REVERB_APP_KEY: 'local-key',
            VITE_REVERB_HOST: 'localhost',
            VITE_REVERB_PORT: '8080',
            VITE_REVERB_SCHEME: 'http',
        })).toEqual({
            broadcaster: 'reverb',
            key: 'local-key',
            wsHost: 'localhost',
            wsPort: 8080,
            wssPort: 8080,
            forceTLS: false,
            enabledTransports: ['ws', 'wss'],
        });
    });

    it('keeps the production Pusher cluster and TLS configuration', () => {
        expect(echoOptions({
            VITE_PUSHER_APP_KEY: 'production-key',
            VITE_PUSHER_APP_CLUSTER: 'eu',
        })).toEqual({
            broadcaster: 'pusher',
            key: 'production-key',
            cluster: 'eu',
            forceTLS: true,
        });
    });

    it('does not create Echo without a configured broadcaster', () => {
        expect(echoOptions({})).toBeNull();
    });
});
