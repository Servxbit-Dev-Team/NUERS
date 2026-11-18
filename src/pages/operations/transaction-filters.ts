export const emptyTransactionFilters = {
  search: "",
  merchant: "all",
  from: "",
  to: "",
  date_basis: "document",
  type: "all",
  document: "all",
  status: "all",
  sale_category: "all",
  vat: "all",
  discount: "all",
  customer: "",
  payment: "all",
  channel: "all",
  source: "all",
  branch: "all",
  branch_code: "all",
  seller_vat: "all",
  industry: "all",
  region: "all",
  rdo: "all",
  bir: "all",
  identity: "all",
  min: "",
  max: "",
  sort: "newest",
};
export type TransactionFilters = typeof emptyTransactionFilters;
export const filterTitles: Record<keyof TransactionFilters, string> = {
  search: "Keyword",
  merchant: "Merchant",
  from: "From",
  to: "To",
  date_basis: "Date basis",
  type: "Document group",
  document: "Exact document type",
  status: "NUERS status",
  sale_category: "Sale category",
  vat: "Tax basis",
  discount: "Discount",
  customer: "Customer / TIN",
  payment: "Payment method",
  channel: "Channel",
  source: "Record source",
  branch: "Branch",
  branch_code: "Seller branch code",
  seller_vat: "Seller VAT registration",
  industry: "Registered industry",
  region: "Region",
  rdo: "RDO",
  bir: "BIR evidence",
  identity: "Record identity",
  min: "Minimum amount (₱)",
  max: "Maximum amount (₱)",
  sort: "Sort records",
};
export function transactionQuery(filters: TransactionFilters) {
  const query = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== "" && value !== "all") query.set(key, value);
  });
  return query;
}

/** Read only known filters; the API validates values and enforces the actor's scope. */
export function transactionFiltersFromSearch(search: string): TransactionFilters {
  const params = new URLSearchParams(search);
  const filters = { ...emptyTransactionFilters };
  for (const key of Object.keys(filters) as (keyof TransactionFilters)[]) {
    const value = params.get(key);
    if (value !== null) filters[key] = value.slice(0, 255);
  }
  return filters;
}
