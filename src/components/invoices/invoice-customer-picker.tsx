import { useEffect, useState } from "react";
import { Check, ChevronsUpDown, Loader2, Plus, RefreshCw, Users } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
  Command,
  CommandInput,
  CommandList,
  CommandItem,
  CommandGroup,
} from "@/components/ui/command";
import { apiJson } from "@/lib/api-url";

export type InvoiceCustomer = {
  id: string;
  code: string;
  name: string;
  tin: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  type: string;
  status: string;
  revision: number;
};
type Result = { data: InvoiceCustomer[]; current_page: number; last_page: number; total: number };
export function InvoiceCustomerPicker({
  selected,
  newName,
  onSelect,
  onNew,
  disabled = false,
}: {
  selected: InvoiceCustomer | null;
  newName: string;
  onSelect: (c: InvoiceCustomer) => void;
  onNew: (name: string) => void;
  disabled?: boolean;
}) {
  const [open, setOpen] = useState(false),
    [query, setQuery] = useState(""),
    [applied, setApplied] = useState(""),
    [rows, setRows] = useState<InvoiceCustomer[]>([]),
    [page, setPage] = useState(1),
    [last, setLast] = useState(1),
    [total, setTotal] = useState(0),
    [loading, setLoading] = useState(false),
    [error, setError] = useState(""),
    [retry, setRetry] = useState(0);
  useEffect(() => {
    if (query.trim() === applied) return;
    const timer = setTimeout(() => {
      setRows([]);
      setPage(1);
      setApplied(query.trim());
    }, 250);
    return () => clearTimeout(timer);
  }, [query, applied]);
  useEffect(() => {
    if (!open) return;
    if (applied.length === 1) {
      setRows([]);
      setLoading(false);
      return;
    }
    const c = new AbortController();
    setLoading(true);
    setError("");
    apiJson<Result>(
      `/api/merchant/customers/options?${new URLSearchParams({ page: String(page), ...(applied ? { search: applied } : {}) })}`,
      { signal: c.signal },
    )
      .then((v) => {
        if (c.signal.aborted) return;
        setRows((old) =>
          page === 1 ? v.data : [...old, ...v.data.filter((x) => !old.some((o) => o.id === x.id))],
        );
        setLast(v.last_page);
        setTotal(v.total);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message || "Could not load customers.");
      })
      .finally(() => {
        if (!c.signal.aborted) setLoading(false);
      });
    return () => c.abort();
  }, [open, applied, page, retry]);
  const settling = query.trim() !== applied;
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          aria-label="Business / Person Name"
          aria-expanded={open}
          aria-controls="invoice-customer-options"
          disabled={disabled}
          className="w-full justify-between h-auto min-h-11 px-3 py-2 text-sm text-left font-normal"
        >
          <span className="flex min-w-0 items-center gap-2">
            <Users className="h-4 w-4 shrink-0 text-blue-600" />
            <span className="truncate">
              {selected
                ? selected.name
                : newName
                  ? `New buyer: ${newName}`
                  : "Select customer or add a new buyer…"}
            </span>
          </span>
          <ChevronsUpDown className="h-4 w-4 opacity-50 shrink-0" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align="start"
        className="w-[var(--radix-popover-trigger-width)] min-w-[min(340px,90vw)] max-w-[92vw] p-0"
      >
        <Command shouldFilter={false}>
          <CommandInput
            aria-label="Search saved customers"
            placeholder="Search customer, TIN, email or phone…"
            maxLength={80}
            value={query}
            onValueChange={setQuery}
          />
          <CommandList id="invoice-customer-options" className="max-h-72">
            <CommandGroup heading="Your saved customers">
              {!settling &&
                rows.map((c) => (
                  <CommandItem
                    key={c.id}
                    value={c.id}
                    onSelect={() => {
                      onSelect(c);
                      setOpen(false);
                    }}
                    className="px-3 py-3 text-sm"
                  >
                    <span className="flex-1 min-w-0">
                      <strong className="block font-medium truncate">{c.name}</strong>
                      <span className="block text-xs text-muted-foreground">
                        {c.code} · {c.tin ? `TIN ${c.tin}` : "TIN not recorded"}
                      </span>
                      <span className="block text-xs text-muted-foreground truncate">
                        {c.email || c.phone || "No contact recorded"}
                      </span>
                    </span>
                    {selected?.id === c.id && <Check className="h-4 w-4 text-blue-600" />}
                  </CommandItem>
                ))}
              {(loading || settling) && (
                <div
                  className="p-3 text-sm text-muted-foreground flex gap-2 items-center"
                  role="status"
                >
                  <Loader2 className="w-4 h-4 animate-spin" />
                  Loading customers…
                </div>
              )}
              {!loading && !settling && query.trim().length === 1 && (
                <p className="p-3 text-sm text-muted-foreground">
                  Type at least two characters to search.
                </p>
              )}
              {!loading && !settling && !error && applied.length !== 1 && !rows.length && (
                <p className="p-3 text-sm text-muted-foreground">
                  {applied ? "No matching saved customers." : "Your customer directory is empty."}
                </p>
              )}
              {error && (
                <div className="p-3 text-sm text-red-600" role="alert">
                  {error}
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => setRetry(retry + 1)}
                  >
                    <RefreshCw className="w-3 h-3" />
                    Retry
                  </Button>
                </div>
              )}
              {page < last && !settling && !error && (
                <CommandItem
                  disabled={loading}
                  value="load-more-customers"
                  onSelect={() => setPage(page + 1)}
                  className="justify-center py-3 text-sm text-blue-600"
                >
                  Load more customers ({rows.length} of {total})
                </CommandItem>
              )}
            </CommandGroup>
            <CommandGroup heading="New customer">
              <CommandItem
                value="add-new-customer"
                onSelect={() => {
                  onNew(query.trim());
                  setOpen(false);
                }}
                className="py-3 text-sm text-blue-600"
              >
                <Plus className="w-4 h-4" />
                Add new buyer{query.trim() ? ` “${query.trim()}”` : ""}
              </CommandItem>
            </CommandGroup>
          </CommandList>
          <p className="px-3 py-2 text-xs text-muted-foreground border-t">
            New buyers are saved to Customers when the invoice or draft is saved.
          </p>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
