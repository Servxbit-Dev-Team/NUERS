export const TOKEN_KEY = "nuers_api_token";
export const SESSION_INVALID_EVENT = "nuers:session-invalid";
const DEVICE_KEY = "nuers_browser_device";
let memoryDevice: string | undefined;
export function storedToken() {
  try {
    return typeof window === "undefined" ? null : window.localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}
export function browserDevice() {
  if (typeof window === "undefined") return null;
  try {
    let device = window.localStorage.getItem(DEVICE_KEY);
    if (!device) {
      device = crypto.randomUUID();
      window.localStorage.setItem(DEVICE_KEY, device);
    }
    return device;
  } catch {
    return (memoryDevice ??= crypto.randomUUID());
  }
}
/** Remove legacy tab-persistent financial data; keep unrelated browser preferences. */
export function clearPrivateBrowserState() {
  try {
    const storage = window.sessionStorage;
    for (let i = storage.length - 1; i >= 0; i--) {
      const key = storage.key(i);
      if (key?.startsWith("nuers_dashboard_")) storage.removeItem(key);
    }
  } catch { /* Browser storage can be restricted. */ }
}
export function invalidateToken(expected: string | null) {
  if (!expected || storedToken() !== expected) return false;
  try {
    window.localStorage.removeItem(TOKEN_KEY);
  } catch {}
  clearPrivateBrowserState();
  window.dispatchEvent(new Event(SESSION_INVALID_EVENT));
  return true;
}
