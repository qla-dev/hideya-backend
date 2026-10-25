# Hideya API

Laravel 12 backend for Hideya's private rooms, with Pusher Channels for realtime. Production: `https://qla.dev/hideya/endpoints/api/...` (files in `public_html/qla.dev/dist/hideya/endpoints`).

## Deploy

Push to `main`, then open `https://qla.dev/hideya/endpoints/redeploy.php` (git pull, composer install, caches). It does not migrate: run `php artisan migrate` for a release that adds a migration.

## Authentication

- **Room player:** creating or joining a room returns a `token`. Send it as `X-Player-Token: <token>` on every room request and on `/api/realtime/auth`. Only its SHA-256 is stored.
- **Account (optional):** Sign in with Apple or Google returns a Sanctum token for `Authorization: Bearer <token>`. Sent with create/join, it links the player to the account (push notifications, hosting unlock). Apple tokens are checked against Apple's keys, audience `APPLE_CLIENT_IDS` (default `hideya.qla.dev`) and the hashed nonce; Google tokens against Google's keys and `GOOGLE_CLIENT_IDS`.

## Endpoints

| Method | Path | Who | Body |
| --- | --- | --- | --- |
| GET | `/api/health` | anyone | |
| POST | `/api/rooms` | anyone | `name`, `center?{latitude,longitude}`, `rules?` → `201 {token, ...state}` (you host) |
| POST | `/api/rooms/{code}/join` | anyone | `name` → `201 {token, ...state}` (lobby only, max 20) |
| GET | `/api/rooms/{code}` | player | bootstrap / recover |
| PATCH | `/api/rooms/{code}` | host, lobby | `rules?`, `center?` |
| PATCH | `/api/rooms/{code}/me` | player, lobby | `name?`, `pick? (hunter\|runner\|random)`, `ready?` |
| POST | `/api/rooms/{code}/start` | host | assigns teams, starts the clock |
| POST | `/api/rooms/{code}/location` | player | `latitude`, `longitude` (send every ~5 s; doubles as heartbeat) |
| POST | `/api/rooms/{code}/chat` | player in round | `text` (≤ 300, own team only) |
| POST | `/api/rooms/{code}/tagged` | runner | honour-system tag |
| POST | `/api/rooms/{code}/end` | host | ends the round (`cancelled`) |
| POST | `/api/rooms/{code}/leave` | player | host passes to the next player |
| POST | `/api/realtime/auth` | player | Pusher `socket_id`, `channel_name` |
| POST | `/api/auth/apple` | anyone | `identityToken`, `nonce?` (raw), `name?` → `{token, user}` |
| POST | `/api/auth/google` | anyone | `idToken` → `{token, user}` |
| GET / PATCH / DELETE | `/api/me` | account | read, `name`, delete account (App Store requirement) |
| POST | `/api/auth/logout` | account | revokes this token |
| POST / DELETE | `/api/push-tokens` | account | `token` (`ExponentPushToken[...]`), `platform?` |
| POST | `/api/revenuecat/webhook` | RevenueCat | `Authorization` = `REVENUECAT_WEBHOOK_SECRET`; grants hosting for entitlement `REVENUECAT_HOSTING_ENTITLEMENT` (default `hosting`) to app user `hideya_{userId}` |

Push notifications go through Expo's push service to signed-in players' devices: round start, hunters out, pings (per team), zone shrink warning, round over. `HOSTING_REQUIRES_PURCHASE=true` makes creating a room require the hosting unlock (off until the store products exist).

Rules (minutes unless noted): `roundLength` 10–180, `ping` 0–30 (0 = off), `headStart` 0–15, `zone` bool, `zoneSize` 200–3000 m, `shrinkEvery` 0–15. Defaults match the app: 30 / 2 / 3 / on / 420 / 5.

Every room response is the viewer's state:

```
{ room: { code, status, rules, center, startedAt, headStartEndsAt, endsAt, endedAt, outcome, revision, serverTime, players: [...] },
  you: { id, team, host },
  positions: [ { id, lat, lng, live, at, second? } ],
  chat: [ { id, kind, playerId, name, text, key, values, team, at, second } ],
  realtime: { provider, key, cluster, authEndpoint, channels, events, heartbeatSeconds } }
```

Apply a response or event only when its `revision` is newer than the one you hold; use `serverTime` and the round timestamps for clocks.

## Visibility

- Teammates see each other live.
- Hunters also see untagged runners **where they were at the last ping** (`live: false`, `second`).
- Runners never receive hunter positions, from the API or from realtime.

## Realtime (Pusher)

| Channel | Event | Payload |
| --- | --- | --- |
| `private-room.{code}` | `room.updated` | `{reason, revision, room}`: joined, rules, player, started, tagged, left, ended |
| `private-room.{code}.{team}` | `team.updated` | `{type: location, position}`, `{type: chat, message}`, hunters only: `{type: ping, second, positions}` |

`/api/realtime/auth` signs only the caller's room channel and own team channel. Events are sent immediately (no queue worker), as in miseryindex; a Pusher failure is logged and never fails the request.

There is no cron. Every room request runs the referee, which announces what has fallen due since the last request (head start over, pings with the hunters' snapshot, zone shrink warnings) and ends the round when time runs out (`runnersWin`) or every runner is tagged (`huntersWin`). Location updates every few seconds keep it on time.

## Tests

```
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```
