/** Return from MFA only to a local application page, never to another MFA loop. */
export function safeMfaReturnPath(requested: string | null): string {
  if (!requested || !/^\/(?!\/)/.test(requested) || /[\\\u0000-\u0020\u007f]/.test(requested)) return "/login";
  try {
    const base = "https://nuers.invalid";
    const url = new URL(requested, base);
    const path = decodeURIComponent(url.pathname);
    if (url.origin !== base || /[\\\u0000-\u0020\u007f]/.test(path) || path.startsWith("//") || path.startsWith("/security/mfa")) return "/login";
    return url.pathname + url.search + url.hash;
  } catch {
    return "/login";
  }
}
