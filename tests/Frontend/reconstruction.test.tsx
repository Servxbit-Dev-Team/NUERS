import assert from "node:assert/strict";
import { test } from "node:test";
import { renderToStaticMarkup } from "react-dom/server";
import { toggleRolePermission, type MerchantPermissionGroup } from "../../src/lib/merchant-role-permissions";
import { availableSaleCategories, isBenefitAllowed, benefitLine, type SalesBenefit, type SalesBenefitPolicy } from "../../src/lib/statutory-sales";
import { computeLineItem, type LineItem } from "../../src/lib/invoice-utils";
import { NodeCard } from "../../src/pages/infrastructure/components";
import { FleetMonitoring } from "../../src/pages/infrastructure/fleet-monitoring";
import type { Node } from "../../src/pages/infrastructure/types";
const catalog: MerchantPermissionGroup[] = [{ id: "documents", label: "Documents", permissions: [
  { id: "documents.view", label: "View", description: "Read documents", scope: "assigned", requires: [] },
  { id: "documents.export", label: "Export", description: "Export documents", scope: "assigned", requires: ["documents.view"] },
  { id: "documents.create", label: "Create", description: "Create documents", scope: "business", requires: ["documents.view"] },
]}];
test("Selecting an export grant includes view; removing view revokes dependent grants", () => {
  assert.deepEqual(toggleRolePermission([], "documents.export", true, catalog), ["documents.view", "documents.export"]);
  assert.deepEqual(toggleRolePermission(["documents.view", "documents.export", "documents.create"], "documents.view", false, catalog), []);
});
test("Unknown grants cannot be introduced; unrelated existing grants survive", () => {
  assert.deepEqual(toggleRolePermission([], "owner.admin", true, catalog), []);
  assert.deepEqual(toggleRolePermission(["documents.view", "documents.create", "owner.admin"], "documents.export", true, catalog), ["documents.view", "documents.export", "documents.create"]);
});
test("Cyclic catalog dependencies terminate and cannot create an infinite loop", () => {
  const cyclic = [{ ...catalog[0], permissions: catalog[0].permissions.slice(0, 2).map((p, i) => ({ ...p, requires: [i ? "documents.view" : "documents.export"] })) }];
  assert.deepEqual(toggleRolePermission([], "documents.view", true, cyclic), ["documents.view", "documents.export"]);
});
const policy: SalesBenefitPolicy = { categories: ["regular", "senior_citizen"], scopes: { senior_citizen: ["medicine"] } };
const benefit: SalesBenefit = { category: "senior_citizen", scope: "medicine", beneficiary_name: "Synthetic buyer", id_number: "TEST", evidence_reference: "synthetic", eligibility_confirmed: true };
test("Missing business policy only permits regular sales", () => {
  assert.deepEqual(availableSaleCategories(), ["regular"]);
  assert.equal(isBenefitAllowed(benefit), false);
});
test("Benefit requires both permitted category and permitted scope", () => {
  assert.deepEqual(availableSaleCategories(policy), ["regular", "senior_citizen"]);
  assert.equal(isBenefitAllowed(benefit, policy), true);
  assert.equal(isBenefitAllowed({ ...benefit, scope: "hotel" }, policy), false);
  assert.equal(isBenefitAllowed({ ...benefit, category: "pwd" }, policy), false);
});
test("VAT basis switches preserve entered prices and compute correct centavos", () => {
  assert.equal(computeLineItem(1000000, 1, 0, 12, "vatable", false).line_total, 1120000);
  const inclusive = computeLineItem(1120000, 1, 0, 12, "vatable", true);
  assert.equal(inclusive.line_total, 1120000);
  assert.equal(inclusive.vat_amount, 120000);
});
test("Statutory calculations preserve the original tax classification for server validation", () => {
  const line = { id: "synthetic", line_number: 1, item_code: "", description: "Synthetic", unit: "pc", quantity: 1, unit_price: 11200, discount_pct: 0, benefit_eligible: true, tax_type: "vatable", ...computeLineItem(11200, 1, 0, 12, "vatable", true) } as LineItem;
  const senior = benefitLine(line, benefit, true);
  assert.equal(senior.line_total, 8000); assert.equal(senior.vat_amount, 0);
  assert.equal(senior.benefit_details?.original_tax_type, "vatable");
  assert.equal(benefitLine(line, { ...benefit, category: "national_athlete_coach" }, true).line_total, 8960);
});
const node = { id: "synthetic", code: "S3-TEST", label: "Synthetic storage", tier: "storage", status: "online", provider: "aws", provider_label: "AWS", resource_type: "object_storage", resource_type_label: "Object storage", managed_service: true, provider_reference: "synthetic-bucket", region: "ap-southeast-1", environment: "staging", fresh: false, observed_at: "2026-11-14T01:00:00Z", telemetry: { resource_state: "available", disk_used_bytes: 1024 } } as Node;
test("Managed storage displays provider identity and does not invent CPU/RAM metrics", () => {
  const html = renderToStaticMarkup(<NodeCard node={node} selected={false} onSelect={() => {}} />);
  assert.match(html, /synthetic-bucket/); assert.match(html, /No current telemetry/);
  assert.doesNotMatch(html, /sm-card-meters/);
});
test("Stale fleet samples show unreported current values rather than healthy zeroes", () => {
  const html = renderToStaticMarkup(<FleetMonitoring nodes={[node]} range="1h" generatedAt="2026-11-14T02:00:00Z" onSelect={() => {}} />);
  assert.match(html, /Not reported/); assert.match(html, /History collecting/);
  assert.doesNotMatch(html, /NaN|Infinity/);
});


