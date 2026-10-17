import { useEffect, useState } from "react";
import {
  Key, Plus, Copy, Eye, EyeOff, Trash2, RefreshCw,
  Activity, AlertTriangle, CheckCircle2, Code, Shield,
  BarChart3, Globe, Clock, Loader2, Database, Download,
  FileText, Terminal,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Progress } from "@/components/ui/progress";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from "@/components/ui/dialog";
import {
  ChartContainer, ChartTooltip, ChartTooltipContent,
} from "@/components/ui/chart";
import type { ChartConfig } from "@/components/ui/chart";
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, AreaChart, Area } from "recharts";
import { toast } from "sonner";
import { apiFetch, readJsonResponse } from "@/lib/api-url";
import { cn } from "@/lib/utils";

type ApiKey = {
  id: string;
  name: string;
  key_preview: string;
  plain_key?: string;
  env: "live" | "sandbox";
  created: string;
  last_used: string;
  requests_today: number;
  requests_month: number;
  rate_limit: number;
  active: boolean;
  status: string;
  permissions: string[];
  ip_whitelist: string[];
  branch_type: "main" | "branch";
  branch_code: string;
  branch_name: string;
  branch_location?: string | null;
};

type Webhook = {
  id: string;
  url: string;
  events: string[];
  status: string;
  deliveries: number;
  success_rate: number;
  last_delivery: string;
};

type ApiSummary = {
  active_keys: number;
  total_keys: number;
  requests_today: number;
  requests_month: number;
  active_webhooks: number;
  avg_latency_ms: number;
};

type ChartPoint = {
  hour: string;
  requests?: number;
  errors?: number;
  latency?: number;
};

type IntegrationInfo = {
  transaction_endpoint: string;
  auth_header: string;
  accepted_tax_types: string[];
};

const EMPTY_SUMMARY: ApiSummary = {
  active_keys: 0,
  total_keys: 0,
  requests_today: 0,
  requests_month: 0,
  active_webhooks: 0,
  avg_latency_ms: 0,
};

const API_TOKEN_KEY = "nuers_api_token";

function authHeaders() {
  const headers = new Headers({ Accept: "application/json" });

  try {
    const token = window.localStorage.getItem(API_TOKEN_KEY);
    if (token) headers.set("Authorization", `Bearer ${token}`);
  } catch {
    // Browser storage can be unavailable in restricted contexts.
  }

  return headers;
}

const examplePayload = {
  external_reference: "POS-MAIN-2026-000001",
  source_system: "servxbit-pos",
  schema_version:"NUERS-ingestion-1",source_event_id:"source-event-stable-001",event_type:"invoice.finalized",occurred_at:"2026-10-16T10:00:00+08:00",branch_code:"00000",
  channel: "pos",
  transaction_type: "sale",
  payment_method: "card",
  document_type: "sales_invoice",
  gross_amount: 11200,
  tax_type: "vatable",
  vat_rate: 12,
  vat_inclusive: true,
  customer_name: "ABC Trading Co.",
  customer_tin: "987-654-321-000",
  receipt_number: "POS-OR-20260602-000001",
  items: [
    {
      description: "Retail POS sale",
      quantity: 1,
      unit_price: 11200,
      tax_type: "vatable",
    },
  ],
};

const exampleResponse = {
  transaction_id: "9b4d8b5a-52a1-4f1e-b171-3f40697c4d11",
  receipt_id: "2ec8bc7d-9f9e-49aa-89e5-1d928f7e621a",
  receipt_number: "POS-OR-20260602-000001",
  merchant_id: "linked-to-api-key",
  branch: {
    type: "main",
    code: "MAIN",
    name: "Main Office",
  },
  document: {
    document_type: "vat_invoice",
    bir_template_code: "B1",
    label: "B1 VAT Invoice",
  },
  tax_classification: {
    tax_type: "vatable",
    vatable_sales: 10000,
    vat_exempt_sales: 0,
    zero_rated_sales: 0,
    vat_amount: 1200,
    gross_amount: 11200,
  },
};

const requiredFields = [
  "external_reference",
  "gross_amount or amount",
  "tax_type",
];

const optionalFields = [
  "source_system",
  "channel",
  "transaction_type",
  "payment_method",
  "document_type",
  "vat_rate",
  "vat_inclusive",
  "customer_name",
  "customer_tin",
  "branch_name",
  "branch_code",
  "branch_type",
  "receipt_number",
  "items",
];

const posIntegrationSteps = [
  {
    title: "Create a POS API key",
    body: "Create a live or sandbox key for each POS, branch, terminal group, billing system, or ERP source.",
  },
  {
    title: "Assign main or branch identity",
    body: "Select Main Office or Branch when creating the key. NUERS uses that key setting as the official branch owner.",
  },
  {
    title: "Store the secret in the POS backend",
    body: "Save the key in a server-side environment variable or vault. Never expose it in cashier browsers or public code.",
  },
  {
    title: "POST the sale payload",
    body: "Send external_reference, gross_amount or amount, tax_type, payment method, customer data, and line items.",
  },
  {
    title: "NUERS inserts records",
    body: "The API creates a Business Account transaction, an electronic receipt, VAT classification, and an API request log.",
  },
  {
    title: "Save NUERS IDs back to POS",
    body: "Store transaction_id, receipt_id, receipt_number, and document label for reprints, reconciliation, and audit trails.",
  },
];

type ApiGuideOptions = {
  endpoint: string;
  authHeader: string;
  taxTypes: string[];
};

