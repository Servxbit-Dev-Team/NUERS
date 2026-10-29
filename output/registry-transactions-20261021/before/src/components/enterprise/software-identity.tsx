import { useEffect, useState } from "react";
import { apiJson } from "@/lib/api-url";

type Identity = { name: string; version: string };
let identityRequest: Promise<Identity> | undefined;

/** Display the configured server release, rather than the API or document revision. */
export function SoftwareIdentity() {
  const [identity, setIdentity] = useState<Identity | null>(null);
  useEffect(() => {
    let active = true;
    identityRequest ??= apiJson<{ solution: Identity }>("/api/health").then(value => value.solution).catch(error => { identityRequest = undefined; throw error; });
    identityRequest.then(value => { if (active) setIdentity(value); }).catch(() => {});
    return () => { active = false; };
  }, []);
  return <span className="ne-software-identity" aria-label="Software name and version" title="National Unified Electronic Receipt System">{identity ? `${identity.name} · Version ${identity.version}` : "NUERS · Version unavailable"}</span>;
}
