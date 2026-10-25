# Pusher preparation

Inspected the local `C:/Users/Public/Documents/miseryindex` implementation on 25 October 2026. This is a preparation record; Hideya has no connected game server yet.

## Reference implementation

- `frontend/lib/gameRealtime.native.ts`: the native Pusher singleton from `@pusher/pusher-websocket-react-native`, TLS, one subscription per channel, listener sets, and a delayed release to survive screen transitions without reconnecting.
- `frontend/lib/gameRealtime.web.ts`: `pusher-js`, TLS, explicit bind/unbind and disconnect cleanup. Browsers use WebSockets too.
- `frontend/lib/realtimeStateRevision.ts`: apply only newer revisions, with a timestamp tie breaker. A late heartbeat cannot overwrite a newer broadcast.
- `frontend/components/GameBoard.tsx`: bootstrap once, apply complete event payloads directly, heartbeat for presence/recovery, reconnect handling. An event does not trigger another snapshot GET.
- `backend/app/Events/GameUpdated.php`: synchronous Laravel broadcast, explicit transport, channel and event name, supplied compact payload.
- `backend/app/Http/Resources/GameResource.php`: server supplies the public key/cluster, channel, event, heartbeat interval, state revision and timestamp.

Miseryindex also implements Ably, Reverb and capacity allocation. Hideya's first backend can use Pusher alone; those extra transports are not needed for the requested simple setup. No reference configuration or credentials were copied.

## Hideya integration boundary

1. HTTPS commands create/join rooms, select roles, update settings/readiness, start/end rounds, confirm tags, and send radio messages. Each response is authoritative and versioned.
2. A room bootstrap returns `room`, `viewer`, `revision`, `serverTime`, `startedAt`, `endsAt`, and public Pusher configuration. Server timestamps drive the timer.
3. Keep private room channels for roster/settings and private team/player channels for filtered map state and radio. Authorize subscriptions using the current room session. Miseryindex's public channel shape must not expose hidden runner/hunter locations in Hideya.
4. Runners receive their team only. Hunters receive their own team plus the last permitted runner snapshots. Never send all coordinates and rely on the UI to hide them.
5. `room.updated`, `team.locations`, `team.radio`, and `round.ended` carry compact versioned payloads. Keep events under the provider's event size limit. Apply payloads directly; no GET after every event.
6. Heartbeat reports presence and the last applied revision. Only bootstrap/recover when behind or reconnecting; pause unnecessary work while backgrounded. Leave/unmount releases subscriptions.
7. Location upload, background permission/service, stale-location handling, push notifications and authentication are separate native integrations. The current foreground location button only chooses an arena; it does not publish player positions.

The frontend's `useGameStore` remains the local preview implementation. Connect services at that boundary after the native UI review, and preserve explicit demo mode. No Pusher SDK or pretend connection indicator is installed in this frontend pass.
