# Hideya backend

Laravel 12 API for Hideya rooms with Pusher Channels realtime. `README.md` documents the endpoints, payloads, visibility rules and channels; keep it in sync with code changes.

- Deployed in `public_html/qla.dev/dist/hideya/endpoints`, served as `https://qla.dev/hideya/endpoints` (qla.dev's root `.htaccess` maps it). `public/index.php` accepts both URL prefixes. Redeploy: push, then open `https://qla.dev/hideya/endpoints/redeploy.php`. Redeploy runs `php artisan migrate --force`, so every pushed migration reaches the live database on the next redeploy: keep migrations additive, and never push a migration whose `up()` drops, renames or rewrites existing data without the user's explicit approval.
- The host's MySQL defaults to MyISAM with a 1000-byte key limit; `config/database.php` forces InnoDB and `AppServiceProvider` sets `Schema::defaultStringLength(191)`. Keep both.
- Realtime shares miseryindex's Pusher Channels app (cluster `us2`), as the user approved on 25 October 2026. Hideya's channels are namespaced `private-room.*`, so they cannot collide with miseryindex's `game.*`. The keys live only in `.env` files, never in git. Without keys `BROADCAST_CONNECTION=log` keeps the API working.
- Never send hidden positions and rely on the app to hide them: runners must never receive hunter positions; hunters get runners only through ping snapshots. Team data goes to private team channels authorised in `RealtimeController`.
- The referee runs on requests, not cron. Keep round logic inside the locked room transaction in `RoomController::inRoom`.

# Database safety

`.env` points at the live production database (`tagnetba_hideya` on 65.21.224.37).

- Never run `migrate:fresh`, `migrate:reset`, `migrate:refresh`, `db:wipe`, destructive rollbacks, truncation or mass deletion unless the user explicitly asks for that exact operation.
- Run tests only with `DB_CONNECTION=sqlite DB_DATABASE=:memory:`.
- Never print `.env` secrets.
