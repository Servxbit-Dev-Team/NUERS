# PTI application preparation and filing tracking

Checked against the public BIR EIS certification API guide and RMC 98-2026 on 28 October 2026. Software: NUERS 1.0.0.

## Integration boundary

The public EIS certification API guide describes authentication, invoice submission, result inquiry and an optional taxpayer-hosted result callback. It does not document a PTI application submission API. NUERS must not label an internal save or preparation review as a filing with BIR.

RMC 98-2026 section IV.12 places PTI applications at the taxpayer's registered RDO / LT Office. Confirm the latest official forms, documentary requirements and permitted filing channel with that office. The NUERS checklist is a preparation aid, not an official exhaustive BIR checklist.

## User workflow

1. Merchant owner: open **Settings → PTI Applications**. Super Admin: open **PTI / PTT Management → PTI Applications** and select the registered taxpayer.
2. Start an application. Registered identity comes from the business record. Enter the system/version/provider, scope, covered BIR branch codes, representative and registered office. Save a draft.
3. Upload preparation documents: registration, representative authority or owner declaration, system description/manual, and sample invoice. Additional official office instructions and supporting files can be included. Files remain private and encrypted. Limit: 3 MB each, 25 files / 30 MB per application.
4. Send for **NUERS preparation review**. A reviewer can request corrections or mark it ready for filing. This does not submit to BIR or grant issuance authority.
5. Download the branded summary PDF and ZIP packet. Complete the official application and file through the registered office's prescribed channel.
6. Administrator records the actual receiving reference, filing date, office and uploaded receiving evidence. Only then is the application shown as **BIR filing recorded**.
7. Administrator records the actual BIR-issued PTI reference, issue date, stated expiry if any, selected covered branch and permit evidence. The resulting authority is an administrative evidence record, not live BIR verification. Other covered branches must be recorded and validated against the actual authority scope; a single selected branch does not automatically authorize every branch.

## Controls and status meanings

Merchant owners see only their own applications and attachments. Staff without owner authority cannot manage these applications. Administrative routes require the existing administrative authentication and MFA policy. Requests carry current revisions; stale changes return 409. Draft creation uses an idempotency identifier. Status transitions are explicit and audited. Encrypted draft history and attachment versions remain available; application withdrawal does not delete the audit history.

`draft → in_review → ready_to_file → filed → issued / declined`

Reviewers can request corrections before filing. Draft, review and ready records can be withdrawn in NUERS. Official outcomes require matching application-specific BIR evidence. A changed registered identity must be incorporated in a corrected draft before preparation review can pass.

No key, invoice, transaction, wallet charge or BIR network request is created by application preparation. The EIS transport gates remain disabled until an official adapter and response verification have been implemented and successfully tested against the authorized BIR environment.

## References

- [BIR EIS Certification API Guide](https://eis-cert.bir.gov.ph/#/apiGuide)
- [BIR RMC 98-2026](https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2098-2026%20redacted.pdf), section IV.12–15.
