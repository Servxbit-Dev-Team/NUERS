# NUERS / BIR EIS integration alignment audit

Date: 28 October 2026. NUERS 1.0.0. Scope: local application code, public BIR EIS certification guide and isolated tests. This is not an accreditation or BIR certification result. After the user restored SSH, existing live source baselines matched. The PTI preparation release was deployed to nuers.net with 522 file hashes verified, MFA enforced, BIR transport blocked and source records preserved. See deploy-result.log for the private release verification record.

## Conclusion

Partially aligned at the internal workflow and control level. **Not ready for direct BIR EIS transmission.** PTI application preparation and filing tracking can be implemented now, but the supplied guide does not document an electronic PTI filing API.

| Area | BIR guide / expected boundary | Local finding |
| --- | --- | --- |
| Taxpayer authority | PTI, EIS certification and PTT are separate | Permit evidence records and production authority gates exist. A NUERS review does not establish BIR approval. |
| Authentication | BIR application / EIS identity, RSA-protected bootstrap, token and session key exchange; token lifetime documented as six hours | No working BIR authentication provider. Configuration can retain protected metadata/credentials, but is not an authentication implementation. |
| Invoice schema | Published JSON 2.1: official document, seller, buyer, item, tax, adjustment and reference fields; CAS / POS rules differ | Uses `NUERS-normalized-document-4`, with `official_bir_schema_verified=false`. No certified official field mapper. |
| Mixed tax treatment | Guide includes classification-specific requirements, including CAS separation rules | Internal mixed-tax ingestion cannot be treated as proof that the same JSON can be sent unchanged to EIS. Explicit mapping, document identities and reconciliation need tests. |
| Message security | JWS signing, session-key encryption and authenticated request headers | BIR-specific signing/encryption/authenticated header implementation missing. Merchant API security and merchant webhook signatures serve a different interface. |
| Batch submission | 1–100 invoices per documented submission; BIR submission identity must be retained | NUERS local preparation has a 100-record operator limit. This is not a working wire-format batch submitter. |
| Result inquiry | Query by BIR submission identifier; processing may still be incomplete | Durable queue and uncertain-result inquiry abstraction exist, tested using fake providers. Actual BIR inquiry and verified result parsing are missing. |
| Optional callback | Taxpayer-hosted BIR result callback protocol | Merchant ERP callbacks do not implement the BIR callback contract. A distinct verified receiver is needed if callbacks are enabled. |
| Retries / duplicates | Preserve identity and reconcile uncertain outcomes | Local durable delivery, leases, duplicate controls and safeguards exist. Interoperability with actual BIR responses is unproven. |
| Production readiness | Authorized adapter, authentic response verification and successful certification | `transport_implemented`, `official_interface_verified` and `production_response_verification_implemented` are false. Default provider is `BlockedProvider`. Keep gates closed. |
| PTI applications | RMC 98-2026 IV.12: registered RDO / LT Office; no PTI API in the supplied guide | Deployed preparation workspace supports drafts, encrypted attachments, review, PDF / ZIP packet and evidence-backed filing / permit tracking. No direct BIR filing. |

## Evidence in source

- `config/bir_compliance.php:9–11`: disabled transport, interface and response-verification gates.
- `app/Services/Eis/BlockedProvider.php`: provider refuses real send and inquiry.
- `app/Services/EisDelivery.php:16`: default blocked provider; durable delivery controls elsewhere in the same service.
- `app/Services/EisDocumentReadiness.php:14,128,151`: internal schema, unverified official mapping and transmission disabled.
- `app/Services/EisTransmissionCenter.php:15`: local 100-document limit explicitly distinguished from the BIR wire protocol.
- `app/Http/Controllers/Api/PtiApplicationController.php`: owner-scoped application queries, encrypted evidence and explicit administrative status transitions.

## Verification

- Existing readiness / center / RMC compliance suites: **43 tests, 391 assertions passed**.
- PTI application, taxpayer workspace and durable-workflow regression suites: **46 tests, 458 assertions passed**. A final PTI-only run, including ZIP packet integrity, passed 8 tests / 103 assertions.
- TypeScript and both application builds passed. UI preview uses isolated synthetic data and cannot transmit to BIR.
- These tests prove local behavior only. Fake-provider tests are not BIR certification.

## Remaining implementation sequence

1. Pin the authorized BIR specification and obtain environment-specific application identity / test credentials through BIR's channel. Never collect secrets in chat or ship them in frontend assets.
2. Implement and test official JSON mapping for CAS / POS, sales tax categories, adjustments, discounts, source identifiers and invoice identity. Preserve the original merchant record and record mapping lineage.
3. Build BIR authentication, cryptographic envelope and signing key handling, with isolated certification / production credentials, redacted logs and certificate verification.
4. Implement batch submission, BIR submission-ID persistence, result inquiry, backoff and reconciliation. Add callback verification only if the optional callback path is adopted.
5. Run the mandatory BIR certification scenarios and retain actual outcomes. The RMC describes five mandatory tests, or seven when API callback is used; check the current portal for the authoritative test details.
6. Enable production only after authorized credentials, taxpayer/system/branch coverage, successful certification and authentic-response checks are evidenced. Never mark NUERS acceptance as BIR acknowledgement.

## Primary references

- [BIR EIS Certification API Guide](https://eis-cert.bir.gov.ph/#/apiGuide), public rendered guide inspected on 28 October 2026.
- [RMC 98-2026](https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2098-2026%20redacted.pdf), sections II.6 and IV.12–15.