import { safeMfaReturnPath } from "../../src/lib/safe-navigation";
import { OfficialReceipt, type CenterDocument } from "../../src/components/invoices/official-receipt";
import { CsvReportParser, csvText } from "../../src/lib/report-data";
test("MFA return paths reject foreign origins, encoded escapes and normalized MFA loops", () => {
  for (const value of [null, "https://attacker.invalid", "//attacker.invalid", "/\\attacker.invalid", "/%5cattacker.invalid", "/%2f/attacker.invalid", "/security/mfa", "/invoices/../security/mfa?returnTo=x", "/%73ecurity/mfa", "/%00evil", "/%zz"]) assert.equal(safeMfaReturnPath(value), "/login", String(value));
  assert.equal(safeMfaReturnPath("/invoices?page=2#preview"), "/invoices?page=2#preview");
  assert.equal(safeMfaReturnPath("/merchant/customers?q=Juan%20Cruz"), "/merchant/customers?q=Juan%20Cruz");
});
test("Invoice text remains escaped and untrusted logos never become executable markup", () => {
  const hostile = '<img src=x onerror="alert(1)"><script>alert(2)</script>';
  const d = { id: "synthetic", source: "invoice", number: hostile, business: hostile, tin: "123456789000", document_type: "sales_invoice", record_status: "draft", status: "pending", category: "sales_invoice", issued_at: "2026-11-15T00:00:00Z", amount: 112, seller_address: hostile, buyer_name: hostile, buyer_address: hostile, items: [{description: hostile, quantity: 1, unit_price: 100, amount: 100}], subtotal: 100, discount: 0, vatable: 100, vat: 12, vat_registered: true, currency: "PHP", authority_status: "not_verified", notes: hostile, footer_note: hostile, presentation: {show_logo: true, logo_url: "data:image/svg+xml,<svg onload=alert(3)>", font_family: "Arial"} } as CenterDocument;
  const html = renderToStaticMarkup(<OfficialReceipt document={d} />);
  assert.match(html, /&lt;img/);
  assert.doesNotMatch(html, /<script|<img src="x"|data:image\/svg|<svg onload/);
});
test("CSV exports neutralize formulas in both headers and cells", () => {
  for (const dangerous of ['=1+1', '+SUM(A1)', '-2+3', '@SUM(A1)', '\t=1+1', '\r=1+1']) {
    const out = csvText({headers: [dangerous], rows: [[dangerous]]});
    assert.equal(out, '\uFEFF"\''+dangerous+'"\r\n"\''+dangerous+'"');
  }
});
test("CSV streaming preserves quoted newlines and rejects incomplete/oversized reports", () => {
  const p = new CsvReportParser();for (const part of ['name,value\r', '\n"Juan, ', 'Cruz","line 1\n', 'line 2"\r\n']) p.push(part);
  assert.deepEqual(p.finish(), {headers:['name','value'],rows:[['Juan, Cruz','line 1\nline 2']]});
  const invalid = new CsvReportParser();invalid.push('header\n"unterminated');assert.throws(()=>invalid.finish(), /quoted/);
  const limited = new CsvReportParser(2);assert.throws(()=>limited.push('header\na\nb\n'), /records/);
});
