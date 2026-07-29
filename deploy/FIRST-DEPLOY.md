# First deploy: getting test-api live

The fastest path to a working public endpoint, phase by phase.

**This variant:** one EC2 instance running both the app and PostgreSQL, reached
by IP over plain HTTP, in `ap-southeast-1` (Singapore).

No RDS, no domain, no TLS — deliberate omissions that remove the two biggest
sources of first-deploy failure. [`DEPLOYMENT.md`](../DEPLOYMENT.md) is the
fuller production-shaped path with RDS and HTTPS.

**Verified against:** Ubuntu 26.04 LTS, PHP 8.5, PostgreSQL 18, Laravel 13.

> **This API has no authentication.** Anyone who finds the IP can create and
> delete blogs. Rate limiting caps it at 60 req/min per IP. Fine while you're
> actively working on it; don't leave it running for weeks.

---

## Contents

- [Phase 0 — Account setup](#phase-0--account-setup)
- [Navigating the console](#navigating-the-console)
- [Phase 1 — Launch the instance](#phase-1--launch-the-instance)
- [Phase 2 — SSH in](#phase-2--ssh-in-from-windows)
- [Phase 3 — Install the stack](#phase-3--install-the-stack)
- [Phase 4 — Create the database](#phase-4--create-the-database)
- [Phase 5 — Deploy the code](#phase-5--deploy-the-code)
- [Phase 6 — Web server](#phase-6--web-server)
- [Phase 7 — Worker and scheduler](#phase-7--queue-worker-and-scheduler)
- [Phase 8 — Prove it's live](#phase-8--prove-its-live)
- [Deploying changes from now on](#deploying-changes-from-now-on)
- [Troubleshooting](#when-something-breaks)
- [What this costs](#what-this-costs)

---

## Phase 0 — Account setup

Do this once. Steps 1-3 must happen as **root**; everything after uses the IAM
user.

### 1. Sign in as root, then lock it down

Sign in at https://console.aws.amazon.com with your account **email address**
(the *Root user* tab).

Immediately enable MFA:
https://us-east-1.console.aws.amazon.com/iam/home#/security_credentials
→ Multi-factor authentication (MFA) → Assign MFA device → Authenticator app.

Root can do anything and **cannot be restricted** — including closing the
account. Leaked AWS credentials are routinely used to run up five-figure
compute bills. An IAM user can be revoked in one click; root cannot.

### 2. Billing: activate IAM access, then set a budget

IAM users **cannot see billing by default**, so do this while still root.

- https://us-east-1.console.aws.amazon.com/billing/home#/account
  → *IAM user and role access to Billing information* → Edit → **Activate**
- https://us-east-1.console.aws.amazon.com/costmanagement/home#/budgets
  → Create budget → Use a template → Monthly cost budget → $10 → your email

### 3. Create an IAM admin user

https://us-east-1.console.aws.amazon.com/iam/home#/users → **Create user**

| Field | Value |
|---|---|
| User name | e.g. `joshua-admin` |
| Console access | tick *Provide user access to the AWS Management Console* |
| User type | *I want to create an IAM user* |
| Permissions | *Attach policies directly* → **AdministratorAccess** |

Copy the **console sign-in URL** from the final screen
(`https://<account-id>.signin.aws.amazon.com/console`).

Optional: IAM dashboard → *Account Alias* → Create → makes that URL readable,
e.g. `https://joshua-test.signin.aws.amazon.com/console`.

Sign out of root. **Sign back in as the IAM user for everything below.**

> Root and IAM are different *tabs* on the same sign-in page. Root wants an
> email address; IAM wants an account ID/alias plus user name. Being on the
> wrong tab is a common source of "my password isn't working".

### 4. Set your region to Singapore

Top-right region selector → `Asia Pacific (Singapore) ap-southeast-1`.

> AWS resources are **region-scoped**. Create an instance in one region, then
> look at the console set to another, and it appears to have vanished. Check
> the region selector before every console step.

---

## Navigating the console

Two ways to get anywhere:

- **Search bar at the top of every page.** Type `EC2`, `Budgets`, `Elastic IP`.
- **Direct links** below. These pin `ap-southeast-1`, so they can't land you in
  the wrong region.

| Where | Link |
|---|---|
| Budgets (billing is global, lives in us-east-1) | https://us-east-1.console.aws.amazon.com/costmanagement/home#/budgets |
| Launch an instance | https://ap-southeast-1.console.aws.amazon.com/ec2/home?region=ap-southeast-1#LaunchInstances: |
| Your instances | https://ap-southeast-1.console.aws.amazon.com/ec2/home?region=ap-southeast-1#Instances: |
| Elastic IPs | https://ap-southeast-1.console.aws.amazon.com/ec2/home?region=ap-southeast-1#Addresses: |
| Security groups | https://ap-southeast-1.console.aws.amazon.com/ec2/home?region=ap-southeast-1#SecurityGroups: |

Inside EC2, the **left sidebar** is the map:

```
Instances
  └─ Instances          ← your servers, start/stop, find the public IP
Network & Security
  ├─ Security Groups    ← firewall rules
  ├─ Elastic IPs        ← static IP addresses
  └─ Key Pairs          ← the .pem you SSH with
```

> AWS moves labels around. If something doesn't match what you see, the
> *sequence* is still right — look for the nearest equivalent wording.

---

## Phase 1 — Launch the instance

EC2 → Instances → **Launch instances**

The wizard is one long form. Panels, top to bottom:

1. **Name and tags** — `test-api`
2. **Application and OS Images** — Quick Start → **Ubuntu** tile. Note which
   LTS the dropdown offers; it changes over time and determines your PHP
   version (see Phase 3).
3. **Instance type** — `t3.micro`
4. **Key pair (login)** — **Create new key pair** → `test-api-key`, RSA,
   **.pem** → Create. Downloads immediately.
5. **Network settings** — **Edit**, then set inbound rules (below)
6. **Configure storage** — 20 GiB, gp3
7. **Summary** panel → **Launch instance**

Then **View all instances**. Wait for *Instance state* `Running` **and**
*Status checks* `2/2 passed` — the second lags the first by a couple of
minutes, and SSH refuses connections until it passes.

The `.pem` downloads once and AWS never shows it again.

### Inbound rules

Exactly two:

| Type | Source | Why |
|---|---|---|
| SSH (22) | **My IP** | Only you can log in |
| HTTP (80) | Anywhere `0.0.0.0/0` | The API itself |

Never open 22 to anywhere — that's the most common way a learning box gets
compromised.

> **The CGNAT trap.** Many ISPs (especially mobile and Philippine residential)
> route your traffic through a *pool* of addresses, so the `/32` that "My IP"
> captured isn't the one you connect from next time. Symptom: SSH times out
> for no apparent reason. Check with `curl.exe https://api.ipify.org` — if it
> differs from the rule, set the SSH source to **Custom** and enter the
> enclosing `/24`, e.g. `160.30.69.0/24`. Wider than ideal, still far narrower
> than `0.0.0.0/0`, and the Ubuntu AMI disables password auth so a key is
> still required.

### Give it a static IP

Left sidebar → **Network & Security → Elastic IPs**

1. **Allocate Elastic IP address** → **Allocate**
2. Tick the new address → **Actions** → **Associate Elastic IP address**
3. Resource type **Instance** → choose `test-api` → **Associate**

Without this the public IP changes every time the instance stops.

> AWS charges for **every** public IPv4 address since Feb 2024 — ~$0.005/hour
> (~$3.65/month), attached or idle. The 12-month free tier covers 750
> hours/month. Stopping the instance does **not** stop this charge; release the
> address when you're done with the project.

---

## Phase 2 — SSH in (from Windows)

Move the key to `C:\Users\<you>\.ssh\test-api-key.pem`, then in **PowerShell**:

```powershell
icacls "$env:USERPROFILE\.ssh\test-api-key.pem" /inheritance:r
icacls "$env:USERPROFILE\.ssh\test-api-key.pem" /grant:r "$($env:USERNAME):(R)"

ssh -i "$env:USERPROFILE\.ssh\test-api-key.pem" ubuntu@<elastic-ip>
```

Type `yes` at the host-authenticity prompt.

### Diagnosing a failed connection

The error text tells you which layer is broken:

| Error | Meaning |
|---|---|
| **Connection timed out** | Packets dropped — security group, or your IP changed |
| **Connection refused** | Reached the host, nothing listening — instance still booting |
| **UNPROTECTED PRIVATE KEY FILE** | The `icacls` commands didn't apply |
| **Permission denied (publickey)** | Wrong key, or wrong user (must be `ubuntu`) |

To prove whether packets reach the instance at all, test a port you *know* is
open in the security group:

```powershell
Test-NetConnection <elastic-ip> -Port 80
```

If port 80 says *refused* but 22 says *timed out*, the instance is fine and
your SSH rule is the problem. That's the CGNAT trap above.

Everything from here runs **on the server**.

---

## Phase 3 — Install the stack

```bash
sudo apt update && sudo apt upgrade -y
```

> **`apt update` first, always.** A fresh instance has an empty package index,
> so `apt-cache search` returns *nothing at all* and `apt install` fails with
> `Unable to locate package` — which looks like the package doesn't exist.

### Confirm your PHP version

Ubuntu's default PHP tracks the release: 24.04 → 8.3, 26.04 → 8.5. Check
rather than assume:

```bash
apt-cache search --names-only '^php[0-9]+\.[0-9]+-fpm$'
```

Substitute that version everywhere `8.5` appears below **and** in
`deploy/nginx.conf` (the FPM socket path) and `deploy/php-uploads.ini` (the
conf.d directory). `composer.json` requires `^8.3`, so 8.3/8.4/8.5 all work.

```bash
sudo apt install -y nginx git unzip postgresql postgresql-contrib \
  php8.5-fpm php8.5-cli php8.5-pgsql php8.5-mbstring \
  php8.5-xml php8.5-curl php8.5-zip php8.5-bcmath php8.5-intl php8.5-gd

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Verify — expect PHP 8.5.x and three `active` lines:

```bash
php -v
systemctl is-active nginx php8.5-fpm postgresql
```

---

## Phase 4 — Create the database

Postgres listens on localhost only, so nothing needs opening in the security
group.

Generate a password first — alphanumeric only, because `'`, `"`, `=`, `/` and
`$` break either the SQL statement or the `.env` parser:

```bash
tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32; echo
```

Copy it, then:

```bash
sudo -u postgres psql
```

At the `postgres=#` prompt:

```sql
CREATE USER testapi WITH PASSWORD 'your-generated-password';
CREATE DATABASE test_api OWNER testapi;
\q
```

> **`OWNER testapi` is not optional.** On Postgres 15+ a plain
> `GRANT ALL ON DATABASE` does *not* confer table-creation rights, and
> migrations fail with `permission denied for schema public` — which reads
> like an application bug and isn't.

Verify the app's credentials before going further:

```bash
psql -h 127.0.0.1 -U testapi -d test_api -c '\conninfo'
```

Success looks like `You are connected to database "test_api" as user
"testapi"`.

---

## Phase 5 — Deploy the code

```bash
sudo mkdir -p /var/www && sudo chown ubuntu:ubuntu /var/www
cd /var/www
git clone https://github.com/<you>/<repo>.git test-api
cd test-api
```

> **The trailing `test-api` matters.** If your repo has a different name, this
> renames the directory to match the paths baked into `nginx.conf`,
> `deploy.sh`, and `laravel-worker.service`. Skip it and you get a 404 that
> takes a while to trace.

> **If git asks for a username and password**, the repo is private — GitHub
> stopped accepting passwords over HTTPS in 2021. Either make the repo public,
> or add a deploy key: `ssh-keygen -t ed25519 -C "ec2-deploy" -N "" -f
> ~/.ssh/deploy_key`, then paste `~/.ssh/deploy_key.pub` into the repo's
> Settings → Deploy keys, and clone the `git@github.com:` URL instead.

```bash
composer install --no-dev --optimize-autoloader
```

This is the first real exercise of your PHP version. If a dependency can't run
on it, this is where you find out.

### Environment

```bash
cp .env.production.example .env
nano .env
```

Change these; the rest of the template is already correct:

```ini
APP_URL=http://<your-elastic-ip>

DB_HOST=127.0.0.1
DB_DATABASE=test_api
DB_USERNAME=testapi
DB_PASSWORD=<the password from Phase 4>
```

Save with `Ctrl+O`, `Enter`, `Ctrl+X`.

Confirm `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=false` are set. The first
prevents error pages dumping this file's contents publicly; the second is
required on plain HTTP, where a secure cookie is never sent and sessions break
silently.

### Build

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

`migrate --force` is the moment of truth for your credentials — a
`password authentication failed` here means `.env` and Phase 4 disagree.

Verify before touching nginx:

```bash
php artisan route:list --path=api
php artisan tinker --execute="echo App\Models\Blog::count() . ' blogs';"
```

Five `api/blogs` routes and a blog count proves PHP, Composer, Laravel and
Postgres all work together — everything except the web server.

---

## Phase 6 — Web server

Check the config matches your PHP version:

```bash
cd /var/www/test-api
grep fastcgi_pass deploy/nginx.conf     # must match your php version
```

```bash
# `_` is nginx's catch-all server_name -- it answers on the bare IP.
# Piping rather than `sed -i` leaves the repo file untouched; an edited
# working tree makes deploy.sh's `git pull` abort.
sed 's/<your-domain.com>/_/' deploy/nginx.conf \
  | sudo tee /etc/nginx/sites-available/test-api > /dev/null

sudo ln -sf /etc/nginx/sites-available/test-api /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default

sudo cp deploy/php-uploads.ini /etc/php/8.5/fpm/conf.d/99-test-api.ini
sudo systemctl restart php8.5-fpm

sudo nginx -t && sudo systemctl reload nginx
```

`nginx -t` must print `syntax is ok` / `test is successful`. **If it fails,
don't reload** — you'd drop the running config.

```bash
curl -i http://localhost/up
curl -s http://localhost/api/blogs | head -c 400
```

A `200` and JSON means the whole stack works.

---

## Phase 7 — Queue worker and scheduler

```bash
sudo cp /var/www/test-api/deploy/laravel-worker.service \
        /etc/systemd/system/laravel-worker.service
sudo systemctl daemon-reload
sudo systemctl enable --now laravel-worker
sudo systemctl status laravel-worker --no-pager
```

Expect `active (running)`.

Scheduler — `sudo crontab -e`, then one line:

```
* * * * * cd /var/www/test-api && php artisan schedule:run >> /dev/null 2>&1
```

It runs every minute; Laravel decides internally what's actually due.

---

## Phase 8 — Prove it's live

From **your own machine**, not the server:

```powershell
curl.exe http://<elastic-ip>/up
curl.exe http://<elastic-ip>/api/blogs
```

> **Use `curl.exe`, not `curl`.** In PowerShell, `curl` is an alias for
> `Invoke-WebRequest`, which takes entirely different arguments and throws
> confusing errors on `-X` and `-F`.

The full end-to-end test — put any `.jpg` in your current folder first:

```powershell
curl.exe -X POST http://<elastic-ip>/api/blogs `
  -F "title=Live from EC2" `
  -F "description=First post from the deployed API" `
  -F "tags[]=aws" `
  -F "images[]=@yourimage.jpg"
```

A **201** with an image URL that loads in a browser means you're done. That one
request exercised routing, PHP-FPM, Postgres, validation, file permissions and
the storage symlink together.

---

## Deploying changes from now on

```powershell
# your machine
git push
```

```bash
# the server
cd /var/www/test-api && ./deploy.sh
```

`deploy.sh` handles maintenance mode, pull, composer, migrations, cache
rebuilds and `queue:restart`, and puts the app back up if any step fails.

System config (nginx, php-uploads.ini, the systemd unit) is **not** copied by
`deploy.sh` — that would need sudo on every deploy. After changing those,
re-copy them and reload the relevant service manually.

---

## Stopping and starting the instance

The main cost lever. Stop it when you finish for the day.

**Console → Instances → tick `test-api` → Instance state → Stop instance.**

What survives: the EBS volume (code, database, uploaded images) and the Elastic
IP association, so you get the same address back. Billing drops to ~$5.50/month
— storage plus the IP. Compute, the expensive part, stops immediately.

Start it again from the same dropdown. Nothing needs reconfiguring: nginx,
PHP-FPM, PostgreSQL and the queue worker were all `systemctl enable`d, so they
come back automatically at boot. Wait for `2/2 passed` and the API is live.

> **Stop is not Terminate.** They sit two items apart in the same menu.
> Terminate deletes the root volume by default — your database, `.env` and
> uploads are unrecoverable, and you would redo all eight phases. There is no
> undo. Read the confirmation dialog.

If SSH times out after a restart, your ISP likely reassigned your address while
the instance was off. Same fix as Phase 1: compare `curl.exe
https://api.ipify.org` against the security group rule.

---

## When something breaks

Ordered by how often each is the actual cause.

| Symptom | Cause | Fix |
|---|---|---|
| SSH times out | Your IP changed / CGNAT pool | Update the SG rule to a `/24` |
| SSH refused | Instance still booting | Wait for `2/2 passed` |
| `Unable to locate package` | Stale package index | `sudo apt update` |
| **502** Bad Gateway | PHP-FPM down / socket version mismatch | `systemctl status php8.5-fpm`; check `fastcgi_pass` |
| **500** / blank | `storage/` not writable | `tail storage/logs/laravel.log` |
| `permission denied for schema public` | DB not owned by `testapi` | Recreate with `OWNER testapi` |
| `password authentication failed` | `.env` ≠ Phase 4 password | Fix `.env`, `php artisan config:cache` |
| Config edit ignored | Stale config cache | `php artisan config:cache` |
| Image URLs 404 | Symlink missing | `php artisan storage:link` |
| 413 on upload | nginx/PHP limits | Is `php-uploads.ini` installed? |
| Old code in queued jobs | Worker holds old code | `php artisan queue:restart` |

```bash
tail -f /var/www/test-api/storage/logs/laravel.log
sudo tail -f /var/log/nginx/test-api.error.log
sudo journalctl -u laravel-worker -f
```

---

## What this costs

Approximate, `ap-southeast-1`, running 24/7. **Verify against
https://calculator.aws and your own Billing dashboard** — AWS changes pricing
and free-tier structure regularly.

| Item | ~Monthly |
|---|---|
| `t3.micro` (744 hrs) | $9.60 |
| 20 GB gp3 storage | $1.90 |
| Public IPv4 address | $3.65 |
| Data transfer out | $0 (first 100 GB free) |
| **Total** | **≈ $15** |

Free-tier eligible accounts get 750 hrs/month of `t3.micro`, 30 GB EBS and 750
hrs/month of public IPv4 for the first 12 months — enough to run this
continuously at no cost.

Levers, biggest first:

- **Stop the instance when not working on it.** Compute billing stops at once;
  you keep paying storage + IP (~$5.50/month). Restart takes ~30 seconds.
- **Release the Elastic IP** when the project is done, or it bills forever.
- **Terminate the instance** to drop to $0. Check the EBS volume is deleted
  too — detached volumes keep billing.

Running Postgres on the instance instead of RDS is what keeps this near $15;
RDS would roughly double it and can't be paused as easily.

---

## Next steps

1. **Add authentication.** Sanctum is already installed — put the blog routes
   behind `auth:sanctum` before this runs unattended.
2. **Get a domain**, then certbot for HTTPS (Step 8 of `DEPLOYMENT.md`).
   Remember to flip `SESSION_SECURE_COOKIE` back to `true` afterwards.
3. **Move uploads to S3** and sessions/cache to Redis — this makes the instance
   stateless, which is the prerequisite for everything below.
4. **Move Postgres to RDS.** A good exercise precisely because the app already
   runs and you'll see exactly what changes.
5. **Add a load balancer and a second instance.** Only possible once stateless.
