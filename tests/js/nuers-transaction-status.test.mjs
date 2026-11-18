import test from "node:test";
import assert from "node:assert/strict";
import { nuersSalesStatus } from "../../public/guides/NUERS-Transaction-Status.mjs";
const event = { source_event_id: "source-1", external_reference: "sale-1" };
const success = {
  ...event,
  environment: "live",
  nuers_status: "success",
  status: "durably_recorded",
  id: "id",
  transaction_id: "txn",
  receipt_id: "receipt",
  customer_receipt: { status: "queued" },
};
test("Acknowledges only matching committed sales", () => {
  assert.equal(nuersSalesStatus(event, "live", 201, success).label, "Success");
  assert.equal(
    nuersSalesStatus(event, "live", 200, { ...success, duplicate: true }).acknowledged,
    true,
  );
  for (const b of [
    { ...success, environment: "sandbox" },
    { ...success, receipt_id: "" },
    { ...success, source_event_id: "other" },
    { ...success, nuers_status: "error" },
  ])
    assert.equal(nuersSalesStatus(event, "live", 201, b).acknowledged, false);
});
test("Displays safe failure reasons and preserves unknown outcomes", () => {
  const failure = {
    nuers_status: "error",
    error: { reason: "Fund the wallet", code: "INSUFFICIENT_WALLET_BALANCE", outcome: "rejected" },
  };
  assert.equal(nuersSalesStatus(event, "live", 402, failure).reason, "Fund the wallet");
  for (const status of [0, 200, 500, 503])
    assert.equal(nuersSalesStatus(event, "live", status, null).label, "Pending confirmation");
  assert.equal(
    nuersSalesStatus(event, "live", 201, { ...success, customer_receipt: { status: "failed" } })
      .label,
    "Success",
  );
});
