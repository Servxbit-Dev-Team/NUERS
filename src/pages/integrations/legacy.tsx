import { Navigate } from "react-router-dom";
import { useAuth } from "@/lib/auth";
import { IntegrationWorkspace } from "@/pages/integrations";

/** Old navigation URLs must never render sample keys, traffic or delivery results. */
export function LegacyIntegration() {
  const { profile, loading } = useAuth();
  if (loading) return <p role="status">Checking integration access…</p>;
  if (profile?.role === "merchant") return <Navigate to="/merchant/api-keys" replace />;
  if (["admin", "super_admin", "bir"].includes(profile?.role ?? ""))
    return <IntegrationWorkspace />;
  return (
    <p role="alert">
      Integration administration requires an authorized business or administrator account.
    </p>
  );
}
