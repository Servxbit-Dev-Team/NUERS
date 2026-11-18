/** Customer chat belongs only to authenticated Merchant and Citizen portal pages. */
export function supportWidgetAllowed(pathname: string, role?: string | null): boolean {
  const within = (root: string) => pathname === root || pathname.startsWith(`${root}/`);
  return (
    (role === "merchant" && within("/merchant")) ||
    (["client", "consumer"].includes(role || "") && (within("/client") || within("/consumer")))
  );
}
