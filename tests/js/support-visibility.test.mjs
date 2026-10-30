import { test } from "node:test";
import assert from "node:assert/strict";
import { supportWidgetAllowed } from "../../src/lib/support-visibility.ts";

test("customer chat is available on merchant and citizen dashboards and their child pages", () => {
  for (const path of ["/merchant", "/merchant/invoices", "/merchant/invoices/example"])
    assert.equal(supportWidgetAllowed(path, "merchant"), true);
  for (const role of ["client", "consumer"])
    for (const path of ["/client", "/client/profile", "/consumer"])
      assert.equal(supportWidgetAllowed(path, role), true);
});
test("admin roles and all non-customer routes exclude the chat widget", () => {
  for (const role of ["admin", "super_admin", "bir", "rdo", null])
    for (const path of ["/super-admin", "/system", "/invoices", "/merchant", "/client"])
      assert.equal(supportWidgetAllowed(path, role), false);
  for (const role of ["merchant", "client", "consumer"])
    for (const path of [
      "/",
      "/login",
      "/register",
      "/system",
      "/monitoring",
      "/invoices",
      "/merchant-other",
      "/client-other",
    ])
      assert.equal(supportWidgetAllowed(path, role), false);
});
