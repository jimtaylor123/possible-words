# Real-time WebSockets

PossibleWords uses Pusher-compatible WebSockets for live updates. Production
uses **Pusher**; local development uses the free, self-hosted **Laravel Reverb**
server. This includes private in-app notification broadcasts.

## Configuration

- `config/broadcasting.php` selects `BROADCAST_CONNECTION`. Production continues
  to set it to `pusher` in `serverless.yml`; local `.env.example` sets `reverb`.
- `resources/js/bootstrap.js` chooses Reverb from `VITE_REVERB_*` values, using
  its host, port, and scheme. Without those values it preserves Pusher's
  production cluster/TLS configuration from `VITE_PUSHER_*`.
- Definition channels remain public. Notification broadcasts subscribe to the
  private `App.Models.User.{id}` channel. Echo sends an authenticated request to
  `/broadcasting/auth`; `routes/channels.php` allows only the matching user.
- `QUEUE_CONNECTION=sync` sends broadcasts in the current request. It is the
  required local setting and remains the production deployment setting.

## Local development

Copy `.env.example` (or update an existing ignored `.env`) and run:

```bash
composer install
npm install
make realtime
# equivalent: composer run dev:realtime
```

This starts Laravel at `http://localhost:8011`, Vite, and Reverb at
`ws://localhost:8080`; it does not need a queue worker. After changing an
existing `.env`, run `php artisan config:clear` and restart Vite, because Vite
embeds `VITE_*` settings into the browser bundle.

## Verify notifications end-to-end

1. Start `make realtime` and log in at `http://localhost:8011`.
2. In DevTools → Network, confirm a WebSocket to `ws://localhost:8080`.
3. Trigger an in-app notification for the logged-in user. Confirm a successful
   `POST /broadcasting/auth` and subscription to
   `private-App.Models.User.{id}`; the bell should update without reloading.
4. Click or tap the page once to permit audio, then trigger another
   notification. The bell rings for about three seconds and plays a short Web
   Audio ping.
5. In production, use the Pusher debug console to observe Pusher events.

## Troubleshooting

- **WebSocket fails locally:** ensure Reverb is running on port 8080 and that
  `BROADCAST_CONNECTION=reverb`, `REVERB_SCHEME=http`, and the matching
  `REVERB_*`/`VITE_REVERB_*` values are present. Restart Vite after changes.
- **Private notification will not subscribe:** inspect `/broadcasting/auth`.
  It must use the same authenticated session as the browser and may only
  authorize its own user-channel ID.
- **Socket connects but no event arrives:** confirm `QUEUE_CONNECTION=sync` and
  check the Laravel/Reverb logs for broadcast errors.
- **No sound:** browser autoplay policy requires one prior page interaction.
  The visual notification always remains available even if audio is blocked.
