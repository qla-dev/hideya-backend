# Hideya API

Laravel 12 backend for Hideya's private rooms, with Pusher Channels for realtime. Production: `https://qla.dev/hideya/endpoints/api/...` (files in `public_html/qla.dev/dist/hideya/endpoints`).

## Deploy

Push to `main`, then open `https://qla.dev/hideya/endpoints/redeploy.php` (git pull, composer install, `migrate --force`, caches). New migrations run against the live database on that redeploy, so keep them additive.

## Authentication

- **Room player:** creating or joining a room returns a `token`. Send it as `X-Player-Token: <token>` on every room request and on `/api/realtime/auth`. Only its SHA-256 is stored.
- **Account (optional):** Sign in with Apple or Google returns a Sanctum token for `Authorization: Bearer <token>`. Sent with create/join, it links the player to the account (push notifications). Hosting and playing never need an account or a purchase. Apple tokens are checked against Apple's keys, audience `APPLE_CLIENT_IDS` (default `hideya.qla.dev`) and the hashed nonce; Google tokens against Google's keys and `GOOGLE_CLIENT_IDS`.

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
| GET | `/api/me/stats` | account | `{summary, recent}`: lifetime totals and the latest 12 results belonging to this account |
| POST | `/api/me/demo-results` | account | `id`, `date` (ISO), `team`, `outcome`, `seconds`, `metres`; idempotent per account/demo ID; `{saved: true}` |
| POST | `/api/auth/logout` | account | revokes this token |
| POST / DELETE | `/api/push-tokens` | account | `token` (`ExponentPushToken[...]`), `platform?` |
| POST | `/api/purchases` | Bearer | `{ productId }` after a store purchase. The server asks RevenueCat (`REVENUECAT_SECRET_API_KEY`, app user `hideya-user-{userId}`) what was bought and grants each pack transaction once; Plus follows the `plus` entitlement. Returns `granted` and `user` |

Push notifications go through Expo's push service to signed-in players' devices: round start, hunters out, pings (per team), zone shrink warning, round over.

## Account stats

Apply migration `2026_10_26_000003_create_round_results` when deploying this change (`php artisan migrate --force`). It adds `round_results` and a distance counter on `players`. The redeploy endpoint does not run migrations.

Online results are saved by the referee inside the room transaction for every account-linked participant when the round ends, even if their app is closed. Signing in on a room request links an unclaimed player; existing ownership is never transferred. Distance is accumulated from accepted location updates during play (steps over 6 m and under 150 m), excluding lobby, tagged/left and post-round movement. Time stops at tag, departure or the scheduled end, whichever is first, and excludes the head start. Leaving/cancellation is not a played round or a win. On the first stats read, existing finished account-linked rooms are recovered idempotently; old distances are zero because they were not recorded.

`summary` has integer fields `total`, `played`, `won`, `neverCaught`, `longest`, `farthest`, `runnerPlayed`, `runnerWon`, `hunterPlayed`, `hunterWon`. It aggregates all saved results, with no 50-round limit. `recent` has up to 12 `{id, date, source, team, outcome, seconds, metres, won}` records; `source` distinguishes `demo` from `online`. Demo results are self-reported and cannot overwrite server-recorded online results; wins are calculated by the API. Results are private to the authenticated account and deleted with it, but survive removal of old rooms.

The app uses database totals for signed-in profiles and an account-scoped local cache while offline. Signed-in demo results use a persistent retry queue belonging to the account that started the demo (or signed in during a guest demo). Guest history remains local and is not automatically claimed by the next account signing in. Online stats do not depend on uploading local history. Guests retain the latest 50 local rounds. Demo results are included in account totals and labelled in recent history.

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
