import { test } from "node:test";
import assert from "node:assert/strict";
import { previewDocument } from "../../src/lib/document-presentation.ts";
const fixture = {
  documentType: "vat_exempt_invoice",
  documentNumber: "UNSAVED-1",
  issueDate: "2026-10-16",
  seller: { name: "Synthetic seller", tin: "123456789", vatRegistered: true },
  buyer: { name: "Synthetic buyer" },
  items: [{ description: "Recorded service", quantity: 1, unitPrice: 100000, amount: 100000 }],
  totals: {
    gross: 100000,
    vatExemptSales: 100000,
    vatAmount: 0,
    discount: 0,
    withholdingTax: 0,
    otherCharges: 0,
    totalDue: 100000,
  },
  status: "validated",
  qrValue: "not-a-verification-link",
};
test("local validation remains a draft; centavos convert once and unknown data stays absent", () => {
  const d = previewDocument(fixture);
  assert.equal(d.record_status, "draft");
  assert.equal(d.amount, 1000);
  assert.equal(d.subtotal, 1000);
  assert.equal(d.vat, 0);
  assert.equal(d.items[0].unit_price, 1000);
  assert.equal(d.items[0].amount, 1000);
  assert.equal(d.payment_method, null);
  assert.equal(d.seller_business_style, null);
  assert.equal(d.verification_url, null);
  assert.equal(d.permit_number, null);
  assert.equal(d.vat_registered, true);
  assert.equal(d.bir_status, "not_verified");
});
test("withholding is not deducted from sales or net payable twice", () => {
  const d = previewDocument({
    ...fixture,
    totals: {
      ...fixture.totals,
      gross: 11200,
      discount: 0,
      vatableSales: 10000,
      vatAmount: 1200,
      withholdingTax: 200,
      totalDue: 11000,
    },
  });
  assert.equal(d.amount, 112);
  assert.equal(d.net_payable, 110);
  assert.equal(d.withholding, 2);
  assert.equal(d.balance_due, undefined);
});
test("missing lines, units, registration, and references are not invented", () => {
  const d = previewDocument({
    ...fixture,
    documentType: null,
    seller: { name: "Source seller" },
    items: [],
  });
  assert.equal(d.items.length, 0);
  assert.equal(d.document_type, "unclassified_document");
  assert.equal(d.vat_registered, null);
  assert.equal(d.reference, null);
  assert.equal(
    previewDocument({ ...fixture, seller: { name: "Recorded seller", vatRegistration: "Non-VAT" } })
      .vat_registered,
    false,
  );
  assert.equal(
    previewDocument({
      ...fixture,
      seller: { name: "Recorded seller", vatRegistration: "NUERS Business Account" },
    }).vat_registered,
    null,
  );
});
import { documentPageRanges } from "../../src/lib/document-pdf.ts";
test("PDF pagination keeps a crossing table row and footer section whole", () => {
  const blocks = [
    { top: 90, bottom: 110 },
    { top: 180, bottom: 220 },
    { top: 230, bottom: 260 },
  ];
  const pages = documentPageRanges(260, 100, blocks);
  assert.deepEqual(pages, [
    [0, 90],
    [90, 180],
    [180, 260],
  ]);
  for (const block of blocks)
    assert.ok(pages.some(([a, b]) => block.top >= a && block.bottom <= b));
});
test("PDF pagination covers long/oversized content without gaps or loops", () => {
  const pages = documentPageRanges(350, 100, [{ top: 0, bottom: 250 }]);
  assert.deepEqual(pages, [
    [0, 100],
    [100, 200],
    [200, 300],
    [300, 350],
  ]);
  assert.throws(() => documentPageRanges(0, 100, []));
  assert.throws(() => documentPageRanges(100, 0, []));
});

import { taxForDocument } from "../../src/lib/document-presentation.ts";
test("initial and additional lines honor the selected invoice tax classification", () => {
  assert.equal(taxForDocument("vat_exempt_invoice"), "vat_exempt");
  assert.equal(taxForDocument("zero_rated_invoice"), "zero_rated");
  assert.equal(taxForDocument("non_vat_invoice"), "non_vat");
  assert.equal(taxForDocument("vat_invoice"), "vatable");
});
