# Deploying test-api to AWS EC2

A step-by-step guide for deploying this Laravel 13 API to a single EC2 instance
with an RDS PostgreSQL database.

**Written for this project specifically.** It assumes PHP 8.3, PostgreSQL, and
the `database` driver for cache/session/queue — which is what this repo is
currently configured for.

---

## Table of contents

- [Step 0 — Is EC2 the right target?](#step-0--is-ec2-the-right-target)
- [Step 1 — AWS resources you'll need](#step-1--aws-resources-youll-need)
- [Step 2 — Prepare the app (locally, first)](#step-2--prepare-the-app-locally-first)
- [Step 3 — Provision the AWS infrastructure](#step-3--provision-the-aws-infrastructure)
- [Step 4 — Set up the server](#step-4--set-up-the-server)
- [Step 5 — Deploy the code](#step-5--deploy-the-code)
- [Step 6 — Configure nginx](#step-6--configure-nginx)
- [Step 7 — Queue worker and scheduler](#step-7--queue-worker-and-scheduler)
- [Step 8 — HTTPS](#step-8--https)
- [Step 9 — Your redeploy routine](#step-9--your-redeploy-routine)
- [Troubleshooting](#troubleshooting-the-order-things-usually-break)
- [What to learn next](#what-to-learn-next-in-order)
- [Quick reference](#quick-reference)

---

## Step 0 — Is EC2 the right target?

For **learning how deployment actually works**, yes. You get a raw Linux box and
have to set up nginx, PHP-FPM, systemd, and TLS yourself. That's the knowledge
that transfers everywhere.

The alternatives, so you know what you're skipping:

| Option | Trade-off |
|---|---|
| **EC2** | You manage the OS. Most learning, most work. |
| Elastic Beanstalk | AWS manages the box; you push a zip. Less to learn. |
| ECS / Fargate | Docker containers, no servers. Learn this *after* EC2. |
| Lambda + Bref | Serverless Laravel. Niche, awkward for a first deploy. |

This guide assumes EC2.

---

## Step 1 — AWS resources you'll need

The shopping list, before you touch a console.

### Required

| Resource | Spec | Why |
|---|---|---|
| **EC2 instance** | `t3.micro` / `t3.small`, Ubuntu 24.04 LTS | Your web server |
| **Key pair** | `.pem` file | SSH access — AWS shows it once, save it |
| **Security groups** | `web-sg`, `db-sg` | The firewalls (see below) |
| **RDS PostgreSQL** | `db.t4g.micro` | This app is configured for `pgsql` |
| **Elastic IP** | — | Static IP; without it the public IP changes on reboot |

Security group rules:

- **`web-sg`** (on the EC2 instance)
  - Port 22 (SSH) — **from your IP only**
  - Port 80 (HTTP) — from anywhere (`0.0.0.0/0`)
  - Port 443 (HTTPS) — from anywhere (`0.0.0.0/0`)
- **`db-sg`** (on the RDS instance)
  - Port 5432 — **source is `web-sg`, not an IP range**

Never expose 5432 to the internet. Referencing `web-sg` as the source means
"only the web server can reach the database."

### Strongly recommended

- **IAM role for EC2** — lets the instance read S3/Secrets Manager without
  hardcoded credentials. The `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` lines
  in `.env` should stay **empty**; the role handles it.
- **A domain name** (Route 53 or any registrar) — required for HTTPS.

### Later, when you outgrow one box

- **Application Load Balancer + ACM certificate** — TLS termination, multiple instances
- **S3** — user uploads (EC2 disks die with the instance; never store uploads locally)
- **ElastiCache Redis** — replaces the `database` driver for cache/sessions/queue
- **CloudWatch** — logs and alarms

### Cost

Roughly **$15–25/month** for EC2 + RDS + Elastic IP outside of free tier. RDS is
the part that surprises people.

> **Set a billing alarm before you provision anything.**
> Billing → Budgets → create a monthly budget with an email alert.

---

## Step 2 — Prepare the app (locally, first)

Deployment fails on the app side more often than the AWS side. Fix these before
provisioning anything.

### 2a. This project has no API routes yet

Laravel 11+ ships without `routes/api.php`. Currently `bootstrap/app.php` only
registers `web` and `console`. Fix it:

```bash
php artisan install:api
```

This creates `routes/api.php`, registers it in `bootstrap/app.php`, and installs
Sanctum for token auth. Add a trivial endpoint so you have something to verify
against once deployed:

```php
// routes/api.php
Route::get('/ping', fn () => ['status' => 'ok']);
```

### 2b. The health check already exists

`bootstrap/app.php` registers `health: '/up'`. Point your uptime monitor or load
balancer health check at `/up`. Free win — no code needed.

### 2c. Know which `.env` values change in production

`.env` is gitignored. You write it by hand on the server. The values that matter:

```ini
APP_ENV=production
APP_DEBUG=false          # critical — see warning below
APP_URL=https://your-domain.com
APP_KEY=                 # generate a NEW one on the server; never reuse the local key

LOG_CHANNEL=stack
LOG_LEVEL=error          # local is `debug`, which floods the disk in production

DB_CONNECTION=pgsql
DB_HOST=your-rds-endpoint.rds.amazonaws.com
DB_PORT=5432
DB_DATABASE=test_api
DB_USERNAME=postgres
DB_PASSWORD=<strong password>
```

> **`APP_DEBUG=false` is non-negotiable.**
> With `true`, any error page publicly dumps stack traces, file paths, and the
> full contents of your environment — including database credentials. This is
> the single most damaging Laravel production mistake. The local `.env` in this
> repo has it set to `true`.

### 2d. About the `database` drivers

`SESSION_DRIVER`, `CACHE_STORE`, and `QUEUE_CONNECTION` are all set to
`database`. That's fine on a single instance — keep it for now.

Understand the trade-off: every cache read becomes a database query. This is the
first thing to move to Redis when you scale. It also means the `cache`, `jobs`,
and `sessions` tables must exist, which the migrations in this repo already
handle.

### 2e. Only if you add a load balancer later

Behind an ALB, Laravel sees plain HTTP and will generate `http://` URLs and
break HTTPS detection. Add trusted proxies to `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*');
})
```

Skip this while nginx serves traffic directly.

### 2f. Push to GitHub

You'll deploy by pulling on the server. A private repo plus a read-only deploy
key is the simple path.

---

## Step 3 — Provision the AWS infrastructure

Console-based, in this order. Order matters — creating the security groups first
avoids a chicken-and-egg problem.

1. **Create the security groups** (EC2 → Security Groups)
   Make `web-sg` and `db-sg` with the rules from Step 1.

2. **Launch the EC2 instance** (EC2 → Instances → Launch)
   - AMI: Ubuntu Server 24.04 LTS
   - Type: `t3.micro`
   - Key pair: create/select one and **download the `.pem`**
   - Security group: `web-sg`
   - Storage: 20 GB gp3

3. **Allocate an Elastic IP** (EC2 → Elastic IPs)
   Allocate, then associate it with your instance.

4. **Create the RDS instance** (RDS → Databases → Create)
   - Engine: PostgreSQL
   - Class: `db.t4g.micro`
   - **Public access: No**
   - Security group: `db-sg`
   - Same VPC as the EC2 instance
   - Set and save the master password
   - Note the **endpoint hostname** — that's your `DB_HOST`

5. **Point your domain's A record** at the Elastic IP.

Verify you can get in:

```bash
chmod 400 your-key.pem
ssh -i your-key.pem ubuntu@<elastic-ip>
```

If this hangs, it's almost always the SSH rule in `web-sg` not matching your
current IP address.

---

## Step 4 — Set up the server

Everything from here runs **on the EC2 instance**.

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.3 (Ubuntu 24.04's default) + the extensions Laravel and Postgres need
sudo apt install -y nginx git unzip \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node — only if you ever build frontend assets
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs

# Postgres client, to test the RDS connection
sudo apt install -y postgresql-client
```

**Test the RDS connection now**, before touching Laravel:

```bash
psql -h <rds-endpoint> -U postgres -d postgres
```

If this fails, no amount of Laravel debugging will help — it's the `db-sg`
inbound rule. Fix it here.

Create the database while you're connected:

```sql
CREATE DATABASE test_api;
\q
```

---

## Step 5 — Deploy the code

```bash
sudo mkdir -p /var/www && sudo chown ubuntu:ubuntu /var/www
cd /var/www
git clone <your-repo-url> test-api
cd test-api

composer install --no-dev --optimize-autoloader

cp .env.example .env
nano .env                    # fill in everything from Step 2c
php artisan key:generate

php artisan migrate --force  # --force is required in production

# Symlink public/storage -> storage/app/public so uploaded images are
# reachable over HTTP. One-time setup; without it every image URL 404s.
php artisan storage:link

# Optional: 20 sample blog posts with placeholder images, so GET /api/blogs
# returns something immediately. Safe to skip -- and skip it for real.
php artisan db:seed --force

# Permissions: nginx/PHP-FPM run as www-data and must write these two dirs
sudo chown -R ubuntu:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Cache config, routes, and views for speed
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Two things that bite everyone:

> **`--no-dev` skips Pest, Pint, Faker, and Pail.**
> That's correct for production — but anything you accidentally imported from a
> dev-only package will fatal at runtime instead of at install time.

> **After `config:cache`, Laravel ignores `.env` entirely.**
> It reads the compiled cache file instead. Every time you edit `.env` you
> *must* re-run `php artisan config:cache`. This is the #1 "why won't my change
> take effect" mystery in Laravel deployments.

---

## Step 6 — Configure nginx

The critical detail: **the web root is `public/`, not the project root.** Getting
this wrong serves your `.env` file to the internet.

Create `/etc/nginx/sites-available/test-api`:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/test-api/public;

    index index.php;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;
}
```

Enable it:

```bash
sudo ln -s /etc/nginx/sites-available/test-api /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Visit `http://your-domain.com/up` — you should get a health-check page. That's
your first real signal that the stack works end to end.

---

## Step 7 — Queue worker and scheduler

`QUEUE_CONNECTION=database` means queued jobs sit in the `jobs` table until a
worker picks them up. **Nothing runs them by default.**

### The queue worker

Create `/etc/systemd/system/laravel-worker.service`:

```ini
[Unit]
Description=Laravel queue worker
After=network.target

[Service]
User=www-data
Restart=always
RestartSec=5
ExecStart=/usr/bin/php /var/www/test-api/artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now laravel-worker
sudo systemctl status laravel-worker
```

`--max-time=3600` restarts the worker hourly, which caps any slow memory leak.
`Restart=always` brings it back automatically.

> **The worker holds your code in memory.**
> After every deploy you must run `php artisan queue:restart`, or it keeps
> executing the old version of your code. Silent and very confusing when missed.

### The scheduler

`sudo crontab -e`, then add:

```
* * * * * cd /var/www/test-api && php artisan schedule:run >> /dev/null 2>&1
```

One cron entry runs every minute; Laravel decides internally what's actually due.

---

## Step 8 — HTTPS

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

Certbot edits the nginx config, obtains the certificate, and installs a renewal
timer. Verify renewal works:

```bash
sudo certbot renew --dry-run
```

Then update `.env` and rebuild the config cache:

```bash
# APP_URL=https://your-domain.com
php artisan config:cache
```

---

## Step 9 — Your redeploy routine

Save this as `deploy.sh` in the project root so you stop doing it by hand:

```bash
#!/bin/bash
set -e
cd /var/www/test-api

php artisan down                       # maintenance mode

git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan queue:restart              # don't forget this

php artisan up
```

```bash
chmod +x deploy.sh
```

`set -e` aborts on the first failure instead of leaving you half-deployed.

---

## Troubleshooting: the order things usually break

Work down this list — it's ordered by how often each one is the actual cause.

| Symptom | Cause | Check |
|---|---|---|
| **502 Bad Gateway** | PHP-FPM down or wrong socket path | `sudo systemctl status php8.3-fpm` |
| **500 / blank page** | `storage/` not writable | `tail -f storage/logs/laravel.log` |
| **DB connection refused** | `db-sg` doesn't allow `web-sg` | `psql -h <endpoint> -U postgres` |
| **Config change ignored** | Stale config cache | `php artisan config:cache` |
| **Old code in queued jobs** | Worker still holds old code | `php artisan queue:restart` |
| **404 on every route but `/`** | nginx `try_files` wrong | Re-check the `location /` block |
| **Route not found after deploy** | Stale route cache | `php artisan route:cache` |
| **Image URLs return 404** | `public/storage` symlink missing | `php artisan storage:link` |
| **413 on image upload** | nginx body limit | `client_max_body_size` in `deploy/nginx.conf` |

Logs worth knowing:

```bash
tail -f /var/www/test-api/storage/logs/laravel.log   # application errors
sudo tail -f /var/log/nginx/error.log                # nginx / PHP-FPM errors
sudo journalctl -u laravel-worker -f                 # queue worker
```

---

## What to learn next, in order

1. **Get this working manually, end to end.** Understand each piece before automating it.
2. **Automate deploys** with GitHub Actions triggered on push to `main`.
3. **Make the instance stateless** — sessions/cache to ElastiCache Redis, uploads to S3.
4. **Add an ALB and a second instance.** Only possible once stateless. This is real HA.
5. **Then Docker and ECS,** if you want it.

Don't skip to step 5. The manual version is where the understanding lives.

---

## Quick reference

### Pre-deploy checklist

- [ ] `php artisan install:api` has been run and `routes/api.php` exists
- [ ] Billing alarm set in AWS
- [ ] `APP_DEBUG=false` in the production `.env`
- [ ] `APP_KEY` generated fresh on the server
- [ ] `LOG_LEVEL=error`
- [ ] `db-sg` allows 5432 from `web-sg` only
- [ ] SSH (22) restricted to your IP
- [ ] nginx `root` points at `public/`
- [ ] `storage/` and `bootstrap/cache/` writable by `www-data`
- [ ] `php artisan storage:link` run (blog images 404 without it)
- [ ] `deploy/php-uploads.ini` installed (2 MB default breaks image uploads)
- [ ] Queue worker enabled in systemd
- [ ] Scheduler cron entry added
- [ ] HTTPS via certbot, renewal dry-run passes

### Commands you'll run constantly

```bash
# Deploy
./deploy.sh

# After any .env change
php artisan config:cache

# After any deploy, if using queues
php artisan queue:restart

# Clear everything when confused
php artisan optimize:clear

# Service health
sudo systemctl status nginx php8.3-fpm laravel-worker
```

### Paths

| What | Where |
|---|---|
| Application | `/var/www/test-api` |
| Web root | `/var/www/test-api/public` |
| nginx site config | `/etc/nginx/sites-available/test-api` |
| PHP-FPM socket | `/var/run/php/php8.3-fpm.sock` |
| Worker unit | `/etc/systemd/system/laravel-worker.service` |
| Laravel log | `/var/www/test-api/storage/logs/laravel.log` |
