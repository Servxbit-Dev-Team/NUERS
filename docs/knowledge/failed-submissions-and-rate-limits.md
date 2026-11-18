# Failed submissions and rate limits

The Super Admin Dashboard's **Invalid Transactions** card includes failed/voided recorded transactions plus failed **Live API attempts**. Its note shows the two counts separately. Select **Review failed submissions** to inspect the request history. Use the environment, HTTP response and merchant/reference filters; pagination sits below the table.

Each failed POST to the transaction or atomic-batch intake is one attempt, including attempts rejected by quotas, inactive/expired keys, validation, conflicts and insufficient balance. A batch is one attempt, not an inferred number of lost sales. A successful retry does not erase an earlier failed attempt. Successful attempts and idempotent retries are excluded from the failure count. The graph and transaction amount remain based on recorded financial transactions.

For each attributable attempt, NUERS records the actual HTTP response, normalized error reason/code, environment, source/event reference, branch and received time. Identity comes from the presented API key, never merchant fields in the payload. Unknown or conflicting credentials cannot be assigned to a merchant. Environment is captured for new logs; historical logs use their linked key, and logs without environment evidence are excluded. Old logs that lack a reason show a status-based explanation explicitly labeled as historical.

The failure view is restricted to authenticated administrators and super administrators, with existing MFA policy. It does not return raw payloads, customer email, API keys or hashes, IP addresses, or credentials. No sale, receipt, debit or email is created by failure logging. Evidence capture is best effort: logging failure must never turn a committed sale into a false rejection.

HTTP 5xx and 408 mean the outcome may be unknown. Check status before retrying with the original identity. HTTP 402 requires sufficient wallet funds; 422 requires corrected request data; 409 requires resolving the identity/content conflict; 429 requires respecting Retry-After and backoff. NUERS cannot observe an offline request or a request rejected upstream before reaching the application; the ERP/POS must retain its pending outbox and error history.

Browser HTTP 429 responses use the NUERS branded page and a countdown when a Retry-After duration is provided. It never automatically resubmits the user's action. API clients continue receiving JSON and the original status/headers. Security quotas remain enforced.

Implementation: additive nullable request-log metadata plus indexes; no financial or identity migration. Verification uses isolated synthetic fixtures and existing live log evidence, without submitting a live test sale.
