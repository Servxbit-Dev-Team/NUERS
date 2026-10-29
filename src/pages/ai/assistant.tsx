import { Link } from "react-router-dom";
import { BookOpen, ShieldCheck } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";

export function AIAssistant() {
  return <Card><CardHeader><CardTitle className="flex items-center gap-2"><BookOpen />NUERS guidance</CardTitle></CardHeader><CardContent className="space-y-4">
    <p>Use the published documentation for integration instructions and current system capabilities.</p>
    <p className="text-sm text-muted-foreground">Automated legal advice, account-specific AI analysis and regulatory certification are not enabled. Recorded operational checks remain available to authorized administrators.</p>
    <Button asChild><Link to="/api/docs">Open documentation</Link></Button>
    <p className="flex items-center gap-2 text-sm"><ShieldCheck className="size-4" />No merchant data is sent to an AI provider by this page.</p>
  </CardContent></Card>;
}
