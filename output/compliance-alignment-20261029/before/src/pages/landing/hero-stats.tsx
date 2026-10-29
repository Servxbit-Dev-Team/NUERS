import { useEffect, useState } from "react";
import { motion } from "framer-motion";
import { TrendingUp, ArrowUpRight, Receipt, Users, Activity } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";

export function HeroStats() {
  const [counter, setCounter] = useState(2843921);

  useEffect(() => {
    const interval = setInterval(() => {
      setCounter((prev) => prev + Math.floor(Math.random() * 5) + 1);
    }, 100);
    return () => clearInterval(interval);
  }, []);

  return (
    <div className="relative">
      <div className="absolute -inset-4 rounded-3xl bg-gradient-to-br from-primary/5 via-transparent to-gold/5 blur-xl" />
      <div className="relative grid gap-3 sm:grid-cols-2">
        <motion.div
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ delay: 0.2 }}
        >
          <Card className="border-border/50 bg-card/80 backdrop-blur-sm">
            <CardContent className="p-5">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground">Live Collections</span>
                <Activity className="h-4 w-4 text-success animate-pulse" />
              </div>
              <p className="mt-2 text-2xl font-bold tabular-nums text-foreground">
                {counter.toLocaleString()}
              </p>
              <div className="mt-2 flex items-center gap-1 text-xs text-success">
                <ArrowUpRight className="h-3 w-3" />
                <span>Real-time monitoring</span>
              </div>
            </CardContent>
          </Card>
        </motion.div>

        <motion.div
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ delay: 0.3 }}
        >
          <Card className="border-border/50 bg-card/80 backdrop-blur-sm">
            <CardContent className="p-5">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground">Revenue Today</span>
                <TrendingUp className="h-4 w-4 text-primary" />
              </div>
              <p className="mt-2 text-2xl font-bold text-foreground">₱2.84B</p>
              <div className="mt-2 flex items-center gap-1 text-xs text-success">
                <ArrowUpRight className="h-3 w-3" />
                <span>Across agencies and LGUs</span>
              </div>
            </CardContent>
          </Card>
        </motion.div>

        <motion.div
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ delay: 0.4 }}
        >
          <Card className="border-border/50 bg-card/80 backdrop-blur-sm">
            <CardContent className="p-5">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground">Connected Offices</span>
                <Users className="h-4 w-4 text-primary" />
              </div>
              <p className="mt-2 text-2xl font-bold text-foreground">17 Regions</p>
              <div className="mt-2 flex items-center gap-1 text-xs text-muted-foreground">
                <span>Multi-office visibility</span>
              </div>
            </CardContent>
          </Card>
        </motion.div>

        <motion.div
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ delay: 0.5 }}
        >
          <Card className="border-border/50 bg-card/80 backdrop-blur-sm">
            <CardContent className="p-5">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground">eOR Issued</span>
                <Receipt className="h-4 w-4 text-gold" />
              </div>
              <p className="mt-2 text-2xl font-bold text-foreground">18.4M</p>
              <div className="mt-2 flex items-center gap-1 text-xs text-muted-foreground">
                <span>Digital records and QR verification</span>
              </div>
            </CardContent>
          </Card>
        </motion.div>
      </div>
    </div>
  );
}
