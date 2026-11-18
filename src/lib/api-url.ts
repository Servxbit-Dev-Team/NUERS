import { rateLimitError } from "./rate-limit";
import { realtime } from "./realtime-coordinator";
import { storedToken, browserDevice, invalidateToken } from "./session-store";
const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL ?? "").replace(/\/$/, "");
function firstValidationError(errors: unknown) {
  if (!errors || typeof errors !== "object") return null;

  for (const value of Object.values(errors as Record<string, unknown>)) {
    if (Array.isArray(value) && value.length > 0) return String(value[0]);
    if (typeof value === "string") return value;
  }

  return null;
}

export function apiUrl(path: string) {
  // Paths are application-relative. Only the build-time API base may select an origin.
  if (/^[a-z][a-z0-9+.-]*:/i.test(path) || /[\\\u0000-\u0020\u007f]/.test(path))
    throw new Error("Invalid API path.");
  const normalizedPath = path.startsWith("/") ? path : `/${path}`;
  if (normalizedPath.startsWith("//")) throw new Error("Invalid API path.");
  return `${API_BASE_URL}${normalizedPath}`;
}

export function publicAssetUrl(path?: string | null) {
  const value = (path ?? "").trim();

  if (!value) return "";
  if (/^(https?:|data:|blob:)/i.test(value)) return value;

  const normalizedPath = value.startsWith("/") ? value : `/${value}`;

  if (normalizedPath.startsWith("/public/")) return normalizedPath;
  if (!API_BASE_URL) return normalizedPath;

  const normalizedBase = API_BASE_URL.endsWith("/") ? API_BASE_URL.slice(0, -1) : API_BASE_URL;

  if (normalizedPath.startsWith(`${normalizedBase}/`)) return normalizedPath;
  if (normalizedBase.endsWith("/public") && normalizedPath.startsWith("/uploads/")) {
    return `${normalizedBase}${normalizedPath}`;
  }

  return normalizedPath;
}

export function apiHeaders(
  headers?: HeadersInit,
  options: { json?: boolean; auth?: boolean } = {},
) {
  const nextHeaders = new Headers(headers);

  if (!nextHeaders.has("Accept")) nextHeaders.set("Accept", "application/json");
  if (options.json && !nextHeaders.has("Content-Type"))
    nextHeaders.set("Content-Type", "application/json");

  if (options.auth !== false && !nextHeaders.has("Authorization")) {
    const token = storedToken();
    if (token) nextHeaders.set("Authorization", `Bearer ${token}`);
  }

  return nextHeaders;
}

// Response headers can arrive before a logout/account switch and before the body.
// Bind every successful private response (including clones/downloads) to its session.
const responseSessions = new WeakMap<Response, string>();
export function assertResponseSession(response: Response) {
  const token = responseSessions.get(response);
  if (token && storedToken() !== token)
    throw new Error("Your session changed. Reload this page before continuing.");
}
function bindResponseSession(response: Response, token: string): Response {
  if (responseSessions.has(response)) return response;
  responseSessions.set(response, token);
  for (const method of ["json", "text", "blob", "arrayBuffer", "formData"] as const) {
    const read = response[method].bind(response);
    Object.defineProperty(response, method, { value: async () => {
      assertResponseSession(response);
      const value = await read();
      assertResponseSession(response);
      return value;
    }});
  }
  const clone = response.clone.bind(response);
  Object.defineProperty(response, "clone", { value: () => {
    assertResponseSession(response);
    return bindResponseSession(clone(), token);
  }});
  assertResponseSession(response);
  return response;
}

export async function readJsonResponse<T = any>(
  response: Response,
  fallbackMessage = "Request failed.",
) {
  const contentType = response.headers.get("Content-Type") ?? "";

  if (!contentType.toLowerCase().includes("application/json")) {
    if (response.status === 429) throw rateLimitError(response, {});
    throw new Error(
      "API endpoint returned HTML instead of JSON. Check the live API deployment path.",
    );
  }

  assertResponseSession(response);
  const payload = await response.json().catch((error) => {
    assertResponseSession(response);
    throw new Error("The server returned an invalid JSON response. Please retry.", { cause: error });
  });
  assertResponseSession(response);

  if (!response.ok) {
    if (response.status === 429) throw rateLimitError(response, payload);
    const message = payload?.message || firstValidationError(payload?.errors) || fallbackMessage;
    throw new Error(String(message));
  }

  return payload as T;
}

export async function apiFetch(path: string, init: RequestInit = {}) {
  const url = apiUrl(path);
  const hasFormBody = typeof FormData !== "undefined" && init.body instanceof FormData;

  const token = storedToken();
  const headers = apiHeaders(init.headers, {
    json: Boolean(init.body) && !hasFormBody,
    auth: true,
  });
  const device = browserDevice();
  if (device) headers.set("X-NUERS-Device", device);
  const finished = realtime.beginRequest();
  let response: Response;
  try {
    response = await fetch(url, {
      ...init,
      cache: "no-store",
      redirect: "error",
      headers,
    });
    const retry = response.headers.get("Retry-After");
    const retrySeconds = retry
      ? /^\d+$/.test(retry)
        ? Number(retry)
        : Math.max(0, (Date.parse(retry) - Date.now()) / 1000)
      : 0;
    finished(response.status, Number.isFinite(retrySeconds) ? retrySeconds : 0);
  } catch (error) {
    // Navigation/filter cancellation is not an outage.
    finished(init.signal?.aborted ? 200 : 0);
    throw error;
  }
  const ownsRequest = Boolean(token && headers.get("Authorization") === `Bearer ${token}`);
  if (response.ok && ownsRequest) bindResponseSession(response, token!);
  if (response.status === 401 && headers.get("Authorization") === `Bearer ${token}`)
    invalidateToken(token);
  if (response.status === 403) {
    const body = await response
      .clone()
      .json()
      .catch(() => null);
    if (ownsRequest && storedToken() === token && body?.code === "MFA_REQUIRED" && window.location.pathname !== "/security/mfa")
      window.location.assign(
        `/security/mfa?returnTo=${encodeURIComponent(window.location.pathname + window.location.search + window.location.hash)}`,
      );
  }
  return response;
}

export async function apiJson<T = any>(
  path: string,
  init: RequestInit & { timeoutMs?: number } = {},
  fallbackMessage?: string,
) {
  const {
    timeoutMs = !init.method || init.method.toUpperCase() === "GET" ? 30000 : undefined,
    ...options
  } = init;
  if (!timeoutMs) return readJsonResponse<T>(await apiFetch(path, options), fallbackMessage);
  const controller = new AbortController();
  const cancel = () => controller.abort(options.signal?.reason);
  if (options.signal?.aborted) cancel();
  else options.signal?.addEventListener("abort", cancel, { once: true });
  let timedOut = false;
  const timer = setTimeout(() => {
    timedOut = true;
    controller.abort();
  }, timeoutMs);
  try {
    return await readJsonResponse<T>(
      await apiFetch(path, { ...options, signal: controller.signal }),
      fallbackMessage,
    );
  } catch (error) {
    if (timedOut && !options.signal?.aborted)
      throw new Error(
        !options.method || ["GET", "HEAD"].includes(options.method.toUpperCase())
          ? "This request took too long. Please retry."
          : "This request timed out. Its outcome is unknown; check your records before retrying.",
      );
    throw error;
  } finally {
    clearTimeout(timer);
    options.signal?.removeEventListener("abort", cancel);
  }
}
