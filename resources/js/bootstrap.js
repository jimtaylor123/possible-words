import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { echoOptions } from './realtime/echo';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.Pusher = Pusher;

const options = echoOptions(import.meta.env);

if (options) {
    try {
        window.Echo = new Echo(options);
        window.Echo.connector.pusher.connection.bind('connected', () => { window.axios.defaults.headers.common['X-Socket-ID'] = window.Echo.socketId(); });
    } catch { window.Echo = null; }
}
