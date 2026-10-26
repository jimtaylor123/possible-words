import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { echoOptions } from './realtime/echo';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.Pusher = Pusher;

const options = echoOptions(import.meta.env);

if (import.meta.env.VITE_E2E_MOCK_ECHO === 'true') {
    const listeners = new Map();
    const channel = (name) => ({
        name,
        listen: (event, callback) => {
            listeners.set(`${name}:${event}`, callback);
            return channel(name);
        },
        notification: (callback) => {
            listeners.set(`${name}:notification`, callback);
            return channel(name);
        },
    });
    window.__e2eEchoListeners = listeners;
    window.Echo = { channel, private: channel, leave: () => {}, leaveChannel: () => {} };
} else if (options) {
    try {
        window.Echo = new Echo(options);
        window.Echo.connector.pusher.connection.bind('connected', () => { window.axios.defaults.headers.common['X-Socket-ID'] = window.Echo.socketId(); });
    } catch { window.Echo = null; }
}
