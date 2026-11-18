import { apiFetch, readJsonResponse } from "./api-url";

/** Wait for the actor-owned CLI render without holding a long-running web request. */
export async function fetchTransactionExport(path: string): Promise<Response> {
  let response = await apiFetch(path);
  for (let attempt = 0; response.status === 202; attempt++) {
    const job = (await readJsonResponse(response)) as { export_id?: string };
    if (!job.export_id || !/^[0-9a-f-]{36}$/i.test(job.export_id))
      throw new Error("The export response was incomplete. Please try again.");
    if (attempt >= 180)
      throw new Error(
        "The report is still preparing. Please contact your administrator if it does not complete shortly.",
      );
    await new Promise((resolve) => setTimeout(resolve, 2000));
    response = await apiFetch(
      "/api/transaction-workspace/exports/" + encodeURIComponent(job.export_id),
    );
  }
  if (!response.ok) await readJsonResponse(response, "Export failed. Please try again.");
  return response;
}
