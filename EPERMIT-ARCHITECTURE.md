# Reference architecture: DHSUD ePermit (pilot)

A proposed AWS architecture for a public permit application and evaluation
system.

**Requirements this addresses:** document storage, performance under traffic
spikes, no downtime, backups, and the compliance posture a government system
handling citizen data needs.

> **This is a starting design, not a final one.** Anything here that touches
> data residency, procurement, or the Data Privacy Act must be confirmed with
> DHSUD's legal/compliance office, the National Privacy Commission, and DICT
> before it becomes a commitment.

---

## Contents

- [Decide first](#decide-these-before-anything-else)
- [Environments](#environments)
- [The architecture](#the-architecture)
- [Component choices](#component-choices-and-why)
- [Handling documents](#handling-documents-the-part-most-designs-get-wrong)
- [High availability](#high-availability-and-zero-downtime)
- [Backup and recovery](#backup-and-disaster-recovery)
- [Security and compliance](#security-and-compliance)
- [Cost estimate](#cost-estimate)
- [Phasing](#phasing-dont-build-it-all-at-once)

---

## Decide these before anything else

Four questions whose answers change the architecture. None are technical.

### 1. Data residency

Does DHSUD data have to remain physically in the Philippines?

RA 10173 does not mandate in-country storage, but DICT cloud policy, agency
rules, or procurement conditions may. If in-country residency is required, the
AWS region set available to you changes and this design needs revisiting.

**Verify before designing further.** It is the one constraint that can
invalidate everything else.

### 2. Expected load

"High traffic" needs a number. Permit systems are famously **spiky** — deadline
days can carry 50× a normal day.

Ask: how many applications per year, and what proportion land in the final
week before a deadline? That ratio sizes the whole system.

### 3. Document profile

Permit applications carry plans, titles, clearances. Are these 2 MB PDFs or
200 MB CAD files? This determines upload strategy, storage cost, and whether
you need virus scanning.

### 4. Retention

How long must permit records be kept — 5 years, 10, permanently? Drives S3
lifecycle policy and long-term cost.

---

## Environments

Separate **AWS accounts**, not just separate instances, under AWS Organizations:

```
DHSUD Organization (Control Tower)
├── epermit-prod        ← citizen data, tightly restricted access
├── epermit-staging     ← production's shape, synthetic data only
└── epermit-dev         ← developers work freely
```

Why accounts rather than a shared account with tags:

- **Blast radius** — an over-broad IAM policy in dev cannot reach production
- **Billing clarity** — per-environment cost with no allocation guesswork
- **Access control** — most developers never hold production credentials
- **Audit** — per-account CloudTrail, which matters when demonstrating who
  accessed citizen data

Staging mirrors production's *shape*, not its *scale*:

| | Production | Staging |
|---|---|---|
| Availability zones | 3 | 1 |
| RDS | Multi-AZ | Single-AZ |
| App tasks | Auto-scaling 2–10 | Fixed 1 |
| Redis | Multi-AZ | Single node |
| Hours | 24/7 | Business hours, scheduled off |

Typically ~25% of production cost.

> **Staging must never hold real applicant data.** Copying the production
> database into staging puts citizens' names, addresses and uploaded IDs in
> front of everyone with staging access — a live Data Privacy Act exposure and
> the most common way government systems leak PII. Build an anonymisation
> script on day one; retrofitting it after the fact is much harder.

---

## The architecture

```
                          Route 53 (epermit.gov.ph)
                                    │
                                    ▼
                    ┌───────────────────────────────┐
                    │  CloudFront  +  AWS WAF       │  ← DDoS, rate limiting,
                    │  (CDN, static assets, TLS)    │    OWASP rules, geo rules
                    └───────────────┬───────────────┘
                                    ▼
                    ┌───────────────────────────────┐
                    │   Application Load Balancer   │  ← public subnets
                    │   (ACM cert, health checks)   │    across 3 AZs
                    └───────────────┬───────────────┘
                                    ▼
        ┌───────────────────────────────────────────────────┐
        │            PRIVATE SUBNETS (3 AZs)                │
        │                                                    │
        │   ┌──────────────┐        ┌──────────────┐        │
        │   │ ECS Fargate  │        │ ECS Fargate  │        │
        │   │  web tasks   │        │ queue workers│        │
        │   │  (2–10)      │        │   (1–4)      │        │
        │   └──────┬───────┘        └──────┬───────┘        │
        │          └───────────┬───────────┘                │
        │                      │                             │
        │     ┌────────────────┼────────────────┐           │
        │     ▼                ▼                ▼           │
        │ ┌────────┐    ┌────────────┐   ┌───────────┐     │
        │ │  RDS   │    │ElastiCache │   │    SQS    │     │
        │ │Postgres│    │   Redis    │   │  queues   │     │
        │ │Multi-AZ│    │  Multi-AZ  │   └───────────┘     │
        │ │+replica│    └────────────┘                      │
        │ └────────┘                                         │
        └───────────────────────────────────────────────────┘
                                    │
                    ┌───────────────┴───────────────┐
                    ▼                               ▼
            ┌───────────────┐             ┌──────────────────┐
            │      S3       │             │ Secrets Manager  │
            │  documents    │             │  KMS, GuardDuty  │
            │  versioned,   │             │  CloudTrail      │
            │  encrypted    │             │  CloudWatch      │
            └───────────────┘             └──────────────────┘
```

---

## Component choices and why

### Compute — ECS Fargate

**Recommended.** Containers with no servers to manage.

- No OS patching, no SSH keys to rotate or leak
- Scales on CPU/memory or request count automatically
- Immutable deploys — a new task set replaces the old, no config drift
- Smaller attack surface, which materially simplifies a security review

**Alternative: EC2 Auto Scaling Group.** Closer to what you now know from
test-api, and a legitimate choice — you manage AMIs and patching yourself. Pick
this if the team needs to move fast on familiar ground; the trade is ongoing
operational burden.

Run **web tasks and queue workers as separate services** so a burst of
document processing can't starve request handling.

### Database — RDS PostgreSQL, Multi-AZ

- **Multi-AZ** — synchronous standby in another AZ, automatic failover in
  60–120 seconds. This is what makes patching and hardware failure invisible.
- **Read replica** — point dashboards and monitoring queries at it so
  reporting can't slow down applicants.
- **Automated backups** with point-in-time recovery, 30-day retention.
- Credentials in **Secrets Manager**, rotated automatically. Never in `.env`.

### Cache and sessions — ElastiCache Redis, Multi-AZ

Sessions must be shared across tasks, or users get logged out as requests move
between them. Also caches permit-type lookups, office directories, and other
reference data.

### Queues — SQS

Prefer SQS over Redis queues here. Permit workflows involve email
notifications, PDF generation, virus scanning, and document processing — work
that must not be lost if a worker dies. SQS is durable, managed, and gives you
a dead-letter queue for free.

Laravel supports it natively: `QUEUE_CONNECTION=sqs`.

### CDN and WAF — CloudFront + AWS WAF

Not optional for a public government site.

- **CloudFront** absorbs traffic spikes at the edge and serves static assets
  without touching your app
- **WAF** gives managed OWASP rule sets, rate limiting per IP, bot control, and
  geo rules — the baseline defence for a public form that accepts uploads
- **Shield Standard** DDoS protection is included free

---

## Handling documents (the part most designs get wrong)

**Do not proxy uploads through the application.** A 100 MB plan uploaded
through PHP consumes a worker for the entire transfer, and a few concurrent
uploads can exhaust the pool while the site stays "up" but unresponsive.

Use **presigned S3 URLs**:

```
1. Browser asks the API for an upload URL
2. API validates the request, returns a short-lived presigned PUT URL
3. Browser uploads DIRECTLY to S3 — never touches your servers
4. S3 event fires → Lambda → validates, scans, records in the database
```

Your application never handles file bytes. Uploads scale independently of
compute. Same pattern in reverse for downloads.

Bucket configuration:

| Setting | Value | Why |
|---|---|---|
| Block public access | On | Access only via presigned URLs |
| Versioning | Enabled | Recover from accidental deletion or overwrite |
| Encryption | SSE-KMS | Encrypted at rest, key access audited |
| Lifecycle | Standard → IA at 90d → Glacier at 1y | Retention at ~10% of the cost |
| Replication | Cross-region | Disaster recovery |
| Object Lock | Consider | Regulatory immutability for issued permits |

**Virus scanning is mandatory** for a public upload endpoint. Trigger it from
an S3 event and quarantine anything that fails before it becomes downloadable
by DHSUD staff.

---

## High availability and zero downtime

Four separate concerns, often conflated:

| Failure | Handled by |
|---|---|
| A task crashes | ECS restarts it; ALB health checks drain it first |
| An AZ fails | Tasks in the other two AZs keep serving |
| The database fails | Multi-AZ failover, 60–120s, automatic |
| A deploy goes wrong | Blue/green — traffic shifts only after health checks pass |

**Deployments** use ECS blue/green via CodeDeploy: the new version starts
alongside the old, health checks run, traffic shifts, the old version drains.
Rollback is a traffic shift back — seconds, not a redeploy.

**Database migrations** need care that deploys alone don't give you. Use
expand/contract: add nullable columns, deploy code that writes both old and
new, backfill, then drop the old column in a *later* release. A migration that
renames a column in one step will break whichever version isn't running yet.

---

## Backup and disaster recovery

| What | Mechanism | Retention |
|---|---|---|
| Database | RDS automated backups + PITR | 30 days |
| Database | Manual snapshots before releases | 1 year |
| Documents | S3 versioning | Indefinite |
| Documents | Cross-region replication | Mirrors source |
| Everything | AWS Backup, single policy | Per retention rules |
| Config | Infrastructure as code in git | Forever |

Set explicit targets and design to them:

- **RPO** (how much data you can afford to lose) — 5 minutes with PITR
- **RTO** (how long recovery may take) — 1 hour for a full regional failure

> **An untested backup is not a backup.** Schedule a quarterly restore drill
> into a scratch account and time it. Most organisations discover their
> recovery process is broken during an actual incident.

---

## Security and compliance

Baseline for a system holding citizen data:

| Control | Service |
|---|---|
| Encryption at rest | KMS — RDS, S3, EBS |
| Encryption in transit | ACM / TLS 1.2+ everywhere |
| Secrets | Secrets Manager, auto-rotation |
| API audit trail | CloudTrail, all regions, log file validation on |
| Threat detection | GuardDuty |
| Posture monitoring | Security Hub, AWS Config |
| Network logs | VPC Flow Logs |
| Least privilege | IAM roles per service; no long-lived keys |

Application-level obligations that AWS cannot give you:

- **Audit log of every access to applicant data** — who viewed which
  application, when. Distinct from CloudTrail, which logs AWS API calls, not
  your app's data access.
- **Role-based access control** — applicant, evaluator, supervisor, admin
- **Data subject rights** under RA 10173 — access, correction, erasure
- **Retention enforcement** — automatic deletion when the period expires
- **NPC registration** as a personal information controller

---

## Cost estimate

Rough monthly figures for a **pilot**, `ap-southeast-1`. Treat as an order of
magnitude and verify with https://calculator.aws.

| Component | ~Monthly (USD) |
|---|---|
| ALB | $25 |
| ECS Fargate (2 web + 1 worker, small) | $60 |
| RDS PostgreSQL Multi-AZ (db.t4g.medium) | $130 |
| ElastiCache Redis (cache.t4g.micro ×2) | $30 |
| NAT Gateway (×2 AZ) | $70 |
| S3 + CloudFront (modest volume) | $25 |
| WAF | $15 |
| CloudWatch, GuardDuty, Secrets Manager | $30 |
| **Production subtotal** | **≈ $385** |
| Staging (~25%) | ≈ $95 |
| **Total** | **≈ $480** |

Notes that matter:

- **NAT Gateway is the classic surprise** — $70/month to let private subnets
  reach the internet. VPC endpoints for S3 and ECR remove much of that traffic.
- **RDS Multi-AZ doubles database cost.** It is what buys invisible failover.
  Single-AZ halves it if the pilot can tolerate minutes of downtime.
- Costs scale with traffic and storage; the above is a floor, not a cap.

---

## Phasing (don't build it all at once)

Building the full diagram before you have users is how pilots stall.

### Phase 1 — Working pilot

ALB + ECS Fargate (2 tasks) + RDS Multi-AZ + S3 + CloudFront/WAF.
No Redis (use the database driver), no read replica, no cross-region
replication.

Gets you: HA, zero-downtime deploys, backups, real security posture.
**~$250/month.** Enough for a genuine pilot with real users.

### Phase 2 — When load justifies it

Add ElastiCache, SQS workers, a read replica, and auto-scaling policies tuned
to observed traffic rather than guesses.

### Phase 3 — When it becomes critical infrastructure

Cross-region DR, Object Lock on issued permits, an advanced WAF ruleset,
Shield Advanced, and a formal security assessment.

---

## What I'd insist on from day one

Not everything above is equally urgent. These four are:

1. **Separate AWS accounts** per environment. Retrofitting this is painful.
2. **Presigned S3 uploads.** Rebuilding upload flow later touches everything.
3. **Application-level audit logging** of access to applicant data. You cannot
   reconstruct it after the fact, and you will be asked for it.
4. **Synthetic data in staging.** The moment real data is copied, the exposure
   already happened.

Everything else can be added incrementally. These four are expensive or
impossible to add later.
