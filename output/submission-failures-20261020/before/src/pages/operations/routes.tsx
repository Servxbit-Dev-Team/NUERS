import { AdminTransactions } from './transactions';
import { OperationsWorkspace } from "./workspace";
export function TransactionsCenter(){return <AdminTransactions/>;}
export function BirTransmissionCenter(){return <OperationsWorkspace mode="transmission"/>;}
export function ReconciliationCenter(){return <OperationsWorkspace mode="reconciliation"/>;}
export function SalesSummaryCenter(){return <OperationsWorkspace mode="sales"/>;}
