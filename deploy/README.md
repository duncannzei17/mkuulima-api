# FarmOS on the existing Ubuntu host

Target: `134.209.226.32`, with the two GitHub checkouts at
`/var/www/mkuulima/backend` and `/var/www/mkuulima/frontend`.
This host already runs Nginx, PostgreSQL, PHP 8.4 FPM and other sites. The
native configuration in this directory uses those services and a dedicated
Redis instance on loopback port 6380. It does not change the existing sites.

## Before checkout

1. Remove `.env`, uploaded files, caches, `vendor/` and built frontend files
   from the Git indexes and clean the newly pushed Git history. Rotate every
   credential that appeared in the pushed `.env` files. Git ignore rules alone
   do not remove material from remote history.
2. Point the chosen production domain to the server. Provision a separate
   staging HTTPS origin and isolated PostgreSQL databases for the documented
   release rehearsal. Do not send production traffic before the gates pass.
3. Take a backup of the existing PostgreSQL cluster and confirm spare disk
   space. This 2 GiB host has no swap; add swap or build the frontend off host
   before running `npm ci` and Vite on it.

## Host layout

- Clone `duncannzei17/mkuulima-api` as `backend` and
  `duncannzei17/mkuulima-app` as `frontend` under `/var/www/mkuulima`.
- Keep source owned by a dedicated deploy user. Give `www-data` write access
  only to `backend/storage` and `backend/bootstrap/cache`.
- Install backend dependencies with `composer install --no-dev --prefer-dist
  --optimize-autoloader --no-interaction` as the deploy user. Build frontend
  with `npm ci && VITE_API_BASE_URL=/api npm run build` as that user.
- Copy `native.env.example` to `backend/.env` outside Git, fill all required
  values, and set owner `root:www-data`, mode `0640`. Generate a fresh
  `APP_KEY`; never reuse a key from the pushed development `.env`.
- Run `python3 deploy/provision-native.py` once as root. It generates fresh
  application, PostgreSQL and Redis secrets; creates the FarmOS database and
  role; writes `.env` as `root:www-data` mode `0640`; and starts a dedicated
  password protected Redis instance. It refuses to overwrite existing
  FarmOS secrets or database objects. Its initial mail transport is `log`;
  configure real SMTP before accepting registrations.
- Run `php artisan migrate --force`, then `php artisan tenant:migrate --all`
  as `www-data` after a verified backup. Run `php artisan storage:link`,
  `config:cache`, `route:cache`, and `view:cache`.
- Install and enable `farmos-horizon.service` and
  `farmos-scheduler.service`. Each process must report active status.
- Install `farmos-backup.service` and `farmos-backup.timer`, then run the
  service once before migrations and verify the `.dump.sha256` sidecar.
  The timer takes a daily local PostgreSQL backup as root with 14-day local
  retention. Configure an off-host copy and perform an isolated restore drill
  before production traffic.

## Web and TLS

Replace `__FARMOS_DOMAIN__` in `nginx-farmos.conf.template`, install it in
`/etc/nginx/sites-available/`, link it from `sites-enabled`, then run
`nginx -t` before reloading Nginx. The template serves the SPA publicly,
proxies `/api` through `127.0.0.1:8006`, and restricts PHP execution to
Laravel's internal front controller. The API port is not reachable remotely.
After DNS resolves to this host, use `certbot --nginx -d DOMAIN` to issue the
certificate and enable HTTPS redirection. Verify renewal with
`certbot renew --dry-run`. Only then use the real HTTPS domain in `.env`.

## Release gates

Use `resources/release-rehearsal.md` from the system repository for the
authoritative gate. A release must pass the local test/audit/build gate,
the isolated migration and checksum restore drill, and all real API
Playwright workflows against the deployed HTTPS staging origin. Then check:

```sh
curl -fsS https://DOMAIN/api/health/ready
curl -fsS https://DOMAIN/
systemctl is-active farmos-redis farmos-horizon farmos-scheduler php8.4-fpm nginx postgresql
```

Keep the prior code revision and a verified pre-migration backup through
the post-deployment health check. Database rollback requires the documented
restore procedure; code rollback alone cannot reverse a migration.
