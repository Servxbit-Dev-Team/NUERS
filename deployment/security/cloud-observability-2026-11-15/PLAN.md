# NUERS network audit logging — reviewable rollout

Prepared 2026-11-15. Target: AWS account 289829301267, Singapore. Not yet applied.

- New dedicated private bucket `nuers-289829301267-network-audit-sg`, SSE-S3 encryption, all public-access blocks, bucket-owner-enforced ownership. Existing CloudTrail and business-document buckets remain untouched.
- ALB `nuers-prod`: access logging to `alb/`. Service `logdelivery.elasticloadbalancing.amazonaws.com` gets only PutObject into the account-specific ALB prefix, constrained to this account/region's load balancers.
- Production VPC `vpc-06225f1a965755eb1`: ALL flow records to `flow/`, ten-minute aggregation, hourly partitions. Service `delivery.logs.amazonaws.com` gets only account-specific prefix PutObject and bucket ACL inspection, constrained to this account/region. No application role receives log read access.
- New bucket logs expire after 90 days. Expiration permanently removes only the newly collected network logs. No existing logs or application data are affected.
- Enable ALB logging after policy installation. Create VPC flow logging and immediately reconcile/verify the exact reviewed policy, because AWS may auto-generate delivery statements. Failure leaves rollout marked incomplete; do not claim delivery until actual objects appear under both prefixes.
- Rollback: restore previous ALB logging attributes; stop new flow delivery. Retain collected evidence and bucket. No automatic deletion of the bucket or log bodies.

## Cost guardrails

Fresh November actuals: Cost Explorer US$212.53 through Nov14, budget service US$222.004 at its later refresh. AWS forecast unavailable (insufficient history). Existing budget US$1,450 includes tax and all services; alerts at 50/80/95/100% actual and 100% forecast.

Lower existing monthly budget to **US$1,300**, preserving recipients and thresholds: **₱91,000 at a conservative planning rate of ₱70/USD**, leaving ₱9,000 below the user's ₱100,000 limit for exchange-rate variance/other charges. This is an alert threshold, not an AWS hard spending cap. No paid Cloudflare upgrade or Inspector subscription is part of this change.

Planning model, not a guaranteed bill: US$19/day ×31×1.12 tax allowance +US$50 logging reserve +US$5 temporary staging allowance = US$714.68, or about ₱50,028 at ₱70/USD. The log reserve is a planning allowance, not a metered ingestion cap. Measure first-day log volume and service cost before asserting an ongoing forecast. One existing staging node is bounded to four hours and must be stopped after testing.

## Validation

`prepare.py` generates `plan.json` and asserts service/action/account/region/prefix isolation and separation from existing buckets. AWS IAM Access Analyzer validation is performed read-only before applying. Post-apply checks: encryption, public access, exact policy, retention, ALB attributes, flow delivery status, actual log-object timestamps and sizes (no customer log bodies printed), existing CloudTrail delivery, healthy production targets, public HTTP smoke checks.

Approval is required by the browser's Computer Use Confirmation Policy for the new service access and permanent 90-day log expiration. This is separate from the user's existing authorization to enable logging.
