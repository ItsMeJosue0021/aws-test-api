# First deploy: getting test-api live

The fastest path to a working public endpoint.

**This variant:** one EC2 instance running both the app and PostgreSQL, reached
by IP over plain HTTP, in `ap-southeast-1` (Singapore).

No RDS, no domain, no TLS. Those are deliberate omissions to remove the two
biggest sources of first-deploy failure. [`DEPLOYMENT.md`](../DEPLOYMENT.md) is
the fuller production-shaped path — come back to it once this works.

> **This is a test API with no authentication.** Anyone who finds the IP can
> create and delete blogs. Rate limiting caps it at 60 req/min per IP. Fine
> while you're actively working on it; don't leave it running for weeks.

---

## Phase 0 — Before you touch anything

1. **Set a billing alarm.** Console → Billing and Cost Management → Budgets →
   Create budget → Monthly cost budget → $10 → your email.
2. **Set your region to Singapore.** Top-right region selector →
   `Asia Pacific (Singapore) ap-southeast-1`.

> AWS resources are **region-scoped**. If you create the instance in one region
> and later look in another, it appears to have vanished. Almost everyone hits
> this once. Check the region selector before every console step.

---

## Phase 1 — Launch the instance

EC2 → Instances → **Launch instances**

| Field | Value |
|---|---|
| Name | `test-api` |
| AMI | **Ubuntu Server 24.04 LTS**, 64-bit (x86) |
| Instance type | `t3.micro` |
| Key pair | Create new → `test-api-key` → RSA → **.pem** |
| Storage | 20 GiB, gp3 |

Under **Network settings → Edit**, set the inbound rules:

| Type | Source | Why |
|---|---|---|
| SSH (22) | **My IP** | Only you can log in |
| HTTP (80) | Anywhere `0.0.0.0/0` | The API itself |

Do **not** open 22 to anywhere. That's the single most common way a learning
box gets compromised.

The `.pem` downloads once and AWS never shows it again. Losing it means
rebuilding the instance.

Then click **Launch instance**.

### Give it a static IP

EC2 → **Elastic IPs** → Allocate Elastic IP address → Allocate.
Select it → Actions → **Associate** → choose your `test-api` instance.

Without this, the public IP changes every time the instance stops.

---

## Phase 2 — SSH in (from Windows)

Move the key somewhere stable, e.g. `C:\Users\<you>\.ssh\test-api-key.pem`.

Windows OpenSSH refuses keys that other users can read. In **PowerShell**:

```powershell
icacls "$env:USERPROFILE\.ssh\test-api-key.pem" /inheritance:r
icacls "$env:USERPROFILE\.ssh\test-api-key.pem" /grant:r "$($env:USERNAME):(R)"
```