function buildApiGuide({ endpoint, authHeader, taxTypes }: ApiGuideOptions) {
  const samplePayload = JSON.stringify(examplePayload, null, 2);
  const sampleResponse = JSON.stringify(exampleResponse, null, 2);

  return `# NUERS Business Account API Integration Guide

Version: 2026-06-03
Audience: POS, billing, accounting, e-commerce, ERP, and other external business systems

## 1. Purpose

The NUERS External Transaction API lets a Business Account connect its existing system to NUERS. Every accepted API request is inserted under the Business Account resolved from the API key, then stored as:

- a merchant transaction
- an API-generated electronic receipt
- a VAT/tax ledger entry
- an API request log for audit and rate-limit monitoring

The external system does not choose the Business Account. NUERS resolves it from the API key.

## 2. How a POS transaction is inserted into NUERS

1. The Business Account creates an API key in API Management.
2. NUERS stores the key as a secure hash and links it to the Business Account.
3. The POS stores the private key in its backend configuration or secret vault.
4. The cashier completes a sale in the POS.
5. The POS backend sends a POST request to NUERS with a stable external_reference, amount, tax_type, payment method, customer details, and line items.
6. NUERS validates the API key, checks permissions, checks IP restrictions, and applies the daily rate limit.
7. NUERS resolves the Business Account and branch from the API key.
8. NUERS computes VAT, selects the correct BIR document template, and inserts the transaction.
9. NUERS generates the electronic receipt record and returns transaction_id, receipt_id, receipt_number, branch, document, and VAT classification.
10. The POS saves those NUERS IDs in its own database for reconciliation, receipt reprints, voids, and audit review.

## 3. Where the record appears in the Business Account Portal

After a successful 201 response, the record appears in:

- Transactions: API sales and payment records from POS, accounting, billing, e-commerce, or ERP systems.
- Receipts: API-generated electronic official receipts and verification metadata.
- Tax Center: output VAT, VAT-exempt sales, zero-rated sales, non-VAT sales, and net tax position.
- B2B Tax Center: buyer/supplier matching when customer_tin is supplied.
- Branches and POS & Devices: branch performance based on the API key branch identity.
- API Keys: request logs, last-used timestamp, rate limit usage, and integration health.

## 4. Endpoint

Method: POST
Endpoint: ${endpoint}
Authentication header: ${authHeader}
Content-Type: application/json

Example headers:

\`\`\`http
${authHeader}: nuers_live_sk_your_private_key
Content-Type: application/json
Accept: application/json
\`\`\`

You may also send the API key as a Bearer token, but ${authHeader} is the recommended production header.

## 5. API key rules

- Create a separate API key per connected system, branch, terminal group, or environment.
- A live key writes real transactions.
- A sandbox key should be used for test traffic.
- A key must be active and must include transactions:write.
- If IP restrictions are configured, calls from other IP addresses are rejected.
- Daily rate limits are enforced per key.
- The API key determines whether the transaction belongs to the main office or a branch. NUERS prioritizes the branch identity configured on the key for audit consistency.

## 6. Required fields

| Field | Type | Rule |
| --- | --- | --- |
| external_reference | string | Required. Unique per Business Account. Use the POS receipt ID, invoice ID, or transaction ID. |
| gross_amount or amount | number | Required. Send one of these. |
| tax_type | string | Required. Accepted values: ${taxTypes.join(", ")} |

## 7. Optional fields

| Field | Type | Description |
| --- | --- | --- |
| source_system | string | Name of the source system, such as Servxbit Billing, POS, SAP, QuickBooks, or Oracle. |
| channel | string | Suggested values: api, pos, billing, accounting, ecommerce, erp, mobile. |
| transaction_type | string | Suggested values: sale, purchase, refund, void, service_invoice. |
| payment_method | string | Suggested values: cash, card, gcash, maya, online_banking, bank_transfer. |
| document_type | string | Optional. Suggested values: sales_invoice, payment_receipt, vat_invoice, non_vat_invoice, vat_exempt_invoice, zero_rated_invoice, mixed_sales_invoice. |
| vat_rate | number | Defaults to 12 when omitted. |
| vat_inclusive | boolean | Defaults to true. |
| customer_name | string | Buyer/customer name. |
| customer_tin | string | Buyer/customer TIN for B2B matching, expenses, and tax reconciliation. |
| branch_name, branch_code, branch_type | string | Optional source-system branch hints. NUERS still prioritizes the branch configured on the API key. |
| receipt_number | string | Optional external receipt number. NUERS generates one if omitted. |
| items | array | Optional line items for receipt details and audit review. |

## 8. Supported BIR document templates

NUERS resolves the official document template automatically when document_type is omitted or set to sales_invoice.

| API document type | NUERS/BIR template |
| --- | --- |
| vat_invoice | B1 VAT Invoice |
| non_vat_invoice | B2 Non-VAT Invoice |
| vat_exempt_invoice | B3 VAT-Exempt Sales Invoice |
| zero_rated_invoice | B4 Zero-Rated Sales Invoice |
| mixed_sales_invoice | B5 Mixed Sales Invoice |
| payment_receipt or official_receipt | B6 Payment Receipt |
| sales_invoice | Auto-resolved from tax_type and VAT registration |

## 9. VAT classification

Accepted tax_type values:

- vatable
- vat_exempt
- zero_rated
- non_vat

VAT computation:

- vatable + vat_inclusive true: NUERS treats gross_amount as VAT-inclusive.
- vatable + vat_inclusive false: NUERS treats gross_amount/amount as the VAT-exclusive base and adds VAT.
- zero_rated: gross amount is stored as zero-rated sales, VAT amount is 0.
- vat_exempt and non_vat: gross amount is stored as VAT-exempt/non-VAT sales, VAT amount is 0.

Example: gross_amount 11200, tax_type vatable, vat_rate 12, vat_inclusive true

- vatable_sales: 10000
- vat_amount: 1200
- gross_amount: 11200

## 10. Sample POS request body

\`\`\`json
${samplePayload}
\`\`\`

## 11. Sample success response

HTTP 201 Created

\`\`\`json
${sampleResponse}
\`\`\`

## 12. Duplicate/idempotent response

If the same external_reference is sent again for the same Business Account, NUERS does not create another transaction.

HTTP 200 OK

\`\`\`json
{
  "duplicate": true,
  "transaction_id": "existing-transaction-id",
  "message": "Transaction already exists for this Business Account."
}
\`\`\`

## 13. Common error responses

401 Missing or invalid API key

\`\`\`json
{
  "message": "Missing NUERS API key."
}
\`\`\`

403 Inactive key, missing permission, or blocked IP

\`\`\`json
{
  "message": "API key is missing transactions:write permission."
}
\`\`\`

422 Validation error

\`\`\`json
{
  "message": "The gross amount field is required when amount is not present.",
  "errors": {
    "gross_amount": [
      "The gross amount field is required when amount is not present."
    ]
  }
}
\`\`\`

429 Rate limit exceeded

\`\`\`json
{
  "message": "Daily API key rate limit exceeded."
}
\`\`\`

## 14. cURL example

\`\`\`bash
curl -X POST "${endpoint}" \\
  -H "${authHeader}: nuers_live_sk_your_private_key" \\
  -H "Content-Type: application/json" \\
  -H "Accept: application/json" \\
  -d '${samplePayload.replace(/'/g, "\\'")}'
\`\`\`

## 15. JavaScript fetch example

\`\`\`js
const response = await fetch("${endpoint}", {
  method: "POST",
  headers: {
    "${authHeader}": "nuers_live_sk_your_private_key",
    "Content-Type": "application/json",
    "Accept": "application/json",
  },
  body: JSON.stringify({
    external_reference: "POS-MAIN-2026-000001",
    source_system: "Servxbit POS",
    channel: "pos",
    transaction_type: "sale",
    payment_method: "card",
    document_type: "sales_invoice",
    gross_amount: 11200,
    tax_type: "vatable",
    vat_rate: 12,
    vat_inclusive: true,
    customer_name: "ABC Trading Co.",
    customer_tin: "987-654-321-000",
    items: [
      { description: "Retail POS sale", quantity: 1, unit_price: 11200, tax_type: "vatable" }
    ]
  }),
});

const payload = await response.json();

if (!response.ok) {
  throw new Error(payload.message || "NUERS API request failed");
}
\`\`\`

## 16. PHP cURL example

\`\`\`php
$payload = [
    'external_reference' => 'POS-MAIN-2026-000001',
    'source_system' => 'Servxbit POS',
    'channel' => 'pos',
    'transaction_type' => 'sale',
    'payment_method' => 'card',
    'document_type' => 'sales_invoice',
    'gross_amount' => 11200,
    'tax_type' => 'vatable',
    'vat_rate' => 12,
    'vat_inclusive' => true,
    'customer_name' => 'ABC Trading Co.',
    'customer_tin' => '987-654-321-000',
];

$ch = curl_init('${endpoint}');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        '${authHeader}: nuers_live_sk_your_private_key',
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
]);

$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
\`\`\`

## 17. Production integration checklist

1. Create one API key per connected system and branch.
2. Store the secret key in a vault or server-side environment variable.
3. Never expose the secret key in browsers, mobile apps, or client-side JavaScript.
4. Send stable external_reference values for idempotency.
5. Save transaction_id, receipt_id, receipt_number, document.label, and tax_classification back into the POS database.
6. Send customer_tin for B2B matching and expense reconciliation.
7. Confirm tax_type, document_type, vat_rate, and vat_inclusive settings with accounting.
8. Use sandbox keys for QA and live keys only after reconciliation testing.
9. Review API request logs, receipts, and transactions in the Business Account portal.
10. Configure IP restrictions before production traffic.
11. Rotate keys when personnel, vendors, or deployment environments change.

## 18. Data ownership and privacy

All accepted records are tenant-scoped by the API key's Business Account. A Business Account can only view records created under its own account. NUERS stores request metadata for audit, security, and compliance monitoring.
`;
}

const PDF_WIDTH = 612;
const PDF_HEIGHT = 792;
const PDF_MARGIN = 48;

const pdfColors = {
  navy: "0.023 0.129 0.231",
  blue: "0.000 0.392 0.682",
  gold: "0.839 0.655 0.220",
  teal: "0.055 0.541 0.565",
  ink: "0.055 0.094 0.145",
  muted: "0.365 0.412 0.467",
  border: "0.839 0.863 0.894",
  surface: "0.965 0.976 0.988",
  white: "1 1 1",
};

type PdfFont = "F1" | "F2" | "F3";

class PdfCanvas {
  readonly ops: string[] = [];

  rect(x: number, y: number, width: number, height: number, color: string) {
    this.ops.push(`q ${color} rg ${x} ${y} ${width} ${height} re f Q`);
  }

  strokeRect(x: number, y: number, width: number, height: number, color: string, lineWidth = 1) {
    this.ops.push(`q ${color} RG ${lineWidth} w ${x} ${y} ${width} ${height} re S Q`);
  }

  line(x1: number, y1: number, x2: number, y2: number, color: string, lineWidth = 1) {
    this.ops.push(`q ${color} RG ${lineWidth} w ${x1} ${y1} m ${x2} ${y2} l S Q`);
  }

  text(value: string, x: number, y: number, size: number, font: PdfFont = "F1", color = pdfColors.ink) {
    this.ops.push(`BT /${font} ${size} Tf ${color} rg ${x} ${y} Td (${escapePdfText(value)}) Tj ET`);
  }

  stream() {
    return this.ops.join("\n");
  }
}

function sanitizePdfText(value: string) {
  return value
    .replace(/₱/g, "PHP ")
    .replace(/[–—]/g, "-")
    .replace(/[“”]/g, '"')
    .replace(/[‘’]/g, "'")
    .replace(/•/g, "*")
    .replace(/[^\x09\x0A\x0D\x20-\x7E]/g, "");
}

function escapePdfText(value: string) {
  return sanitizePdfText(value)
    .replace(/\\/g, "\\\\")
    .replace(/\(/g, "\\(")
    .replace(/\)/g, "\\)");
}

function estimatePdfTextWidth(value: string, size: number, font: PdfFont) {
  const factor = font === "F3" ? 0.6 : font === "F2" ? 0.56 : 0.52;
  return sanitizePdfText(value).length * size * factor;
}

