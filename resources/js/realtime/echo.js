export const echoOptions = (env) => {
    if (env.VITE_REVERB_APP_KEY) {
        const scheme = env.VITE_REVERB_SCHEME ?? 'https';

        return {
            broadcaster: 'reverb',
            key: env.VITE_REVERB_APP_KEY,
            wsHost: env.VITE_REVERB_HOST ?? window.location.hostname,
            wsPort: Number(env.VITE_REVERB_PORT ?? 80),
            wssPort: Number(env.VITE_REVERB_PORT ?? 443),
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
        };
    }

    if (env.VITE_PUSHER_APP_KEY) {
        return {
            broadcaster: 'pusher',
            key: env.VITE_PUSHER_APP_KEY,
            cluster: env.VITE_PUSHER_APP_CLUSTER,
            forceTLS: true,
        };
    }

    return null;
};
