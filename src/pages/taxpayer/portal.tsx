import { MerchantBilling } from "@/pages/merchant/billing";
import { useState } from "react";
import {
  Plus,
  Edit, MessageSquare,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

const SERVICE_REQUESTS = [
  { id: "SR-0084", type: "TIN correction", subject: "Update middle name on TIN record", status: "in_progress", priority: "normal", date: "2026-05-27" },
  { id: "SR-0083", type: "Branch registration", subject: "Register Cebu satellite office", status: "open", priority: "normal", date: "2026-05-26" },
  { id: "SR-0082", type: "Document request", subject: "Request certified copy of COR", status: "resolved", priority: "low", date: "2026-05-20" },
];

const STATUS_META = {
  in_progress: { label: "In Progress", color: "text-primary" },
  open:        { label: "Open",        color: "text-warning" },
  resolved:    { label: "Resolved",    color: "text-success" },
  closed:      { label: "Closed",      color: "text-muted-foreground" },
  paid:        { label: "Paid",        color: "text-success" },
  pending:     { label: "Pending",     color: "text-warning" },
  failed:      { label: "Failed",      color: "text-destructive" },
};

export function TaxpayerPortal() {
  const [tab, setTab] = useState("profile");
  const [showRequest, setShowRequest] = useState(false);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-xl font-bold text-foreground">Customer Portal</h1>
          <p className="text-xs text-muted-foreground mt-0.5">Profile management, transaction pricing, and service requests</p>
        </div>
        <Button size="sm" className="gap-1.5" onClick={() => setShowRequest(true)}>
          <Plus className="h-3.5 w-3.5" /> New Service Request
        </Button>
      </div>

      {/* Profile banner */}
      <Card>
        <CardContent className="p-5">
          <div className="flex items-center gap-4 flex-wrap">
            <Avatar className="h-14 w-14">
              <AvatarFallback className="text-lg font-bold bg-primary text-primary-foreground">AB</AvatarFallback>
            </Avatar>
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 flex-wrap">
                <h2 className="text-base font-bold text-foreground">ABC Trading Corporation</h2>
                <Badge variant="default" className="text-[9px]">Professional</Badge>
                <Badge variant="outline" className="text-[9px] text-success border-success/30">VAT Registered</Badge>
              </div>
              <div className="flex flex-wrap gap-3 mt-1 text-xs text-muted-foreground">
                <span>TIN: <span className="font-mono text-foreground">456-789-012-000</span></span>
                <span>RDO: RDO 43 — Pasig City</span>
                <span>Member since: Nov 2024</span>
                <span className="text-success">Verified</span>
              </div>
            </div>
            <Button variant="outline" size="sm" className="gap-1.5">
              <Edit className="h-3.5 w-3.5" /> Edit Profile
            </Button>
          </div>
        </CardContent>
      </Card>

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList className="h-8 mb-6">
          <TabsTrigger value="profile" className="text-xs">Profile</TabsTrigger>
          <TabsTrigger value="subscription" className="text-xs">Transaction Pricing</TabsTrigger>
          <TabsTrigger value="billing" className="text-xs">Fees & Storage</TabsTrigger>
          <TabsTrigger value="requests" className="text-xs">Service Requests</TabsTrigger>
        </TabsList>

        {/* ── Profile ───────────────────────────────────────────────── */}
        {tab === "profile" && (
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader className="px-5 pt-5 pb-2">
                <CardTitle className="text-sm">Business Information</CardTitle>
              </CardHeader>
              <CardContent className="px-5 pb-5">
                <div className="space-y-3 text-xs">
                  {[
                    { label: "Legal Business Name", value: "ABC Trading Corporation" },
                    { label: "Trade Name", value: "ABC Trading" },
                    { label: "Business Type", value: "Corporation" },
                    { label: "Industry", value: "Wholesale Trade" },
                    { label: "SEC Registration No.", value: "CS2019-123456" },
                    { label: "BIR Registration No.", value: "456-789-012-000" },
                    { label: "Registered Address", value: "5F Tower One, Ortigas, Pasig" },
                    { label: "Contact Email", value: "finance@abctrading.ph" },
                    { label: "Contact Phone", value: "+63 2 8123 4567" },
                  ].map(f => (
                    <div key={f.label} className="flex justify-between py-1.5 border-b last:border-0">
                      <span className="text-muted-foreground">{f.label}</span>
                      <span className="font-medium text-foreground text-right max-w-[200px] truncate">{f.value}</span>
                    </div>
                  ))}
                </div>
                <Button size="sm" variant="outline" className="gap-1.5 w-full mt-4">
                  <Edit className="h-3.5 w-3.5" /> Update Information
                </Button>
              </CardContent>
            </Card>

            <div className="space-y-4">
              <Card>
                <CardHeader className="px-5 pt-5 pb-2">
                  <CardTitle className="text-sm">Verification Status</CardTitle>
                </CardHeader>
                <CardContent className="px-5 pb-5 space-y-2">
                  {[
                    { label: "SEC Certificate",    status: "verified",   date: "Verified Jan 2025" },
                    { label: "BIR Registration",   status: "verified",   date: "Verified Nov 2024" },
                    { label: "Business Permit",    status: "expiring",   date: "Expires Dec 2026" },
                    { label: "Gov ID (Authorized Representative)", status: "verified", date: "Verified Nov 2024" },
                    { label: "Risk Assessment",    status: "medium",     date: "Score: 45/100" },
                  ].map(v => (
                    <div key={v.label} className="flex items-center justify-between py-1.5 border-b last:border-0 text-xs">
                      <span className="text-muted-foreground">{v.label}</span>
                      <div className="flex items-center gap-2">
                        <span className="text-muted-foreground text-[10px]">{v.date}</span>
                        <span className={cn("font-medium capitalize",
                          v.status === "verified" ? "text-success" :
                          v.status === "expiring" ? "text-warning" :
                          v.status === "medium" ? "text-warning" : "text-destructive"
                        )}>
                          {v.status === "verified" ? "Verified" : v.status === "expiring" ? "Expiring" : v.status === "medium" ? "Medium Risk" : v.status}
                        </span>
                      </div>
                    </div>
                  ))}
                </CardContent>
              </Card>

              <Card>
                <CardHeader className="px-5 pt-5 pb-2">
                  <CardTitle className="text-sm">Notification Preferences</CardTitle>
                </CardHeader>
                <CardContent className="px-5 pb-5 space-y-3">
                  {[
                    { label: "BIR deadline reminders", defaultOn: true },
                    { label: "EIS submission status", defaultOn: true },
                    { label: "Billing & invoices", defaultOn: true },
                    { label: "System announcements", defaultOn: false },
                    { label: "Promotional offers", defaultOn: false },
                  ].map(n => (
                    <div key={n.label} className="flex items-center justify-between text-xs">
                      <span className="text-foreground">{n.label}</span>
                      <Switch defaultChecked={n.defaultOn} className="scale-75" />
                    </div>
                  ))}
                </CardContent>
              </Card>
            </div>
          </div>
        )}

        {(tab === "subscription" || tab === "billing") && <MerchantBilling />}

        {/* ── Service Requests ─────────────────────────────────────── */}
        {tab === "requests" && (
          <Card>
            <CardHeader className="px-5 pt-5 pb-2 flex-row items-center justify-between">
              <CardTitle className="text-sm">Service Requests</CardTitle>
              <Button size="sm" className="gap-1 h-7 text-xs" onClick={() => setShowRequest(true)}>
                <Plus className="h-3 w-3" /> New Request
              </Button>
            </CardHeader>
            <CardContent className="px-5 pb-5">
              <div className="space-y-2">
                {SERVICE_REQUESTS.map(sr => {
                  const sm = STATUS_META[sr.status as keyof typeof STATUS_META];
                  return (
                    <div key={sr.id} className="flex items-center justify-between p-4 border rounded-lg hover:bg-muted/20 transition-colors">
                      <div className="flex items-center gap-3">
                        <MessageSquare className="h-4 w-4 text-muted-foreground flex-shrink-0" />
                        <div>
                          <div className="flex items-center gap-2">
                            <p className="text-xs font-semibold text-foreground">{sr.subject}</p>
                            <Badge variant="outline" className="text-[9px] font-mono">{sr.id}</Badge>
                          </div>
                          <p className="text-[10px] text-muted-foreground mt-0.5">{sr.type} • {sr.date}</p>
                        </div>
                      </div>
                      <div className="flex items-center gap-2">
                        <span className={cn("text-xs font-medium capitalize", sm?.color)}>{sm?.label}</span>
                        <Button variant="ghost" size="sm" className="h-7 text-xs">View</Button>
                      </div>
                    </div>
                  );
                })}
              </div>
            </CardContent>
          </Card>
        )}
      </Tabs>

      {/* New Service Request Dialog */}
      <Dialog open={showRequest} onOpenChange={setShowRequest}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle className="text-sm">New Service Request</DialogTitle>
          </DialogHeader>
          <div className="space-y-3 mt-2">
            <div className="space-y-1">
              <Label className="text-xs">Request Type</Label>
              <Select>
                <SelectTrigger className="h-8 text-xs"><SelectValue placeholder="Select type" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="tin_inquiry">TIN Inquiry</SelectItem>
                  <SelectItem value="tin_correction">TIN Correction</SelectItem>
                  <SelectItem value="registration_update">Registration Update</SelectItem>
                  <SelectItem value="branch_registration">Branch Registration</SelectItem>
                  <SelectItem value="vat_registration">VAT Registration</SelectItem>
                  <SelectItem value="document_request">Document Request</SelectItem>
                  <SelectItem value="dispute">Dispute</SelectItem>
                  <SelectItem value="general_inquiry">General Inquiry</SelectItem>
                  <SelectItem value="technical_support">Technical Support</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Subject</Label>
              <Input className="h-8 text-xs" placeholder="Brief description of your request" />
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Details</Label>
              <Textarea className="text-xs min-h-[100px]" placeholder="Provide full details of your request…" />
            </div>
            <div className="flex gap-3 justify-end">
              <Button variant="outline" size="sm" onClick={() => setShowRequest(false)}>Cancel</Button>
              <Button size="sm" onClick={() => { setShowRequest(false); toast.success("Service request SR-0085 submitted"); }}>
                Submit Request
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}