function wrapPdfText(value: string, maxWidth: number, size: number, font: PdfFont = "F1") {
  const words = sanitizePdfText(value).split(/\s+/).filter(Boolean);
  const lines: string[] = [];
  let current = "";

  words.forEach((word) => {
    const next = current ? `${current} ${word}` : word;
    if (estimatePdfTextWidth(next, size, font) <= maxWidth) {
      current = next;
      return;
    }
    if (current) lines.push(current);
    current = word;
  });

  if (current) lines.push(current);
  return lines.length ? lines : [""];
}

function wrapCodeLine(value: string, maxChars: number) {
  const clean = sanitizePdfText(value).replace(/\t/g, "  ");
  if (clean.length <= maxChars) return [clean];

  const lines: string[] = [];
  for (let index = 0; index < clean.length; index += maxChars) {
    lines.push(clean.slice(index, index + maxChars));
  }
  return lines;
}

function buildPdfFile(pages: PdfCanvas[]) {
  const pageObjectIds = pages.map((_, index) => 6 + index * 2);
  const kids = pageObjectIds.map((id) => `${id} 0 R`).join(" ");
  const objects: Array<{ id: number; body: string }> = [
    { id: 1, body: "<< /Type /Catalog /Pages 2 0 R >>" },
    { id: 2, body: `<< /Type /Pages /Kids [${kids}] /Count ${pages.length} >>` },
    { id: 3, body: "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>" },
    { id: 4, body: "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>" },
    { id: 5, body: "<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>" },
  ];

  pages.forEach((page, index) => {
    const pageId = 6 + index * 2;
    const streamId = pageId + 1;
    const stream = page.stream();
    objects.push({
      id: pageId,
      body: `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${PDF_WIDTH} ${PDF_HEIGHT}] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents ${streamId} 0 R >>`,
    });
    objects.push({
      id: streamId,
      body: `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`,
    });
  });

  let pdf = "%PDF-1.4\n";
  const offsets = [0];

  objects.sort((a, b) => a.id - b.id).forEach((object) => {
    offsets[object.id] = pdf.length;
    pdf += `${object.id} 0 obj\n${object.body}\nendobj\n`;
  });

  const xrefOffset = pdf.length;
  pdf += `xref\n0 ${objects.length + 1}\n`;
  pdf += "0000000000 65535 f \n";
  for (let id = 1; id <= objects.length; id += 1) {
    pdf += `${String(offsets[id]).padStart(10, "0")} 00000 n \n`;
  }
  pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`;

  return new TextEncoder().encode(pdf);
}

function drawNuersHeader(page: PdfCanvas, pageNumber: number) {
  page.rect(0, PDF_HEIGHT - 74, PDF_WIDTH, 74, pdfColors.navy);
  page.rect(0, PDF_HEIGHT - 78, PDF_WIDTH, 4, pdfColors.gold);
  page.strokeRect(PDF_MARGIN, PDF_HEIGHT - 58, 34, 34, pdfColors.gold, 1.5);
  page.text("N", PDF_MARGIN + 9, PDF_HEIGHT - 50, 20, "F2", pdfColors.white);
  page.text("NUERS", PDF_MARGIN + 44, PDF_HEIGHT - 41, 18, "F2", pdfColors.white);
  page.text("National Unified Electronic Receipt System", PDF_MARGIN + 44, PDF_HEIGHT - 56, 8.5, "F1", pdfColors.white);
  page.text(`Business Account API Guide | Page ${pageNumber}`, PDF_WIDTH - 218, PDF_HEIGHT - 46, 8, "F1", pdfColors.white);
}

function drawNuersFooter(page: PdfCanvas, pageNumber: number) {
  page.line(PDF_MARGIN, 42, PDF_WIDTH - PDF_MARGIN, 42, pdfColors.border, 0.8);
  page.text("NUERS Confidential Integration Guide - For authorized Business Account technical teams", PDF_MARGIN, 28, 7.5, "F1", pdfColors.muted);
  page.text(String(pageNumber), PDF_WIDTH - PDF_MARGIN - 8, 28, 7.5, "F2", pdfColors.muted);
}

function addSectionTitle(page: PdfCanvas, title: string, y: number) {
  page.rect(PDF_MARGIN, y - 5, 5, 22, pdfColors.gold);
  page.text(title, PDF_MARGIN + 14, y, 15, "F2", pdfColors.ink);
}

function addParagraph(page: PdfCanvas, text: string, x: number, y: number, width: number, size = 9.5) {
  let nextY = y;
  wrapPdfText(text, width, size).forEach((line) => {
    page.text(line, x, nextY, size, "F1", pdfColors.muted);
    nextY -= size + 4;
  });
  return nextY;
}

function addBullet(page: PdfCanvas, text: string, x: number, y: number, width: number) {
  page.rect(x, y + 2, 4, 4, pdfColors.teal);
  return addParagraph(page, text, x + 12, y, width - 12, 8.7) - 2;
}

function addCodeBlock(page: PdfCanvas, code: string, x: number, y: number, width: number, height: number) {
  page.rect(x, y - height, width, height, "0.028 0.039 0.071");
  page.strokeRect(x, y - height, width, height, "0.145 0.176 0.231", 0.8);

  const maxChars = Math.floor((width - 22) / (7.1 * 0.6));
  const lines = code.split("\n").flatMap((line) => wrapCodeLine(line, maxChars));
  let lineY = y - 18;

  lines.slice(0, Math.floor((height - 24) / 10)).forEach((line) => {
    page.text(line, x + 11, lineY, 7.1, "F3", pdfColors.white);
    lineY -= 10;
  });
}

function addInfoCard(page: PdfCanvas, title: string, body: string, x: number, y: number, width: number, accent = pdfColors.blue) {
  page.rect(x, y - 74, width, 74, pdfColors.surface);
  page.strokeRect(x, y - 74, width, 74, pdfColors.border, 0.8);
  page.rect(x, y - 74, 4, 74, accent);
  page.text(title, x + 14, y - 22, 9, "F2", pdfColors.ink);
  addParagraph(page, body, x + 14, y - 38, width - 24, 7.5);
}

function buildApiGuidePdf(options: ApiGuideOptions) {
  const canonicalGuide = buildApiGuide(options);
  const payloadJson = JSON.stringify(examplePayload, null, 2);
  const responseJson = JSON.stringify(exampleResponse, null, 2);
  const pages: PdfCanvas[] = [];

  const cover = new PdfCanvas();
  cover.rect(0, 0, PDF_WIDTH, PDF_HEIGHT, pdfColors.navy);
  cover.rect(0, 0, PDF_WIDTH, 16, pdfColors.gold);
  cover.rect(0, PDF_HEIGHT - 18, PDF_WIDTH, 18, pdfColors.gold);
  cover.strokeRect(PDF_MARGIN, PDF_HEIGHT - 170, 82, 82, pdfColors.gold, 2);
  cover.text("N", PDF_MARGIN + 23, PDF_HEIGHT - 142, 38, "F2", pdfColors.white);
  cover.text("NUERS", PDF_MARGIN + 104, PDF_HEIGHT - 112, 36, "F2", pdfColors.white);
  cover.text("National Unified Electronic Receipt System", PDF_MARGIN + 108, PDF_HEIGHT - 134, 12, "F1", pdfColors.white);
  cover.text("Business Account External Transaction API", PDF_MARGIN, PDF_HEIGHT - 236, 24, "F2", pdfColors.white);
  cover.text("Enterprise Integration Guide", PDF_MARGIN, PDF_HEIGHT - 266, 24, "F2", pdfColors.gold);
  addParagraph(
    cover,
    "Production-ready documentation for connecting POS, accounting, billing, e-commerce, ERP, and branch systems to the NUERS Business Account Portal.",
    PDF_MARGIN,
    PDF_HEIGHT - 305,
    440,
    11,
  );
  addInfoCard(cover, "Endpoint", options.endpoint, PDF_MARGIN, 270, 246, pdfColors.teal);
  addInfoCard(cover, "Authentication", `${options.authHeader} + server-side secret key`, PDF_MARGIN + 266, 270, 246, pdfColors.gold);
  cover.text("Prepared for authorized NUERS Business Account integration teams", PDF_MARGIN, 112, 9, "F1", pdfColors.white);
  cover.text(`PDF Edition - ${new Date().toLocaleDateString("en-PH", { year: "numeric", month: "long", day: "numeric" })}`, PDF_MARGIN, 94, 8, "F1", pdfColors.white);
  pages.push(cover);

  const overview = new PdfCanvas();
  drawNuersHeader(overview, 2);
  addSectionTitle(overview, "1. Integration Overview", 690);
  let y = addParagraph(
    overview,
    "The NUERS External Transaction API connects existing business systems to the Business Account Portal. Every accepted request is tenant-scoped by the API key, inserted as a transaction, recorded as an electronic receipt, computed for VAT classification, and logged for audit monitoring.",
    PDF_MARGIN,
    660,
    500,
  );
  y -= 10;
  addInfoCard(overview, "Tenant ownership", "The external system does not choose the Business Account. NUERS resolves ownership from the API key.", PDF_MARGIN, y, 160, pdfColors.blue);
  addInfoCard(overview, "Branch attribution", "The key determines whether records belong to the main office or a registered branch.", PDF_MARGIN + 176, y, 160, pdfColors.teal);
  addInfoCard(overview, "Tax intelligence", "Accepted records feed VAT ledgers, B2B matching, receipts, and API logs.", PDF_MARGIN + 352, y, 160, pdfColors.gold);
  y -= 110;
  addSectionTitle(overview, "2. Endpoint And Security", y);
  y -= 32;
  overview.text("Method", PDF_MARGIN, y, 8, "F2", pdfColors.muted);
  overview.text("POST", PDF_MARGIN + 90, y, 9, "F3", pdfColors.ink);
  y -= 20;
  overview.text("Endpoint", PDF_MARGIN, y, 8, "F2", pdfColors.muted);
  overview.text(options.endpoint, PDF_MARGIN + 90, y, 8.5, "F3", pdfColors.ink);
  y -= 20;
  overview.text("Auth Header", PDF_MARGIN, y, 8, "F2", pdfColors.muted);
  overview.text(options.authHeader, PDF_MARGIN + 90, y, 8.5, "F3", pdfColors.ink);
  y -= 34;
  [
    "Create one API key per connected system, branch, terminal group, and environment.",
    "Store secret keys only in server-side vaults or environment variables.",
    "Use sandbox keys for QA and live keys only for real production transactions.",
    "Configure IP restrictions and rotate keys when vendors, staff, or deployment environments change.",
  ].forEach((item) => {
    y = addBullet(overview, item, PDF_MARGIN, y, 500);
  });
  drawNuersFooter(overview, 2);
  pages.push(overview);

  const contract = new PdfCanvas();
  drawNuersHeader(contract, 3);
  addSectionTitle(contract, "3. Request Contract", 690);
  contract.text("Required Fields", PDF_MARGIN, 650, 11, "F2", pdfColors.ink);
  y = 626;
  [
    ["external_reference", "Required. Unique per Business Account. Used for idempotency."],
    ["gross_amount or amount", "Required. Numeric amount sent by the source system."],
    ["tax_type", `Required. Accepted values: ${options.taxTypes.join(", ")}.`],
  ].forEach(([field, rule]) => {
    contract.rect(PDF_MARGIN, y - 25, 500, 30, pdfColors.surface);
    contract.strokeRect(PDF_MARGIN, y - 25, 500, 30, pdfColors.border, 0.5);
    contract.text(field, PDF_MARGIN + 12, y - 8, 8, "F3", pdfColors.ink);
    contract.text(rule, PDF_MARGIN + 170, y - 8, 8, "F1", pdfColors.muted);
    y -= 34;
  });
  contract.text("Optional Fields", PDF_MARGIN, y - 10, 11, "F2", pdfColors.ink);
  y -= 36;
  [
    "source_system, channel, transaction_type, payment_method",
    "document_type, vat_rate, vat_inclusive, customer_name, customer_tin",
    "branch hints, receipt_number, items, line quantities, unit prices, and item tax types",
  ].forEach((item) => {
    y = addBullet(contract, item, PDF_MARGIN, y, 500);
  });
  y -= 12;
  addSectionTitle(contract, "4. VAT Classification", y);
  y -= 34;
  [
    ["vatable", "Computes output VAT. Inclusive requests split gross into sales base and VAT."],
    ["vat_exempt", "Stores the amount as VAT-exempt sales with zero VAT amount."],
    ["zero_rated", "Stores the amount as zero-rated sales with zero VAT amount."],
    ["non_vat", "Stores the amount as non-VAT sales for non-VAT registered operations."],
  ].forEach(([label, body]) => {
    contract.text(label, PDF_MARGIN, y, 8.5, "F2", pdfColors.ink);
    y = addParagraph(contract, body, PDF_MARGIN + 84, y, 410, 8.2);
    y -= 5;
  });
  drawNuersFooter(contract, 3);
  pages.push(contract);

  const requestPage = new PdfCanvas();
  drawNuersHeader(requestPage, 4);
  addSectionTitle(requestPage, "5. Sample POS Request Body", 690);
  addParagraph(requestPage, "Send this JSON to the transaction endpoint. NUERS computes tax, links the record to the Business Account through the API key, and generates an API electronic receipt.", PDF_MARGIN, 660, 500, 9);
  addCodeBlock(requestPage, payloadJson, PDF_MARGIN, 610, 500, 460);
  drawNuersFooter(requestPage, 4);
  pages.push(requestPage);

  const responsePage = new PdfCanvas();
  drawNuersHeader(responsePage, 5);
  addSectionTitle(responsePage, "6. Success Response", 690);
  responsePage.text("HTTP 201 Created", PDF_MARGIN, 660, 9, "F2", pdfColors.teal);
  addCodeBlock(responsePage, responseJson, PDF_MARGIN, 640, 500, 275);
  addSectionTitle(responsePage, "7. Common Errors", 330);
  y = 298;
  [
    ["401", "Missing or invalid API key."],
    ["403", "Inactive key, missing permission, or blocked IP address."],
    ["422", "Validation error, such as missing gross_amount or invalid tax_type."],
    ["429", "Daily API key rate limit exceeded."],
  ].forEach(([code, body]) => {
    responsePage.rect(PDF_MARGIN, y - 22, 500, 28, pdfColors.surface);
    responsePage.strokeRect(PDF_MARGIN, y - 22, 500, 28, pdfColors.border, 0.5);
    responsePage.text(code, PDF_MARGIN + 12, y - 7, 9, "F2", pdfColors.navy);
    responsePage.text(body, PDF_MARGIN + 70, y - 7, 8.5, "F1", pdfColors.muted);
    y -= 34;
  });
  drawNuersFooter(responsePage, 5);
  pages.push(responsePage);

  const tutorial = new PdfCanvas();
  drawNuersHeader(tutorial, 6);
  addSectionTitle(tutorial, "8. POS Implementation Tutorial", 690);
  y = 656;
  posIntegrationSteps.map((step, index) => `${index + 1}. ${step.title}: ${step.body}`).forEach((item) => {
    y = addBullet(tutorial, item, PDF_MARGIN, y, 500);
  });
  y = addBullet(tutorial, "After HTTP 201 Created, save transaction_id, receipt_id, receipt_number, document.label, and tax_classification back into the POS database.", PDF_MARGIN, y, 500);
  y = addBullet(tutorial, "On retries, reuse the same external_reference so NUERS returns the existing transaction instead of creating a duplicate.", PDF_MARGIN, y, 500);
  y -= 10;
  tutorial.text("cURL Quick Start", PDF_MARGIN, y, 11, "F2", pdfColors.ink);
  y -= 16;
  addCodeBlock(
    tutorial,
    `curl -X POST "${options.endpoint}" \\\n  -H "${options.authHeader}: nuers_live_sk_your_private_key" \\\n  -H "Content-Type: application/json" \\\n  -H "Accept: application/json" \\\n  -d '${JSON.stringify(examplePayload)}'`,
    PDF_MARGIN,
    y,
    500,
    145,
  );
  y -= 178;
  addInfoCard(tutorial, "Canonical source", `This PDF is generated from the ${canonicalGuide.split("\n").length}-line NUERS Business Account API guide embedded in the portal.`, PDF_MARGIN, y, 244, pdfColors.blue);
  addInfoCard(tutorial, "Production rule", "Never expose live keys in browser code, mobile apps, public repositories, or client-side JavaScript.", PDF_MARGIN + 268, y, 244, pdfColors.gold);
  drawNuersFooter(tutorial, 6);
  pages.push(tutorial);

  return buildPdfFile(pages);
}

function copyWithSelectionFallback(value: string) {
  let copiedByEvent = false;
  const copyListener = (event: ClipboardEvent) => {
    event.clipboardData?.setData("text/plain", value);
    event.preventDefault();
    copiedByEvent = true;
  };
  const textarea = document.createElement("textarea");

  document.addEventListener("copy", copyListener);
  textarea.value = value;
  textarea.setAttribute("readonly", "true");
  textarea.style.position = "fixed";
  textarea.style.left = "0";
  textarea.style.top = "0";
  textarea.style.opacity = "0";
  document.body.appendChild(textarea);
  window.focus();
  textarea.focus({ preventScroll: true });
  textarea.select();
  textarea.setSelectionRange(0, textarea.value.length);

  try {
    const copiedByCommand = document.execCommand("copy");
    return copiedByCommand || copiedByEvent;
  } finally {
    document.removeEventListener("copy", copyListener);
    textarea.remove();
  }
}

async function copyTextToClipboard(value: string) {
  if (copyWithSelectionFallback(value)) return;

  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(value);
    return;
  }

  throw new Error("Clipboard copy failed.");
}

function maskApiKey(value: string) {
  if (!value) return "";
  if (value.includes("•") || value.includes("*")) return value;
  if (value.length <= 18) return value;

  return `${value.slice(0, 18)}••••••••••••${value.slice(-4)}`;
}

function downloadPdfFile(filename: string, bytes: Uint8Array) {
  const arrayBuffer = new ArrayBuffer(bytes.byteLength);
  new Uint8Array(arrayBuffer).set(bytes);
  const blob = new Blob([arrayBuffer], { type: "application/pdf" });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");

  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 0);
}

const requestConfig: ChartConfig = {
  requests: { label: "Requests", color: "var(--chart-1)" },
  errors: { label: "Errors", color: "var(--chart-3)" },
};

const latencyConfig: ChartConfig = {
  latency: { label: "Avg Latency (ms)", color: "var(--chart-2)" },
};

const COPY_FEEDBACK_TOAST_ID = "api-keys-copy-feedback";

export function MerchantApiKeys() {
  const [keys, setKeys] = useState<ApiKey[]>([]);
  const [webhooks, setWebhooks] = useState<Webhook[]>([]);
  const [hourlyRequests, setHourlyRequests] = useState<ChartPoint[]>([]);
  const [latencyData, setLatencyData] = useState<ChartPoint[]>([]);
  const [summary, setSummary] = useState<ApiSummary>(EMPTY_SUMMARY);
  const [integration, setIntegration] = useState<IntegrationInfo | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [newlyCreatedKey, setNewlyCreatedKey] = useState<ApiKey | null>(null);
  const [showKey, setShowKey] = useState<string | null>(null);
  const [addOpen, setAddOpen] = useState(false);
  const [newKeyName, setNewKeyName] = useState("");
  const [newKeyEnv, setNewKeyEnv] = useState<"live" | "sandbox">("live");
  const [newBranchType, setNewBranchType] = useState<"main" | "branch">("main");
  const [newBranchName, setNewBranchName] = useState("Main Office");
  const [newBranchCode, setNewBranchCode] = useState("");
  const [newBranchLocation, setNewBranchLocation] = useState("");
  const [selectedKey, setSelectedKey] = useState<ApiKey | null>(null);

  async function loadApiKeys() {
    setLoading(true);
    setError("");

    try {
      const response = await apiFetch("/api/merchant/api-keys", {
        headers: authHeaders(),
        cache: "no-store",
      });
      const payload = await readJsonResponse<{
        keys?: ApiKey[];
        webhooks?: Webhook[];
        hourly_requests?: ChartPoint[];
        latency_data?: ChartPoint[];
        summary?: ApiSummary;
        integration?: IntegrationInfo | null;
      }>(response, "Unable to load API keys from the database.");

      setKeys(payload.keys ?? []);
      setWebhooks(payload.webhooks ?? []);
      setHourlyRequests(payload.hourly_requests ?? []);
      setLatencyData(payload.latency_data ?? []);
      setSummary(payload.summary ?? EMPTY_SUMMARY);
      setIntegration(payload.integration ?? null);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to load API keys from the database.");
      setKeys([]);
      setWebhooks([]);
      setHourlyRequests([]);
      setLatencyData([]);
      setSummary(EMPTY_SUMMARY);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadApiKeys();
  }, []);

  async function handleCopy(value: string | null | undefined, message = "Copied to clipboard.") {
    const copyValue = value?.trim();

    if (!copyValue) {
      toast.error("Nothing to copy.", { id: COPY_FEEDBACK_TOAST_ID });
      return;
    }

    try {
      await copyTextToClipboard(copyValue);
      toast.success(message, { id: COPY_FEEDBACK_TOAST_ID });
    } catch {
      toast.error("Copy failed. Please select and copy the value manually.", { id: COPY_FEEDBACK_TOAST_ID });
    }
  }

  function getOneTimeSecret(key: ApiKey) {
    return key.plain_key ?? (newlyCreatedKey?.id === key.id ? newlyCreatedKey.plain_key : undefined);
  }

  function getApiKeyCopyValue(key: ApiKey) {
    return getOneTimeSecret(key) ?? key.key_preview;
  }

  function getApiKeyCopyMessage(key: ApiKey) {
    return getOneTimeSecret(key)
      ? "API secret key copied to clipboard."
      : "API key preview copied. Full secret is available only immediately after creation.";
  }

  function toggleApiKeyVisibility(key: ApiKey) {
    const willShow = showKey !== key.id;

    setShowKey(willShow ? key.id : null);

    if (willShow && !getOneTimeSecret(key)) {
      toast.success("API key preview shown. Full secret is available only immediately after creation.");
    }
  }

  function currentGuideOptions(): ApiGuideOptions {
    return {
      endpoint: integration?.transaction_endpoint ?? "/api/integrations/transactions",
      authHeader: integration?.auth_header ?? "X-NUERS-API-Key",
      taxTypes: integration?.accepted_tax_types ?? ["vatable", "vat_exempt", "zero_rated", "non_vat"],
    };
  }

  function handleDownloadApiGuide() {
    try {
      downloadPdfFile("nuers-business-account-api-guide.pdf", buildApiGuidePdf(currentGuideOptions()));
      toast.success("NUERS API integration PDF downloaded.");
    } catch {
      toast.error("Download failed. Please try again.");
    }
  }

  function handleCopyCurlExample() {
    const { endpoint, authHeader } = currentGuideOptions();
    const payload = JSON.stringify(examplePayload, null, 2).replace(/'/g, "\\'");

    void handleCopy(
      `curl -X POST "${endpoint}" \\\n  -H "${authHeader}: nuers_live_sk_your_private_key" \\\n  -H "Content-Type: application/json" \\\n  -H "Accept: application/json" \\\n  -d '${payload}'`,
      "cURL example copied.",
    );
  }

  async function handleRevoke(id: string) {
    setBusy(true);

    try {
      const response = await apiFetch(`/api/merchant/api-keys/${id}`, {
        method: "DELETE",
        headers: authHeaders(),
      });
      await readJsonResponse(response, "Unable to revoke API key.");

      toast.success("API key revoked.");
      loadApiKeys();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Unable to revoke API key.");
    } finally {
      setBusy(false);
    }
  }

  async function handleToggle(id: string, val: boolean) {
    setBusy(true);

    try {
      const headers = authHeaders();
      headers.set("Content-Type", "application/json");

      const response = await apiFetch(`/api/merchant/api-keys/${id}`, {
        method: "PATCH",
        headers,
        body: JSON.stringify({ active: val }),
      });
      await readJsonResponse(response, "Unable to update API key.");

      toast.success(val ? "Key activated." : "Key deactivated.");
      loadApiKeys();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Unable to update API key.");
    } finally {
      setBusy(false);
    }
  }

  async function handleCreate() {
    if (!newKeyName.trim()) return;
    setBusy(true);

    try {
      const headers = authHeaders();
      headers.set("Content-Type", "application/json");

      const response = await apiFetch("/api/merchant/api-keys", {
        method: "POST",
        headers,
        body: JSON.stringify({
          name: newKeyName,
          environment: newKeyEnv,
          branch_type: newBranchType,
          branch_name: newBranchType === "main" ? "Main Office" : newBranchName,
          branch_code: newBranchCode,
          branch_location: newBranchLocation || null,
          scopes: ["transactions:write", "transactions:read", "receipts:write", "receipts:read", "reports:read"],
          rate_limit_per_day: newKeyEnv === "live" ? 50000 : 5000,
        }),
      });
      const payload = await readJsonResponse<{ key: ApiKey }>(response, "Unable to create API key.");

      setNewlyCreatedKey(payload.key);
      toast.success(`API key "${newKeyName}" created. Copy it now.`);
      setNewKeyName("");
      setNewBranchType("main");
      setNewBranchName("Main Office");
      setNewBranchCode("");
      setNewBranchLocation("");
      await loadApiKeys();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Unable to create API key.");
    } finally {
      setBusy(false);
    }
  }

  const totalRequests = summary.requests_today;
  const activeCount = summary.active_keys;
  const totalMonthly = summary.requests_month;
  const guideOptions = currentGuideOptions();
  const selectedOneTimeSecret = selectedKey ? getOneTimeSecret(selectedKey) : undefined;
  const selectedKeyCopyValue = selectedKey ? getApiKeyCopyValue(selectedKey) : "";
  const selectedKeyDisplay = selectedKey
    ? showKey === selectedKey.id
      ? selectedKeyCopyValue
      : maskApiKey(selectedKeyCopyValue)
    : "";

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-foreground">API Management</h1>
          <p className="text-sm text-muted-foreground">{activeCount} active keys · {totalRequests.toLocaleString()} requests today</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" size="sm" className="gap-2" onClick={handleDownloadApiGuide} aria-label="Download API guide PDF">
            <Download className="h-4 w-4" /> PDF Guide
          </Button>
          <Button size="sm" className="gap-2" onClick={() => setAddOpen(true)} aria-label="Create new API key">
            <Plus className="h-4 w-4" /> New API Key
          </Button>
        </div>
      </div>

      <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-slate-800">Production intake uses <code>NUERS-ingestion-1</code>: provide a stable source event ID, <code>invoice.finalized</code> or <code>payment.recorded</code>, original timezone-aware event time, source system identifier and verified five-digit branch. Source numbers must match the authorized series. Retrying the same event returns its saved resource and status URL. Examples below describe the internal NUERS contract; BIR technical mapping remains pending. Existing keys without expiry must be replaced.</div>
      {/* KPI */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[
          { label: "Active Keys", value: activeCount.toString(), icon: Key, accent: "border-l-success", sub: `${keys.length} total` },
          { label: "Requests Today", value: totalRequests.toLocaleString(), icon: Activity, accent: "border-l-primary", sub: `${totalMonthly.toLocaleString()} this month` },
          { label: "Active Webhooks", value: summary.active_webhooks.toString(), icon: Code, accent: "border-l-chart-3", sub: "event-driven" },
          { label: "Avg Latency", value: `${summary.avg_latency_ms}ms`, icon: CheckCircle2, accent: "border-l-chart-1", sub: "API request logs" },
        ].map((s) => (
          <Card key={s.label} className={`border-l-4 ${s.accent}`}>
            <CardContent className="p-4">
              <div className="flex items-center justify-between mb-1">
                <span className="text-xs text-muted-foreground">{s.label}</span>
                <s.icon className="h-4 w-4 text-muted-foreground" />
              </div>
              <p className="text-xl font-bold text-foreground">{s.value}</p>
              <p className="text-[10px] text-muted-foreground mt-0.5">{s.sub}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      {error && (
        <Card className="border-destructive/30 bg-destructive/5">
          <CardContent className="flex items-center justify-between gap-3 p-4">
            <div className="flex items-center gap-2 text-sm text-destructive">
              <AlertTriangle className="h-4 w-4" />
              {error}
            </div>
            <Button variant="outline" size="sm" onClick={loadApiKeys}>Retry</Button>
          </CardContent>
        </Card>
      )}

      <Tabs defaultValue="keys">
        <TabsList>
          <TabsTrigger value="keys" className="gap-2">API Keys <Badge variant="secondary" className="text-[10px] h-4">{keys.length}</Badge></TabsTrigger>
          <TabsTrigger value="webhooks" className="gap-2">Webhooks <Badge variant="secondary" className="text-[10px] h-4">{webhooks.length}</Badge></TabsTrigger>
          <TabsTrigger value="analytics"><BarChart3 className="h-3.5 w-3.5 mr-1.5" />Analytics</TabsTrigger>
        </TabsList>

        {/* API Keys */}
        <TabsContent value="keys" className="mt-4 space-y-4">
          <Card className="border-primary/20 bg-primary/5">
            <CardContent className="p-4">
              <div className="flex items-start justify-between gap-4 flex-wrap">
                <div>
                  <p className="text-sm font-semibold text-foreground">External Transaction API</p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Use this endpoint from POS, accounting, billing, e-commerce, or ERP systems. NUERS resolves the Business Account from the API key and stores every accepted payload as that account's transaction and electronic receipt.
                  </p>
                </div>
                <Badge variant="outline" className="font-mono text-[10px]">
                  {guideOptions.authHeader}
                </Badge>
              </div>
              <div className="mt-3 grid gap-3 lg:grid-cols-2">
                <div className="rounded-lg bg-background/70 p-3">
                  <p className="text-[10px] uppercase tracking-wide text-muted-foreground">Endpoint</p>
                  <code className="mt-1 block break-all text-xs text-foreground">{guideOptions.endpoint}</code>
                </div>
                <div className="rounded-lg bg-background/70 p-3">
                  <p className="text-[10px] uppercase tracking-wide text-muted-foreground">VAT Classification</p>
                  <p className="mt-1 text-xs text-foreground">{guideOptions.taxTypes.join(", ")}</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-4">
              <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex items-start gap-3">
                  <div className="rounded-lg bg-primary/10 p-2 text-primary">
                    <FileText className="h-4 w-4" />
                  </div>
                  <div>
                    <p className="text-sm font-semibold text-foreground">Complete integration documentation</p>
                    <p className="mt-1 max-w-3xl text-xs text-muted-foreground">
                      Use this as the implementation guide for connecting your own POS, accounting, billing, e-commerce, or ERP system. It explains how a sale is posted, how NUERS resolves the Business Account from the key, how VAT is computed, and where the transaction and receipt appear in the portal.
                    </p>
                  </div>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button variant="outline" size="sm" className="gap-2" onClick={handleCopyCurlExample} aria-label="Copy cURL API example">
                    <Terminal className="h-3.5 w-3.5" /> Copy cURL
                  </Button>
                  <Button size="sm" className="gap-2" onClick={handleDownloadApiGuide} aria-label="Download complete API guide PDF">
                    <Download className="h-3.5 w-3.5" /> Download PDF Guide
                  </Button>
                </div>
              </div>
              <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {posIntegrationSteps.map((step, index) => (
                  <div key={step.title} className="rounded-lg border bg-muted/20 p-3">
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Step {index + 1}</p>
                    <p className="mt-1 text-xs font-semibold text-foreground">{step.title}</p>
                    <p className="mt-1 text-[11px] leading-relaxed text-muted-foreground">{step.body}</p>
                  </div>
                ))}
              </div>
              <div className="mt-4 rounded-xl border border-primary/15 bg-primary/5 p-3">
                <p className="text-xs font-semibold text-foreground">What NUERS inserts after a successful POS request</p>
                <div className="mt-3 grid gap-2 text-[11px] text-muted-foreground md:grid-cols-2 xl:grid-cols-4">
                  <div className="rounded-lg bg-background/80 p-2">
                    <p className="font-semibold text-foreground">Transaction ledger</p>
                    <p>Stored under the API key&apos;s Business Account with amount, VAT, customer, branch, RDO, and source system.</p>
                  </div>
                  <div className="rounded-lg bg-background/80 p-2">
                    <p className="font-semibold text-foreground">Electronic receipt</p>
                    <p>Generated as a NUERS receipt with QR/verifiable data, BIR template, buyer details, and item lines.</p>
                  </div>
                  <div className="rounded-lg bg-background/80 p-2">
                    <p className="font-semibold text-foreground">Tax intelligence</p>
                    <p>Feeds Tax Center, output VAT, B2B matching, customer TIN matching, and reconciliation dashboards.</p>
                  </div>
                  <div className="rounded-lg bg-background/80 p-2">
                    <p className="font-semibold text-foreground">API audit log</p>
                    <p>Tracks request status, latency, source system, branch identity, IP, and rate-limit usage.</p>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card className="border-chart-3/20">
            <CardHeader className="pb-3">
              <div className="flex items-start justify-between gap-3 flex-wrap">
                <div>
                  <CardTitle className="flex items-center gap-2 text-sm">
                    <Code className="h-4 w-4 text-primary" />
                    Sample JSON for POS, Billing, Accounting, or ERP
                  </CardTitle>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Send this request body to the transaction endpoint. NUERS computes VAT, links the record to this Business Account through the API key, and generates an API electronic receipt.
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    className="gap-2"
                    onClick={() => void handleCopy(JSON.stringify(examplePayload, null, 2), "Sample JSON copied.")}
                    aria-label="Copy sample JSON payload"
                  >
                    <Copy className="h-3.5 w-3.5" /> Copy JSON
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    className="gap-2"
                    onClick={() => void handleCopy(`POST ${guideOptions.endpoint}`, "Integration endpoint copied.")}
                    aria-label="Copy integration endpoint"
                  >
                    <Copy className="h-3.5 w-3.5" /> Copy Endpoint
                  </Button>
                </div>
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid gap-3 lg:grid-cols-3">
                <div className="rounded-lg border bg-muted/20 p-3">
                  <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Method</p>
                  <p className="mt-1 font-mono text-sm font-semibold text-foreground">POST</p>
                </div>
                <div className="rounded-lg border bg-muted/20 p-3 lg:col-span-2">
                  <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Headers</p>
                  <div className="mt-1 space-y-1 font-mono text-xs text-foreground">
                    <p>{guideOptions.authHeader}: nuers_live_sk_xxxxxxxxxxxxx</p>
                    <p>Content-Type: application/json</p>
                  </div>
                </div>
              </div>

              <div className="grid gap-4 xl:grid-cols-2">
                <div className="space-y-2">
                  <div className="flex items-center justify-between gap-2">
                    <p className="text-xs font-semibold text-foreground">Request Body</p>
                    <Badge variant="secondary" className="text-[10px]">Creates transaction and receipt</Badge>
                  </div>
                  <pre className="max-h-[420px] overflow-auto rounded-xl border bg-slate-950 p-4 text-xs leading-relaxed text-slate-100">
{JSON.stringify(examplePayload, null, 2)}
                  </pre>
                </div>
                <div className="space-y-2">
                  <div className="flex items-center justify-between gap-2">
                    <p className="text-xs font-semibold text-foreground">Success Response</p>
                    <Badge variant="outline" className="text-[10px]">201 Created</Badge>
                  </div>
                  <pre className="max-h-[420px] overflow-auto rounded-xl border bg-muted/40 p-4 text-xs leading-relaxed text-foreground">
{JSON.stringify(exampleResponse, null, 2)}
                  </pre>
                </div>
              </div>

              <div className="grid gap-3 lg:grid-cols-2">
                <div className="rounded-xl border p-3">
                  <p className="text-xs font-semibold text-foreground">Required fields</p>
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    {requiredFields.map((field) => (
                      <Badge key={field} variant="default" className="font-mono text-[10px]">{field}</Badge>
                    ))}
                  </div>
                </div>
                <div className="rounded-xl border p-3">
                  <p className="text-xs font-semibold text-foreground">Optional fields</p>
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    {optionalFields.map((field) => (
                      <Badge key={field} variant="outline" className="font-mono text-[10px]">{field}</Badge>
                    ))}
                  </div>
                </div>
              </div>

              <div className="rounded-xl border border-warning/30 bg-warning/5 p-3">
                <div className="flex items-start gap-2">
                  <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
                  <div className="space-y-1">
                    <p className="text-xs font-semibold text-foreground">Branch identity comes from the API key</p>
                    <p className="text-xs text-muted-foreground">
                      The API key determines whether the transaction belongs to the main office or a branch. External systems may send branch fields, but NUERS prioritizes the branch identity configured on the API key for audit consistency.
                    </p>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>

          {loading && (
            <Card>
              <CardContent className="flex items-center justify-center gap-2 p-10 text-sm text-muted-foreground">
                <Loader2 className="h-4 w-4 animate-spin" />
                Loading database API keys...
              </CardContent>
            </Card>
          )}

          {!loading && keys.length === 0 && (
            <Card>
              <CardContent className="flex flex-col items-center justify-center gap-2 p-10 text-center">
                <Database className="h-9 w-9 text-muted-foreground/70" />
                <p className="text-sm font-medium text-foreground">No API keys yet</p>
                <p className="max-w-md text-xs text-muted-foreground">Create a key for each external system. Transactions sent with that key will be inserted only into this Business Account.</p>
              </CardContent>
            </Card>
          )}

          {!loading && keys.map((k) => {
            const oneTimeSecret = getOneTimeSecret(k);
            const keyCopyValue = getApiKeyCopyValue(k);
            const isKeyShown = showKey === k.id;
            const displayKey = isKeyShown ? keyCopyValue : maskApiKey(keyCopyValue);

            return (
            <Card key={k.id} className={cn("transition-opacity", !k.active && "opacity-60")}>
              <CardContent className="p-4 space-y-3">
                <div className="flex items-start justify-between gap-2 flex-wrap">
                  <div className="flex items-center gap-2 flex-wrap">
                    <p className="text-sm font-semibold text-foreground">{k.name}</p>
                    <Badge variant={k.env === "live" ? "default" : "secondary"} className="text-[10px]">
                      {k.env === "live" ? "Live" : "Sandbox"}
                    </Badge>
                    {!k.active && <Badge variant="destructive" className="text-[10px]">Inactive</Badge>}
                    {k.ip_whitelist.length > 0 && (
                      <Badge variant="outline" className="text-[10px] gap-1 border-success/40 text-success">
                        <Shield className="h-2.5 w-2.5" /> IP Restricted
                      </Badge>
                    )}
                    <Badge variant="outline" className="text-[10px]">
                      {k.branch_type === "main" ? "Main Office" : k.branch_name}
                    </Badge>
                  </div>
                  <div className="flex items-center gap-1.5">
                    <Switch checked={k.active} onCheckedChange={(v) => handleToggle(k.id, v)} disabled={busy || k.status === "revoked"} />
                    <Button variant="ghost" size="sm" className="h-7 text-xs gap-1" onClick={() => setSelectedKey(k)} aria-label={`View API key details for ${k.name}`}>
                      <Eye className="h-3.5 w-3.5" /> Details
                    </Button>
                    <Button variant="ghost" size="icon" className="h-7 w-7 text-destructive" onClick={() => handleRevoke(k.id)} aria-label={`Revoke API key ${k.name}`}>
                      <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </div>

                <div className="flex items-center gap-2 rounded-lg bg-muted/50 px-3 py-2">
                  <code className="flex-1 font-mono text-xs text-foreground truncate">
                    {displayKey}
                  </code>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-6 w-6 shrink-0"
                    disabled={!keyCopyValue}
                    onClick={() => toggleApiKeyVisibility(k)}
                    title={oneTimeSecret ? (isKeyShown ? "Hide API secret" : "Show API secret") : (isKeyShown ? "Hide API key preview" : "Show API key preview")}
                    aria-label={`${isKeyShown ? "Hide" : "Show"} API key for ${k.name}`}
                  >
                    {isKeyShown ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
                  </Button>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-6 w-6 shrink-0"
                    disabled={!keyCopyValue}
                    onClick={() => void handleCopy(keyCopyValue, getApiKeyCopyMessage(k))}
                    title={oneTimeSecret ? "Copy API secret" : "Copy API key preview"}
                    aria-label={`Copy API key for ${k.name}`}
                  >
                    <Copy className="h-3.5 w-3.5" />
                  </Button>
                </div>

                <div className="flex flex-wrap gap-1">
                  {k.permissions.map((p) => (
                    <Badge key={p} variant="outline" className="text-[10px] font-mono">{p}</Badge>
                  ))}
                </div>

                <div className="space-y-1">
                  <div className="flex items-center justify-between text-xs">
                    <span className="text-muted-foreground">Daily Rate Limit</span>
                    <span className="font-medium text-foreground">{k.requests_today.toLocaleString()} / {k.rate_limit.toLocaleString()}</span>
                  </div>
                  <Progress
                    value={(k.requests_today / k.rate_limit) * 100}
                    className={cn("h-1.5", (k.requests_today / k.rate_limit) > 0.8 && "[&>div]:bg-warning")}
                  />
                </div>

                <div className="flex gap-4 text-[10px] text-muted-foreground">
                  <span className="flex items-center gap-1"><Clock className="h-3 w-3" />Created: {k.created}</span>
                  <span className="flex items-center gap-1"><Activity className="h-3 w-3" />Last used: {k.last_used}</span>
                  <span className="flex items-center gap-1"><BarChart3 className="h-3 w-3" />{k.requests_month.toLocaleString()} this month</span>
                  <span className="flex items-center gap-1 font-mono">Branch: {k.branch_code}</span>
                </div>
              </CardContent>
            </Card>
          )})}
        </TabsContent>

        {/* Webhooks */}
        <TabsContent value="webhooks" className="mt-4">
          <Card>
            <CardHeader className="pb-3">
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle className="text-sm">Webhooks</CardTitle>
                  <p className="text-xs text-muted-foreground">Event-driven integrations</p>
                </div>
                <Button variant="outline" size="sm" className="gap-2 text-xs" onClick={() => toast.success("Add webhook dialog coming soon.")}>
                  <Plus className="h-3.5 w-3.5" /> Add Webhook
                </Button>
              </div>
            </CardHeader>
            <CardContent className="space-y-3">
              {webhooks.map((w) => (
                <div key={w.id} className="rounded-lg border p-4 space-y-3">
                  <div className="flex items-center justify-between flex-wrap gap-2">
                    <div className="flex items-center gap-2">
                      <span className="h-2 w-2 rounded-full bg-success animate-pulse" />
                      <Globe className="h-3.5 w-3.5 text-muted-foreground" />
                      <code className="text-xs font-mono text-foreground">{w.url}</code>
                    </div>
                    <div className="flex items-center gap-2">
                      <Badge variant="default" className="text-[10px]">Active</Badge>
                      <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => toast.success("Sending test event...")}>
                        <RefreshCw className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  </div>
                  <div className="flex gap-1 flex-wrap">
                    {w.events.map((e) => (
                      <Badge key={e} variant="secondary" className="text-[10px] font-mono">{e}</Badge>
                    ))}
                  </div>
                  <div className="flex gap-4 text-xs text-muted-foreground">
                    <span>{w.deliveries.toLocaleString()} total deliveries</span>
                    <span className="text-success font-medium">{w.success_rate}% success</span>
                    <span>Last: {w.last_delivery}</span>
                  </div>
                  <div className="space-y-1">
                    <Progress value={w.success_rate} className="h-1" />
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Analytics */}
        <TabsContent value="analytics" className="mt-4 space-y-4">
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm">API Request Volume (Today)</CardTitle>
            </CardHeader>
            <CardContent>
              <ChartContainer config={requestConfig} className="min-h-[200px] w-full">
                <BarChart data={hourlyRequests} accessibilityLayer>
                  <CartesianGrid vertical={false} stroke="var(--border)" />
                  <XAxis dataKey="hour" tickLine={false} axisLine={false} tick={{ fontSize: 9 }} />
                  <YAxis tickLine={false} axisLine={false} tick={{ fontSize: 10 }} />
                  <ChartTooltip content={<ChartTooltipContent />} />
                  <Bar dataKey="requests" fill="var(--color-requests)" radius={[3, 3, 0, 0]} />
                  <Bar dataKey="errors" fill="var(--color-errors)" radius={[3, 3, 0, 0]} />
                </BarChart>
              </ChartContainer>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm">Average Latency (ms)</CardTitle>
            </CardHeader>
            <CardContent>
              <ChartContainer config={latencyConfig} className="min-h-[140px] w-full">
                <AreaChart data={latencyData} accessibilityLayer>
                  <defs>
                    <linearGradient id="latGrad" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="var(--chart-2)" stopOpacity={0.3} />
                      <stop offset="100%" stopColor="var(--chart-2)" stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid vertical={false} stroke="var(--border)" />
                  <XAxis dataKey="hour" tickLine={false} axisLine={false} tick={{ fontSize: 9 }} />
                  <YAxis tickLine={false} axisLine={false} tick={{ fontSize: 10 }} tickFormatter={(v) => `${v}ms`} />
                  <ChartTooltip content={<ChartTooltipContent />} />
                  <Area type="monotone" dataKey="latency" stroke="var(--chart-2)" strokeWidth={2} fill="url(#latGrad)" />
                </AreaChart>
              </ChartContainer>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Key Detail Dialog */}
      <Dialog open={!!selectedKey} onOpenChange={() => setSelectedKey(null)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Key className="h-4 w-4 text-primary" /> {selectedKey?.name}
            </DialogTitle>
            <DialogDescription>View integration metadata and copy the available key value.</DialogDescription>
          </DialogHeader>
          {selectedKey && (
            <div className="space-y-4">
              <div className="rounded-lg border bg-muted/20 p-3">
                <div className="flex items-center justify-between gap-2">
                  <div>
                    <p className="text-[10px] uppercase tracking-wide text-muted-foreground">API key</p>
                    <p className="text-[11px] text-muted-foreground">
                      {selectedOneTimeSecret ? "Full one-time secret is available." : "Stored preview only. Full secret was shown once when created."}
                    </p>
                  </div>
                  <div className="flex items-center gap-1">
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7"
                      onClick={() => toggleApiKeyVisibility(selectedKey)}
                      aria-label={`${showKey === selectedKey.id ? "Hide" : "Show"} selected API key`}
                    >
                      {showKey === selectedKey.id ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7"
                      onClick={() => void handleCopy(selectedKeyCopyValue, getApiKeyCopyMessage(selectedKey))}
                      aria-label="Copy selected API key"
                    >
                      <Copy className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </div>
                <code className="mt-2 block break-all rounded-md bg-background px-3 py-2 font-mono text-xs text-foreground">
                  {selectedKeyDisplay}
                </code>
              </div>
              <div className="grid grid-cols-2 gap-2">
                {[
                  { label: "Environment", value: selectedKey.env },
                  { label: "Status", value: selectedKey.active ? "Active" : "Inactive" },
                  { label: "Created", value: selectedKey.created },
                  { label: "Last Used", value: selectedKey.last_used },
                  { label: "Today Requests", value: selectedKey.requests_today.toLocaleString() },
                  { label: "Monthly Requests", value: selectedKey.requests_month.toLocaleString() },
                  { label: "Rate Limit", value: `${selectedKey.rate_limit.toLocaleString()}/day` },
                  { label: "IP Whitelist", value: selectedKey.ip_whitelist.length > 0 ? selectedKey.ip_whitelist.join(", ") : "No restriction" },
                  { label: "Branch Type", value: selectedKey.branch_type === "main" ? "Main Office" : "Branch" },
                  { label: "Branch Code", value: selectedKey.branch_code },
                  { label: "Branch Name", value: selectedKey.branch_name },
                  { label: "Branch Location", value: selectedKey.branch_location || "Not set" },
                ].map((f) => (
                  <div key={f.label} className="rounded border p-2.5">
                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">{f.label}</p>
                    <p className="text-xs font-medium text-foreground mt-0.5">{f.value}</p>
                  </div>
                ))}
              </div>
              <div>
                <p className="text-xs font-medium text-foreground mb-1.5">Permissions</p>
                <div className="flex flex-wrap gap-1">
                  {selectedKey.permissions.map((p) => (
                    <Badge key={p} variant="outline" className="text-[10px] font-mono">{p}</Badge>
                  ))}
                </div>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>

      {/* Create Key Dialog */}
      <Dialog open={addOpen} onOpenChange={setAddOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2"><Plus className="h-4 w-4 text-primary" /> Create New API Key</DialogTitle>
            <DialogDescription>Keys expire after 90 days and are bound to one verified BIR branch. A key does not grant PTI/PTT authority. Sandbox keys cannot write the live ledger.</DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            {newlyCreatedKey?.plain_key && (
              <div className="rounded-lg border border-success/30 bg-success/5 p-3">
                <p className="text-xs font-semibold text-success">New API key created. Copy it now.</p>
                <div className="mt-2 flex items-center gap-2 rounded-md bg-background px-3 py-2">
                  <code className="min-w-0 flex-1 truncate text-xs font-mono text-foreground">{newlyCreatedKey.plain_key}</code>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7"
                    onClick={() => void handleCopy(newlyCreatedKey.plain_key, "API secret key copied to clipboard.")}
                    aria-label="Copy newly created API key"
                  >
                    <Copy className="h-3.5 w-3.5" />
                  </Button>
                </div>
                <p className="mt-2 text-[10px] text-muted-foreground">For security, existing keys are stored as hashes and cannot be revealed again.</p>
              </div>
            )}
            <div className="space-y-1.5">
              <Label>Key Name</Label>
              <Input placeholder="e.g. Mobile App v2" value={newKeyName} onChange={(e) => setNewKeyName(e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label>Environment</Label>
              <div className="flex gap-2">
                {(["live", "sandbox"] as const).map((env) => (
                  <Button key={env} variant={newKeyEnv === env ? "default" : "outline"} size="sm" onClick={() => setNewKeyEnv(env)} className="flex-1 capitalize">
                    {env === "live" ? "Live" : "Sandbox"}
                  </Button>
                ))}
              </div>
            </div>
            <div className="space-y-1.5">
              <Label>API Branch Identity</Label>
              <div className="grid grid-cols-2 gap-2">
                <Button
                  type="button"
                  variant={newBranchType === "main" ? "default" : "outline"}
                  size="sm"
                  onClick={() => {
                    setNewBranchType("main");
                    setNewBranchName("Main Office");
                    setNewBranchCode("");
                  }}
                >
                  Main Office
                </Button>
                <Button
                  type="button"
                  variant={newBranchType === "branch" ? "default" : "outline"}
                  size="sm"
                  onClick={() => {
                    setNewBranchType("branch");
                    if (newBranchName === "Main Office") setNewBranchName("");
                    if (newBranchCode === "MAIN") setNewBranchCode("");
                  }}
                >
                  Branch
                </Button>
              </div>
              <p className="text-[10px] text-muted-foreground">
                Transactions sent through this key inherit this branch identity. External systems cannot override it.
              </p>
            </div>
            {(
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label>Branch Name</Label>
                  <Input placeholder="e.g. Makati Branch" value={newBranchName} onChange={(e) => setNewBranchName(e.target.value)} />
                </div>
                <div className="space-y-1.5">
                  <Label>Branch Code</Label>
                  <Input placeholder="Verified 5-digit BIR branch" maxLength={5} inputMode="numeric" value={newBranchCode} onChange={(e) => setNewBranchCode(e.target.value.replace(/\D/g,""))} />
                </div>
              </div>
            )}
            <div className="space-y-1.5">
              <Label>{newBranchType === "main" ? "Main Office Location" : "Branch Location"}</Label>
              <Input placeholder="Optional address or branch location" value={newBranchLocation} onChange={(e) => setNewBranchLocation(e.target.value)} />
            </div>
            {newKeyEnv === "live" && (
              <div className="rounded-lg border border-warning/30 bg-warning/5 p-3 flex gap-2">
                <AlertTriangle className="h-4 w-4 text-warning shrink-0 mt-0.5" />
                <p className="text-xs text-muted-foreground">Live keys process real transactions. Use sandbox for testing.</p>
              </div>
            )}
            <div className="rounded-lg border bg-muted/30 p-3">
              <p className="text-xs font-medium text-foreground">Transaction insert sample</p>
              <pre className="mt-2 max-h-44 overflow-auto rounded bg-background p-2 text-[10px] text-muted-foreground">
{JSON.stringify(examplePayload, null, 2)}
              </pre>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setAddOpen(false)}>Close</Button>
            <Button onClick={handleCreate} disabled={!newKeyName.trim() || busy} className="gap-2">
              {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Key className="h-4 w-4" />}
              Create Key
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
