/** Display a recorded TIN without changing its digits or inventing a branch. */
export function formatTin(value?: string | null): string {
  const original = (value ?? "").trim();
  if (!/^[\d\s-]+$/.test(original)) return original;
  const digits = original.replace(/[\s-]/g, "");
  if (![9, 12, 14].includes(digits.length)) return original;
  const base = `${digits.slice(0, 3)}-${digits.slice(3, 6)}-${digits.slice(6, 9)}`;
  return digits.length === 9 ? base : `${base}-${digits.slice(9)}`;
}