Then connect (Elastic IP is on the instance's detail page):

```powershell
ssh -i "$env:USERPROFILE\.ssh\test-api-key.pem" ubuntu@<elastic-ip>
```

Type `yes` at the host-authenticity prompt.

**If it hangs:** the SSH rule doesn't match your current IP. Home connections
get new IPs regularly — edit the security group's inbound rule back to "My IP".

**`WARNING: UNPROTECTED PRIVATE KEY FILE`:** the `icacls` commands above didn't
apply. Re-run them.

Everything from here runs **on the server**.

---

## Phase 3 — Install the stack

```bash
sudo apt update && sudo apt upgrade -y

sudo apt install -y nginx git unzip postgresql postgresql-contrib \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Sanity check — all three should report `active (running)`:

```bash
systemctl is-active nginx php8.3-fpm postgresql
```

---

## Phase 4 — Create the database

Postgres runs locally, so it never touches the network. Nothing to open in the
security group.

```bash
sudo -u postgres psql
```

At the `postgres=#` prompt (use your own password):

```sql
CREATE USER testapi WITH PASSWORD 'pick-a-strong-password';
CREATE DATABASE test_api OWNER testapi;
\q
```

Creating the database with `OWNER testapi` matters: on Postgres 15+ a plain
`GRANT ALL ON DATABASE` is *not* enough to create tables, and migrations fail
with a confusing `permission denied for schema public`.

Verify the app's future credentials actually work:

```bash
psql -h 127.0.0.1 -U testapi -d test_api -c '\conninfo'
```

---

## Phase 5 — Deploy the code

```bash
sudo mkdir -p /var/www && sudo chown ubuntu:ubuntu /var/www
cd /var/www
git clone <your-github-repo-url> test-api
cd test-api

composer install --no-dev --optimize-autoloader
cp .env.production.example .env
nano .env
```

Set these (leave the rest as the template has them):

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=http://<your-elastic-ip>

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=test_api
DB_USERNAME=testapi
DB_PASSWORD=pick-a-strong-password

SESSION_SECURE_COOKIE=false
```

`SESSION_SECURE_COOKIE=false` is required here — on plain HTTP a secure cookie
is never sent, and sessions silently break.

Then:

```bash
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan db:seed --force        # 20 sample blogs, optional

sudo chown -R ubuntu:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## Phase 6 — Web server

```bash
cd /var/www/test-api

# No domain yet, so match any hostname
sed -i 's/<your-domain.com>/_/' deploy/nginx.conf

sudo cp deploy/nginx.conf /etc/nginx/sites-available/test-api
sudo ln -sf /etc/nginx/sites-available/test-api /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default

sudo cp deploy/php-uploads.ini /etc/php/8.3/fpm/conf.d/99-test-api.ini
sudo systemctl restart php8.3-fpm

sudo nginx -t && sudo systemctl reload nginx
```

`server_name _;` is nginx's catch-all — it answers on the bare IP. Swap in a
real domain later.

First check, from the server itself:

```bash
curl -i http://localhost/up
```

A `200` means nginx → PHP-FPM → Laravel → Postgres all work.

---

## Phase 7 — Queue worker and scheduler

```bash
sudo cp /var/www/test-api/deploy/laravel-worker.service \
        /etc/systemd/system/laravel-worker.service
sudo systemctl daemon-reload
sudo systemctl enable --now laravel-worker
sudo systemctl status laravel-worker
```

Scheduler — `sudo crontab -e`, then add:

```
* * * * * cd /var/www/test-api && php artisan schedule:run >> /dev/null 2>&1
```

```bash
chmod +x /var/www/test-api/deploy.sh
```

---

## Phase 8 — Prove it's live

From **your own machine**, not the server:

```powershell
curl http://<elastic-ip>/up
curl http://<elastic-ip>/api/blogs
```

Then the real test — one request that exercises routing, PHP-FPM, Postgres,
validation, file permissions, and the storage symlink at once:

```powershell
curl -X POST http://<elastic-ip>/api/blogs `
  -F "title=Live from EC2" `
  -F "description=First post from the deployed API" `
  -F "tags[]=aws" `
  -F "images[]=@some-local-image.jpg"
```

A `201` with an image URL that loads in a browser means you're done.

---

## When something breaks

Check in this order — it's ordered by likelihood.

| Symptom | Cause | Fix |
|---|---|---|
| SSH hangs | Your IP changed | Update the SG inbound rule |
| Connection refused on :80 | HTTP not open in the SG | Add port 80 from `0.0.0.0/0` |
| **502** Bad Gateway | PHP-FPM down / wrong socket | `systemctl status php8.3-fpm` |
| **500** / blank | `storage/` not writable | `tail storage/logs/laravel.log` |
| `permission denied for schema public` | DB not owned by `testapi` | Recreate with `OWNER testapi` |
| Config edit ignored | Stale config cache | `php artisan config:cache` |
| Image URLs 404 | Symlink missing | `php artisan storage:link` |
| 413 on upload | nginx/PHP limits | `deploy/php-uploads.ini` installed? |

```bash
tail -f /var/www/test-api/storage/logs/laravel.log
sudo tail -f /var/log/nginx/test-api.error.log
sudo journalctl -u laravel-worker -f
```

---

## Once it works

1. **Stop the instance when you're not using it.** Compute stops billing; the
   Elastic IP keeps the address. Start it again when you need it.
2. Add Sanctum auth to the blog routes.
3. Get a domain, then certbot for HTTPS (Step 8 of `DEPLOYMENT.md`).
4. Move Postgres to RDS — a good exercise precisely because the app is already
   running and you'll see exactly what changes.
