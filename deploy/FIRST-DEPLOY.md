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

## Phase 0 — Account setup

Do this once, in this order. Steps 1-3 must happen as **root**; everything
after uses the IAM user.

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

> AWS resources are **region-scoped**. If you create the instance in one region
> and later look in another, it appears to have vanished. Almost everyone hits
> this once. Check the region selector before every console step.

---

## Navigating the console

Two ways to get anywhere:

- **Search bar at the top of every page.** Type `EC2`, `Budgets`, `Elastic IP`.
  Fastest general method.
- **Direct links** below. These pin `ap-southeast-1`, so they can't land you in
  the wrong region.

| Where | Link |
|---|---|
| Budgets (billing is global, lives in us-east-1) | https://us-east-1.console.aws.amazon.com/costmanagement/home#/budgets |
| Launch an instance | https://ap-southeast-1.console.aws.amazon.com/ec2/home?region=ap-southeast-1#LaunchInstances: |
| Your instances | https://ap-southeast-1.console.aws.amazon.com/ec2/home?region=ap-southeast-1#Instances: |
| Elastic IPs | https://ap-southeast-1.console.aws.amazon.com/ec2/home?region=ap-southeast-1#Addresses: |
| Security groups | https://ap-southeast-1.console.aws.amazon.com/ec2/home?region=ap-southeast-1#SecurityGroups: |

Inside EC2, the **left sidebar** is the main map:

```
Instances
  └─ Instances          ← your servers, start/stop, find the public IP
Network & Security
  ├─ Security Groups    ← firewall rules
  ├─ Elastic IPs        ← static IP addresses
  └─ Key Pairs          ← the .pem you SSH with
```

> AWS moves labels and buttons around. If something below doesn't match what
> you see, the *sequence* is still right — look for the nearest equivalent
> wording rather than assuming you're on the wrong screen.

---

## Phase 1 — Launch the instance

EC2 → Instances → **Launch instances**

The wizard is one long form. Panels, top to bottom:

1. **Name and tags** — `test-api`
2. **Application and OS Images** — Quick Start → **Ubuntu** tile → the dropdown
   should read *Ubuntu Server 24.04 LTS (HVM), SSD Volume Type*, marked
   "Free tier eligible"
3. **Instance type** — `t3.micro`
4. **Key pair (login)** — click **Create new key pair** → name `test-api-key`,
   type RSA, format **.pem** → Create. It downloads immediately.
5. **Network settings** — click **Edit** (top-right of that panel) and set the
   inbound rules per the table below
6. **Configure storage** — 20 GiB, gp3
7. **Summary** panel on the right → **Launch instance**

After launching you get a green success banner → **View all instances**. Wait
until *Instance state* is `Running` **and** *Status checks* shows `2/2 passed`.
That takes a couple of minutes; SSH will refuse connections before it finishes.

Find your address on the instance's **Details** tab, field
**Public IPv4 address**.

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

Left sidebar → **Network & Security → Elastic IPs**

1. Orange **Allocate Elastic IP address** button (top right) → leave defaults →
   **Allocate**
2. Tick the new address in the list → **Actions** dropdown → **Associate
   Elastic IP address**
3. Resource type **Instance** → choose `test-api` → **Associate**

The instance's Public IPv4 address now changes to this one — use it from here
on. Without this step the IP changes every time the instance stops.

> Since February 2024 AWS charges for **every** public IPv4 address — roughly
> $0.005/hour (~$3.65/month), whether it's attached or idle. The 12-month free
> tier covers 750 hours/month of it, so one address on one instance is normally
> $0 while you're still free-tier eligible.
>
> Stopping the instance does **not** stop the IP charge. When you're finished
> with this project, release the address (Elastic IPs → Actions → Release) or
> it bills quietly forever.

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

Free-tier eligible accounts get 750 hrs/month of `t3.micro`, 30 GB EBS, and
750 hrs/month of public IPv4 for the first 12 months — enough to run this
continuously at no cost.

Levers, biggest first:

- **Stop the instance when not working on it.** Compute billing stops at once;
  you keep paying only storage + IP (~$5.50/month). Restart takes ~30 seconds.
- **Release the Elastic IP** when the project is done, or it bills forever.
- **Terminate the instance** to drop to $0. Check the EBS volume is deleted
  too — detached volumes keep billing.

Running Postgres on this instance instead of RDS is what keeps the figure
near $15; RDS would roughly double it and can't be stopped as easily.

---

## Once it works

1. **Stop the instance when you're not using it.** Compute stops billing; the
   Elastic IP keeps the address. Start it again when you need it.
2. Add Sanctum auth to the blog routes.
3. Get a domain, then certbot for HTTPS (Step 8 of `DEPLOYMENT.md`).
4. Move Postgres to RDS — a good exercise precisely because the app is already
   running and you'll see exactly what changes.
