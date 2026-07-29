# Deployment files

Server configuration for test-api, kept in version control so you copy
known-good files onto the box instead of retyping them into `nano`.

These are **templates**. Each has a `<placeholder>` you must replace.

| File | Installs to | Placeholder to replace |
|---|---|---|
| `nginx.conf` | `/etc/nginx/sites-available/test-api` | `<your-domain.com>` |
| `php-uploads.ini` | `/etc/php/8.3/fpm/conf.d/99-test-api.ini` | none |
| `laravel-worker.service` | `/etc/systemd/system/laravel-worker.service` | none |
| `../deploy.sh` | stays in the repo, run in place | none |
| `../.env.production.example` | copy to `.env` on the server | several — see the file |

Full context for all of this is in [`../DEPLOYMENT.md`](../DEPLOYMENT.md).
This file is just the install commands.

---

## Prerequisites

The app is already cloned to `/var/www/test-api`, and nginx + PHP 8.3 are
installed (Steps 4-5 of `DEPLOYMENT.md`).

---

## 1. Environment file

```bash
cd /var/www/test-api
cp .env.production.example .env
nano .env                    # replace every <PLACEHOLDER>
php artisan key:generate     # generates a fresh APP_KEY in place
```

Verify the database credentials work before continuing:

```bash
php artisan migrate --force
```

---

## 2. nginx

```bash
cd /var/www/test-api

# Set your domain, then install
sed -i 's/<your-domain.com>/example.com/' deploy/nginx.conf
sudo cp deploy/nginx.conf /etc/nginx/sites-available/test-api

sudo ln -sf /etc/nginx/sites-available/test-api /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default

sudo nginx -t && sudo systemctl reload nginx
```

`nginx -t` validates the config. **If it fails, do not reload** — fix the error
first, or you'll drop the running config.

Check it:

```bash
curl -i http://localhost/up
```

A `200` means nginx, PHP-FPM, and Laravel are all talking to each other.

---

## 3. PHP upload limits

Ubuntu's PHP defaults (`upload_max_filesize=2M`, `post_max_size=8M`) are smaller
than what the blog endpoints accept, so image uploads fail confusingly without
this.

```bash
sudo cp /var/www/test-api/deploy/php-uploads.ini \
        /etc/php/8.3/fpm/conf.d/99-test-api.ini

sudo systemctl restart php8.3-fpm

# Confirm the new values took effect
php -i | grep -E 'upload_max_filesize|post_max_size'
```

Note `php -i` shows the **CLI** values. To check what FPM is using, hit a page
running `phpinfo()`, or trust the restart -- the file only lives in the `fpm`
conf.d directory.

---

## 4. Queue worker

```bash
sudo cp /var/www/test-api/deploy/laravel-worker.service \
        /etc/systemd/system/laravel-worker.service

sudo systemctl daemon-reload
sudo systemctl enable --now laravel-worker
sudo systemctl status laravel-worker
```

Expect `active (running)`. Follow its output with:

```bash
sudo journalctl -u laravel-worker -f
```

---

## 5. Scheduler

Laravel's scheduler needs exactly one cron entry. `sudo crontab -e`:

```
* * * * * cd /var/www/test-api && php artisan schedule:run >> /dev/null 2>&1
```

It runs every minute; Laravel decides internally what's actually due.

---

## 6. Deploy script

```bash
cd /var/www/test-api
chmod +x deploy.sh
./deploy.sh
```

From here on, `./deploy.sh` is the whole deploy process.

---

## Updating a config later

Because these live in git, changing server config is a normal code change:

```bash
# On your machine
vim deploy/nginx.conf
git commit -am "nginx: raise upload limit"
git push

# On the server
cd /var/www/test-api && git pull
sudo cp deploy/nginx.conf /etc/nginx/sites-available/test-api
sudo nginx -t && sudo systemctl reload nginx
```

Note that `deploy.sh` does **not** copy these system files automatically — that
would need `sudo` on every deploy. Config changes are a deliberate, separate
step.

---

## Certbot rewrites nginx.conf

After you run `sudo certbot --nginx` (Step 8), the **installed** file at
`/etc/nginx/sites-available/test-api` gains a `listen 443 ssl` block and a
port-80 redirect. The copy in this repo stays as-is.

So if you later re-copy `deploy/nginx.conf` over it, you will wipe the TLS
config. Either re-run certbot afterwards:

```bash
sudo certbot --nginx -d example.com
```

or paste certbot's additions into `deploy/nginx.conf` and commit them, so the
repo holds the real production config. The second option is better once things
are stable.
