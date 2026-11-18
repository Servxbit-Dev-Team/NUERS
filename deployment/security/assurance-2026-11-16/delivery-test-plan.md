# Explicit delivery checks — 16 November 2026

Destination: the existing confirmed infrastructure-alert subscriber, siosonmarkdavid@gmail.com.

1. One SNS message to existing `nuers-staging-infrastructure-alerts` topic. Subject: `NUERS STAGING TEST — alert delivery 2026-11-16`. Body: `This is an authorized NUERS staging delivery test. No incident is in progress. No customer data is included. Please confirm receipt and approximate receipt time. Reference: NUERS-ASSURANCE-20261116-SNS.`
2. One SMTP message through the existing configured NUERS mail transport, to the same recipient. Subject: `NUERS | STAGING TEST — mail delivery 2026-11-16`. Body: `This is an authorized NUERS mail delivery test. This is not a registration OTP or password reset. No customer data is included. Please confirm receipt and approximate receipt time. Reference: NUERS-ASSURANCE-20261116-SMTP.`

No new subscribers, credentials, access permissions, account records or customer notifications. SMTP credentials remain on the existing production host and are not copied to staging. The transport acceptance ID or SMTP success does not establish inbox receipt; user confirmation is a separate gate.

These two messages require explicit user instruction before sending. Staging transport and recipient-isolation tests run first. Budget: existing SNS/email services only; no paid monitoring subscription or new resources.
