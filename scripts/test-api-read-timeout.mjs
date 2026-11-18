import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import ts from "typescript";

let fetchImpl;
const values = new Map([["nuers_api_token", "test-session"]]);
const window = {
  localStorage: { getItem: (key) => values.get(key), removeItem: (key) => values.delete(key) },
};
const exports = {};
vm.runInNewContext(
  ts.transpileModule(
    fs
      .readFileSync("src/lib/api-url.ts", "utf8")
      .replaceAll("import.meta.env.VITE_API_BASE_URL", "undefined"),
    { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } },
  ).outputText,
  {
    exports,
    window,
    Headers,
    Response,
    FormData,
    AbortController,
    setTimeout,
    clearTimeout,
    fetch: (...args) => fetchImpl(...args),
    require: (id) =>
      id === "./session-store"
        ? {
            storedToken: () => values.get("nuers_api_token"),
            browserDevice: () => "test-device",
            invalidateToken: () => values.delete("nuers_api_token"),
          }
        : { rateLimitError: () => new Error("Rate limited") },
  },
);
const hanging = (_, { signal }) =>
  new Promise((resolve, reject) => {
    if (signal.aborted) reject(signal.reason);
    else signal.addEventListener("abort", () => reject(signal.reason), { once: true });
  });
fetchImpl = hanging;
await assert.rejects(exports.apiJson("/api/merchant/overview", { timeoutMs: 15 }), /took too long/);
assert.equal(values.get("nuers_api_token"), "test-session");
const caller = new AbortController();
const request = exports.apiJson("/api/merchant/sales-reports", {
  timeoutMs: 1000,
  signal: caller.signal,
});
caller.abort(new Error("Navigation cancelled"));
await assert.rejects(request, /Navigation cancelled/);
assert.equal(values.get("nuers_api_token"), "test-session");
fetchImpl = async () =>
  new Response('{"count":341}', { headers: { "Content-Type": "application/json" } });
assert.equal((await exports.apiJson("/api/merchant/sales-reports", { timeoutMs: 100 })).count, 341);
const already = new AbortController();
already.abort(new Error("Already cancelled"));
fetchImpl = hanging;
await assert.rejects(
  exports.apiJson("/api/merchant/overview", { timeoutMs: 100, signal: already.signal }),
  /Already cancelled/,
);
console.log(
  "Read timeouts: bounded loading, session preservation, navigation cancellation, successful retry, and pre-aborted requests passed.",
);
