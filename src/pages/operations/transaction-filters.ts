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
