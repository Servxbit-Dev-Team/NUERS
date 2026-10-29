import { BirTransmissionWorkspace } from './bir-transmission-center';
import { AdminTransactions } from './transactions';
import { OperationsWorkspace } from "./workspace";
export function TransactionsCenter(){return <AdminTransactions/>;}
export function BirTransmissionCenter(){return <BirTransmissionWorkspace/>;}
export function ReconciliationCenter(){return <OperationsWorkspace mode="reconciliation"/>;}
export function SalesSummaryCenter(){return <OperationsWorkspace mode="sales"/>;}
