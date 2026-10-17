import { useState, useRef, useEffect } from "react";
import {
  Send, Zap, Shield, FileText, AlertTriangle, RefreshCw,
  Bot, User, Copy, ThumbsUp, ThumbsDown,
  BarChart3, Lightbulb,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { Textarea } from "@/components/ui/textarea";
import { toast } from "sonner";

type Message = {
  id: string;
  role: "user" | "assistant";
  content: string;
  time: string;
};

const suggestions = [
  { icon: FileText, label: "Validate invoice compliance", prompt: "Help me validate if my invoice meets BIR EIS requirements" },
  { icon: Shield, label: "Check tax type requirements", prompt: "What VAT requirements apply to a service company with annual revenue of ₱5M?" },
  { icon: AlertTriangle, label: "Troubleshoot EIS rejection", prompt: "My invoice was rejected with error BIR-ERR-4012 VAT Rate Mismatch. How do I fix it?" },
  { icon: BarChart3, label: "Compliance recommendations", prompt: "Review my taxpayer profile and give me compliance improvement recommendations" },
  { icon: Zap, label: "API integration guidance", prompt: "How do I integrate my POS system with the NUERS API?" },
  { icon: Lightbulb, label: "Tax computation help", prompt: "Calculate the VAT and withholding tax for a ₱500,000 professional services invoice" },
];

const initialMessages: Message[] = [
  {
    id: "1",
    role: "assistant",
    content: `Hello! I'm the **NUERS AI Compliance Assistant** — your intelligent guide for BIR EIS compliance, invoice validation, tax computation, and regulatory guidance.

I can help you with:
- **Invoice Compliance**: Validate invoices against BIR EIS requirements
- **Tax Computation**: Calculate VAT, withholding tax, and other taxes
- **EIS Troubleshooting**: Diagnose and fix transmission errors
- **Regulatory Guidance**: Navigate BIR rules, RMCs, and RRs
- **API Integration**: Guide you through NUERS API integration
- **Compliance Analysis**: Assess your compliance posture

How can I assist you today?`,
    time: "14:41",
  },
];

function MessageBubble({ msg }: { msg: Message }) {
  const isUser = msg.role === "user";
  return (
    <div className={`flex gap-3 ${isUser ? "flex-row-reverse" : "flex-row"}`}>
      <div className={`flex h-8 w-8 items-center justify-center rounded-full shrink-0 ${isUser ? "bg-primary text-primary-foreground" : "bg-sidebar text-sidebar-foreground"}`}>
        {isUser ? <User className="h-4 w-4" /> : <Bot className="h-4 w-4" />}
      </div>
      <div className={`max-w-[75%] ${isUser ? "items-end" : "items-start"} flex flex-col gap-1`}>
        <div className={`rounded-xl px-4 py-3 text-sm leading-relaxed whitespace-pre-wrap ${isUser ? "bg-primary text-primary-foreground rounded-tr-sm" : "bg-card border rounded-tl-sm"}`}>
          {msg.content.replace(/\*\*(.*?)\*\*/g, "$1")}
        </div>
        <div className={`flex items-center gap-2 ${isUser ? "flex-row-reverse" : "flex-row"}`}>
          <span className="text-[10px] text-muted-foreground">{msg.time}</span>
          {!isUser && (
            <div className="flex items-center gap-1">
              <button className="text-muted-foreground hover:text-foreground transition-colors" onClick={() => toast.success("Copied to clipboard")}>
                <Copy className="h-3 w-3" />
              </button>
              <button className="text-muted-foreground hover:text-success transition-colors">
                <ThumbsUp className="h-3 w-3" />
              </button>
              <button className="text-muted-foreground hover:text-destructive transition-colors">
                <ThumbsDown className="h-3 w-3" />
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

const autoResponses: Record<string, string> = {
  default: `I understand your question. Let me provide guidance based on BIR EIS requirements and current tax regulations in the Philippines.

**Analysis:**
Based on the information provided, here are my recommendations:

1. **Compliance Check**: Your invoice structure needs to include all required BIR EIS fields including seller TIN, buyer TIN, invoice date, invoice number series, and detailed line items.

2. **VAT Computation**: Ensure VAT is computed at 12% on the base amount, with proper segregation of VAT-exempt and VAT-zero rated items if applicable.

3. **EIS Transmission**: After validation, invoices should be transmitted within the required window. Any transmission errors should be retried with exponential backoff.

Is there a specific aspect you'd like me to elaborate on?`,

  "validate": `**Invoice Validation Analysis**

I'll run a compliance check against BIR EIS requirements:

✅ **Required Fields Status:**
- Seller TIN: Required — Must be verified with BIR records
- Invoice Number: Must follow registered series (RMO 12-2013)
- Date: Must be in YYYY-MM-DD format per EIS spec
- VAT Registration Number: Required for VAT-registered sellers
- Line items: Each must include item description, quantity, unit price, and tax breakdown

⚠️ **Common Issues to Check:**
- VAT rate must exactly match BIR registered rate (12% standard)
- Invoice series must be pre-registered with BIR
- Timestamps must be in UTC+8 (Philippine Standard Time)

📋 **Required Documents:**
- Authority to Print (ATP) or BIR Form 1906 approval
- Certificate of Registration (COR) — BIR Form 2303

Would you like me to validate a specific invoice JSON payload?`,

  "error": `**EIS Error Analysis: BIR-ERR-4012 — VAT Rate Mismatch**

This error occurs when the VAT rate in your invoice does not match the rate registered with BIR.

**Root Cause:**
The submitted invoice has a VAT rate of 0% (zero-rated) but your BIR registration indicates standard 12% VAT.

**Resolution Steps:**
1. Verify your VAT registration status in the BIR CAS/EFPS portal
2. If you are zero-rated (export sales, PEZA-registered, etc.), provide supporting documentation
3. Update the invoice VAT type field to match your actual registration: \`"vat_type": "VAT12"\`
4. Resubmit the corrected invoice through the NUERS portal

**Regulatory Reference:**
- NIRC Section 106 (VAT on Sale of Goods)
- BIR Revenue Regulation No. 13-2018

**BIR EIS Error Code Reference:**
- 4012: VAT Rate Mismatch — Rate submitted differs from BIR records
- 4011: Invalid TIN — Verify TIN with BIR online verification
- 5001: Connection Timeout — Retry with exponential backoff

Need help with the corrected payload format?`,

  "api": `**NUERS API Integration Guide**

Here's a quick-start guide for integrating your POS system with NUERS:

**Step 1: Authentication**
\`\`\`
POST /api/v1/auth/token
Content-Type: application/json
{
  "client_id": "your_client_id",
  "client_secret": "your_client_secret",
  "grant_type": "client_credentials"
}
\`\`\`

**Step 2: Submit Invoice**
\`\`\`
POST /api/v1/invoices/submit
Authorization: Bearer {access_token}
{
  "invoice_type": "SALES_INVOICE",
  "seller_tin": "123-456-789-000",
  "invoice_date": "2026-05-29",
  ...
}
\`\`\`

**Step 3: Check Transmission Status**
\`\`\`
GET /api/v1/invoices/{invoice_id}/status
\`\`\`

**SDK Available:**
- JavaScript/TypeScript: \`npm install @nuers/sdk\`
- Python: \`pip install nuers-sdk\`
- Java: Available in Maven Central

Rate Limits: 100 req/min (Standard), 500 req/min (Enterprise)

Shall I provide a complete integration example for your specific POS platform?`,
};

function getAutoResponse(input: string): string {
  const lower = input.toLowerCase();
  if (lower.includes("validate") || lower.includes("invoice") || lower.includes("requirement")) return autoResponses.validate;
  if (lower.includes("error") || lower.includes("reject") || lower.includes("bir-err")) return autoResponses.error;
  if (lower.includes("api") || lower.includes("integrat") || lower.includes("pos")) return autoResponses.api;
  return autoResponses.default;
}

export function AIAssistant() {
  const [messages, setMessages] = useState<Message[]>(initialMessages);
  const [input, setInput] = useState("");
  const [loading, setLoading] = useState(false);
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages]);

  function sendMessage(text?: string) {
    const msg = text || input;
    if (!msg.trim()) return;
    setInput("");

    const userMsg: Message = {
      id: Date.now().toString(),
      role: "user",
      content: msg,
      time: new Date().toLocaleTimeString("en-PH", { hour: "2-digit", minute: "2-digit" }),
    };
    setMessages((prev) => [...prev, userMsg]);
    setLoading(true);

    setTimeout(() => {
      const aiMsg: Message = {
        id: (Date.now() + 1).toString(),
        role: "assistant",
        content: getAutoResponse(msg),
        time: new Date().toLocaleTimeString("en-PH", { hour: "2-digit", minute: "2-digit" }),
      };
      setMessages((prev) => [...prev, aiMsg]);
      setLoading(false);
    }, 1200);
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">NUERS AI Assistant</h1>
          <p className="text-sm text-muted-foreground">Intelligent compliance, tax, and technical guidance with NUERS AI</p>
        </div>
        <div className="flex items-center gap-2">
          <Badge className="bg-gold/20 text-gold-foreground border-gold/40 text-xs">
            <Zap className="h-3 w-3 mr-1 text-gold" />
            NUERS AI
          </Badge>
          <Button variant="outline" size="sm" className="gap-2" onClick={() => { setMessages(initialMessages); toast.success("Conversation cleared"); }}>
            <RefreshCw className="h-3.5 w-3.5" /> New Chat
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-4">
        {/* Suggestions sidebar */}
        <div className="lg:col-span-1 space-y-3">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Quick Prompts</CardTitle>
            </CardHeader>
            <CardContent className="p-2 space-y-1">
              {suggestions.map((s) => (
                <button
                  key={s.label}
                  className="w-full text-left flex items-start gap-2 p-2 rounded-lg hover:bg-muted/60 transition-colors"
                  onClick={() => sendMessage(s.prompt)}
                >
                  <s.icon className="h-3.5 w-3.5 text-primary mt-0.5 shrink-0" />
                  <span className="text-xs">{s.label}</span>
                </button>
              ))}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">AI Capabilities</CardTitle>
            </CardHeader>
            <CardContent className="p-3 space-y-2">
              {[
                { label: "Invoice Validation", desc: "BIR EIS compliance check" },
                { label: "Tax Computation", desc: "VAT, WHT, income tax" },
                { label: "EIS Error Resolution", desc: "Diagnose & fix errors" },
                { label: "Regulatory Guidance", desc: "RMC, RR, BIR circulars" },
                { label: "API Support", desc: "Integration assistance" },
              ].map((cap) => (
                <div key={cap.label}>
                  <p className="text-xs font-medium">{cap.label}</p>
                  <p className="text-[10px] text-muted-foreground">{cap.desc}</p>
                </div>
              ))}
            </CardContent>
          </Card>
        </div>

        {/* Chat window */}
        <Card className="lg:col-span-3 flex flex-col" style={{ height: "calc(100vh - 220px)", minHeight: "500px" }}>
          <CardContent className="flex flex-col h-full p-0">
            {/* Messages */}
            <div className="flex-1 overflow-y-auto p-4 space-y-4">
              {messages.map((msg) => (
                <MessageBubble key={msg.id} msg={msg} />
              ))}
              {loading && (
                <div className="flex gap-3">
                  <div className="flex h-8 w-8 items-center justify-center rounded-full bg-sidebar text-sidebar-foreground shrink-0">
                    <Bot className="h-4 w-4" />
                  </div>
                  <div className="bg-card border rounded-xl rounded-tl-sm px-4 py-3 flex items-center gap-2">
                    <div className="flex gap-1">
                      <div className="h-2 w-2 rounded-full bg-muted-foreground animate-bounce" style={{ animationDelay: "0ms" }} />
                      <div className="h-2 w-2 rounded-full bg-muted-foreground animate-bounce" style={{ animationDelay: "150ms" }} />
                      <div className="h-2 w-2 rounded-full bg-muted-foreground animate-bounce" style={{ animationDelay: "300ms" }} />
                    </div>
                    <span className="text-xs text-muted-foreground">NUERS AI is thinking...</span>
                  </div>
                </div>
              )}
              <div ref={endRef} />
            </div>

            <Separator />

            {/* Input */}
            <div className="p-4">
              <div className="flex items-end gap-2">
                <Textarea
                  placeholder="Ask about invoice compliance, tax computation, EIS errors, regulatory guidance..."
                  className="resize-none min-h-[48px] max-h-32 text-sm"
                  value={input}
                  onChange={(e) => setInput(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === "Enter" && !e.shiftKey) {
                      e.preventDefault();
                      sendMessage();
                    }
                  }}
                />
                <Button
                  size="icon"
                  className="h-12 w-12 shrink-0"
                  onClick={() => sendMessage()}
                  disabled={!input.trim() || loading}
                >
                  <Send className="h-4 w-4" />
                </Button>
              </div>
              <p className="text-[10px] text-muted-foreground mt-2">
                NUERS AI provides guidance based on BIR regulations. Always verify critical tax matters with a qualified tax professional.
              </p>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
