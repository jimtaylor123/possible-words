# Real-time (WebSockets) — how it works, how to verify, how to debug

PossibleWords uses **Pusher** for real-time updates: when one user adds a definition or likes one, other users on the same word page see it appear/update without reloading.

## Architecture

```
Browser (Echo + pusher-js)
   │  wss://ws-<cluster>.pusher.com   (public channels)
   ▼
Pusher Channels
   ▲
   │  REST api-<cluster>.pusher.com  (triggered by queued broadcast job)
   │
Lambda web function (Laravel)
   │  WordController → broadcast(new DefinitionCreated/Voted)->toOthers()
   ▼
QUEUE_CONNECTION=sync (production) → runs inline in the same request
```

Key config:

- `config/broadcasting.php` → default driver `env('BROADCAST_CONNECTION', 'null')`; prod sets `BROADCAST_CONNECTION=pusher` in `serverless.yml`.
- `resources/js/bootstrap.js` → Echo configured with `broadcaster: 'pusher'`, `key: VITE_PUSHER_APP_KEY`, `cluster: VITE_PUSHER_APP_CLUSTER`, `forceTLS: true`. These `VITE_*` values are baked into the frontend at build time.
- `app/Events/DefinitionCreated.php` → public channel `word.{id}.definitions`, event `definition.created`.
- `app/Events/DefinitionVoted.php` → public channel `definition.{id}`, event `definition.voted`.
- `app/Http/Controllers/WordController.php` → `storeDefinition()` and `voteDefinition()` call `broadcast(...)->toOthers()`.
- `routes/channels.php` → the channels are **public** (`Broadcast::channel` returning `true`); no private-channel auth (`broadcasting/auth`) is involved.

Note: `broadcast()` enqueues a `BroadcastEvent` job. Locally `QUEUE_CONNECTION=database` needs a worker (`make dev` runs `queue:listen`). In production `QUEUE_CONNECTION=sync` runs the broadcast inline, so no worker is required on Lambda.

## How to verify end-to-end

1. Two browsers open the same word page (e.g. `https://possiblewords.jimtaylor.space/w/<slug>`), both logged in as different users.
2. In browser A, like a definition (or add a definition).
3. Browser B should show the vote count / new definition update immediately, with no reload.
4. Optional: in the Pusher debug console (`https://dashboard.pusher.com/apps/<app_id>/console`), you should see the `definition.voted` / `definition.created` events land.

Quick API check (server → Pusher connectivity and creds):

```bash
php -r '
require "vendor/autoload.php";
$e = parse_ini_file(".env");
$p = new Pusher\Pusher($e["PUSHER_APP_KEY"], $e["PUSHER_APP_SECRET"], $e["PUSHER_APP_ID"], ["cluster" => $e["PUSHER_APP_CLUSTER"]]);
print_r($p->get_channels());
'
```

If it prints channels (including live `word.*`/`definition.*` channels), the backend can reach Pusher and the credentials are valid.

Browser-side check (client → Pusher connectivity):

```js
// node with pusher-js installed
const { Pusher } = require('pusher-js');
const p = new Pusher('APP_KEY', { cluster: 'eu', forceTLS: true });
p.connection.bind('connected', () => console.log('connected', p.connection.socket_id));
p.subscribe('test-channel').bind('test-event', d => console.log('received', d));
```

## How to debug

- **"WebSocket connection failed" / no live updates locally**: check `.env` has `BROADCAST_CONNECTION=pusher`, valid `PUSHER_*` values, and `VITE_PUSHER_APP_KEY`/`VITE_PUSHER_APP_CLUSTER` (then re-run `npm run build` — Vite vars are compile-time).
- **Events not arriving but WS connects**: the queue worker must be running locally (`make dev`) for the `BroadcastEvent` job to fire. On Lambda, confirm `QUEUE_CONNECTION=sync`.
- **Only some clients get updates**: broadcasts use `->toOthers()` — the acting user is deliberately excluded (their own UI updates via the redirect response).
- **Check Lambda env**: `aws lambda get-function-configuration --function-name possiblewords-prod-web --query Environment.Variables` and compare `PUSHER_APP_KEY`/`PUSHER_APP_SECRET`/`PUSHER_APP_CLUSTER` with the Pusher dashboard / local `.env`.
- **Pusher error codes**: `4004` channel/event name invalid, `4010` bad signature (wrong secret), `4001` bad app key. The event names (`definition.voted`) and channel names (`definition.{id}`, `word.{id}.definitions`) follow Pusher's charset rules.
