import { useEffect, useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { Activity } from "lucide-react";

const agencies = [
  "City Treasurer - Makati",
  "Provincial Capitol - Cebu",
  "Public Hospital Cashier",
  "State University Registrar",
  "Business Permit Office",
  "Regulatory Fees Counter",
  "Court Fee Collection",
  "Utility Payment Office",
  "Municipal Treasury",
  "Government Service Center",
];

const types = ["Tax Collection", "Permit Fee", "Healthcare Payment", "Tuition Fee", "Service Charge"];

function generateTransaction() {
  return {
    id: Math.random().toString(36).slice(2, 10).toUpperCase(),
    agency: agencies[Math.floor(Math.random() * agencies.length)],
    amount: (Math.random() * 15000 + 50).toFixed(2),
    type: types[Math.floor(Math.random() * types.length)],
    region: ["NCR", "Region III", "Region IV-A", "Region VII", "Region XI"][Math.floor(Math.random() * 5)],
  };
}

export function LiveTransactionTicker() {
  const [transactions, setTransactions] = useState(() =>
    Array.from({ length: 5 }, generateTransaction)
  );

  useEffect(() => {
    const interval = setInterval(() => {
      setTransactions((prev) => [generateTransaction(), ...prev.slice(0, 4)]);
    }, 2000);
    return () => clearInterval(interval);
  }, []);

  return (
    <div className="border-b bg-secondary/30 py-3 overflow-hidden">
      <div className="mx-auto max-w-7xl px-6">
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-2 shrink-0">
            <Activity className="h-3.5 w-3.5 text-success animate-pulse" />
            <span className="text-xs font-semibold text-foreground">LIVE</span>
          </div>
          <div className="flex-1 overflow-hidden">
            <AnimatePresence mode="popLayout">
              <motion.div
                key={transactions[0]?.id}
                initial={{ opacity: 0, y: -20 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: 20 }}
                className="flex items-center gap-6 text-xs"
              >
                <span className="font-mono text-muted-foreground">{transactions[0]?.id}</span>
                <span className="font-medium text-foreground">{transactions[0]?.agency}</span>
                <span className="text-success font-semibold">₱{transactions[0]?.amount}</span>
                <span className="text-muted-foreground hidden sm:inline">{transactions[0]?.type}</span>
                <span className="text-muted-foreground hidden md:inline">{transactions[0]?.region}</span>
              </motion.div>
            </AnimatePresence>
          </div>
          <span className="shrink-0 text-[10px] text-muted-foreground">
            Live government collections
          </span>
        </div>
      </div>
    </div>
  );
}
