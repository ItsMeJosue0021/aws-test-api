# Architecture: test-api

What we deployed, what it's called, and how it would evolve.

---

## What's running now

One EC2 `t3.micro` in `ap-southeast-1` doing everything:

```
┌──────────────────── EC2 t3.micro ─────────────────────┐
│                                                        │
│   nginx :80                                            │
│      │                                                 │
│      ▼                                                 │
│   PHP-FPM 8.5  ──►  Laravel 13                        │
│                        │                               │
│                        ├──► PostgreSQL 18              │
│                        │    localhost:5432             │
│                        │    · application data         │
│                        │    · cache   (CACHE_STORE)    │
│                        │    · sessions(SESSION_DRIVER) │
│                        │    · jobs    (QUEUE_CONNECTION)│
│                        │                               │
│                        └──► storage/app/public         │
│                             uploaded blog images       │
│                                                        │
│   systemd: laravel-worker (queue)                      │
│   cron:    schedule:run every minute                   │
│                                                        │
│   ── all on one 20 GB EBS volume ──                   │
└────────────────────────────────────────────────────────┘
```

Four kinds of state live on that single volume: **application data, cache,
sessions, and uploaded files.**

---

## What this is called

| Term | What it emphasises |
|---|---|
| **Single-server** / **single-node** deployment | The literal shape. Most common name. |
| **Monolithic infrastructure** | One deployable unit holding everything |
| **One-tier architecture** | Formal term — "tier" means physical separation |
| **Pet server** (vs "cattle") | Hand-configured, irreplaceable, has a name |
| **Snowflake server** | Unique, not reproducible from code |

The tier vocabulary scales to describe where you'd go next:

- **One-tier** — this. App, data and files on one machine.
- **Two-tier** — application server separate from database server.
- **Three-tier** — web / application / data separated. The classic shape.

"Separating the database" is precisely the one-tier → two-tier move.

---

## This is a legitimate architecture

Single-server is the right choice for a learning project, an internal tool, or
an early-stage product. Plenty of real systems run this way for years. It is
simpler, cheaper, faster to deploy, and has zero network latency between the
app and its database.

What you trade away is **durability** and **scalability** — and those only
start to matter under specific, identifiable conditions.

---

## What actually breaks it

| Problem | Why one box can't solve it |
|---|---|
| **The instance dies** | The volume goes with it — data, images, everything |
| **You need a second app server** | It can't see the first's database or files |
| **Deploys cause downtime** | Nothing else is serving while you deploy |
| **DB load competes with web load** | A heavy query starves PHP-FPM of CPU |
| **Storage fills** | 20 GB shared between OS, database and uploads |

> The one that matters today is the first. **This instance has no backups.**
> Lose the volume and everything on it is gone. Enabling EBS snapshots costs
> cents per month and is the highest-value change available — well before any
> architectural work.

---

## Evolution path

Three stages, in this order. The order is not optional — see the note at the
end.

### Stage 1 — Images to S3

Highest value for least effort, because it protects data that can't be
regenerated.

```bash
composer require league/flysystem-aws-s3-v3
```

Then one constant in `app/Services/BlogService.php`:

```php
public const IMAGE_DISK = 's3';   // was 'public'
```

Plus an S3 bucket and an **IAM role attached to the instance** — never access
keys in `.env`. That's why `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` are
empty in the env template and should stay that way: the SDK reads credentials
from instance metadata automatically.

> `BlogImage` stores a **`disk` column per row** specifically for this. Images
> already written locally keep resolving from the local disk while new ones go
> to S3 — no migration script, no broken URLs, no big-bang cutover.

**Cost:** pennies. S3 is roughly $0.025/GB-month.

### Stage 2 — Database to RDS

Provision RDS PostgreSQL, then move the data:

```bash
pg_dump -h 127.0.0.1 -U testapi test_api > backup.sql
psql -h <rds-endpoint> -U postgres -d test_api < backup.sql
```

Change `DB_HOST` in `.env`, run `php artisan config:cache`. **No application
code changes** — the payoff for using Eloquent rather than raw connections.

You gain automated backups, point-in-time recovery, and optional Multi-AZ
failover.

**Cost:** ~$15/month. The real price of this stage.

### Stage 3 — Cache, sessions and queues to ElastiCache

```ini
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

Now the instance holds **nothing**. It is stateless: disposable, replaceable,
reproducible.

### Then horizontal scaling becomes possible

```
                     ┌─────────────┐
    Internet ───────►│     ALB     │
                     └──────┬──────┘
                      ┌─────┴─────┐
                      ▼           ▼
                  ┌───────┐   ┌───────┐
                  │ EC2 1 │   │ EC2 2 │   ← identical, stateless
                  └───┬───┘   └───┬───┘
                      └─────┬─────┘
                 ┌──────────┼──────────┐
                 ▼          ▼          ▼
              ┌─────┐   ┌───────┐  ┌──────┐
              │ RDS │   │ Redis │  │  S3  │
              └─────┘   └───────┘  └──────┘
```

Zero-downtime deploys, survives an instance failure, scales with traffic.

> **The ordering is mandatory.** You cannot put a load balancer in front of two
> instances before the app is stateless. Each would have its own database and
> its own image folder, so users would get different answers depending on which
> instance served them — and sessions would break as requests bounced between
> them.

---

## Recommended next steps

In order of value per unit of effort:

1. **Enable EBS snapshots.** Solves "the instance dies" for a few cents/month
   with no architectural change. Do this first.
2. **Add authentication.** Sanctum is installed; the blog routes are open.
3. **Stage 1 (S3).** Small code change, and the exercise teaches IAM roles —
   core AWS knowledge.
4. **Stop.** Do stages 2 and 3 as deliberate learning exercises, not because
   the architecture is wrong. It isn't.
