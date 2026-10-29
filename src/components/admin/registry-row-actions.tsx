import { ArrowLeftRight, Eye, KeyRound, MoreHorizontal, Pencil, SearchCheck, ShieldCheck, Wallet } from 'lucide-react';
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem,
  DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type Props = {
  name: string;
  busy: boolean;
  canWrite: boolean;
  canViewWallet: boolean;
  onView: () => void;
  onWallet: () => void;
  onTransactions: () => void;
  onEdit: () => void;
  onReview: () => void;
  onAccess: () => void;
  onCheckTin: () => void;
  onOpenChange: (open: boolean) => void;
};

export function RegistryRowActions({
  name, busy, canWrite, canViewWallet, onView, onWallet, onTransactions, onEdit, onReview,
  onAccess, onCheckTin, onOpenChange,
}: Props) {
  return <div className="br-row-actions" role="group" aria-label={`Actions for ${name}`}>
    <button type="button" className="br-action-view" disabled={busy}
      aria-label={`View details for ${name}`} onClick={onView}>
      <Eye size={16} aria-hidden="true"/><span>View details</span>
    </button>
    <DropdownMenu onOpenChange={onOpenChange}>
      <DropdownMenuTrigger asChild>
        <button type="button" className="br-action-more" disabled={busy}
          aria-label={`More actions for ${name}`} title={`More actions for ${name}`}>
          <MoreHorizontal size={19} aria-hidden="true"/>
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent className="br-action-menu" align="end" sideOffset={8} collisionPadding={12}>
        <DropdownMenuLabel className="br-action-context">
          <span>Merchant actions</span><strong>{name}</strong>
        </DropdownMenuLabel>
        <DropdownMenuSeparator/>
        <DropdownMenuGroup aria-label="Merchant management">
          {canViewWallet && <DropdownMenuItem disabled={busy} onSelect={onTransactions}>
            <ArrowLeftRight aria-hidden="true"/><span>View transactions<small>Records, details and PDF / CSV reports</small></span>
          </DropdownMenuItem>}
          {canViewWallet && <DropdownMenuItem disabled={busy} onSelect={onWallet}>
            <Wallet aria-hidden="true"/><span>Open wallet<small>Balance and transaction history</small></span>
          </DropdownMenuItem>}
          <DropdownMenuItem disabled={!canWrite || busy} onSelect={onEdit}>
            <Pencil aria-hidden="true"/><span>Edit business<small>Registered business information</small></span>
          </DropdownMenuItem>
        </DropdownMenuGroup>
        <DropdownMenuSeparator/>
        <DropdownMenuLabel className="br-action-section">Verification &amp; access</DropdownMenuLabel>
        <DropdownMenuGroup aria-label="Verification and access">
          <DropdownMenuItem disabled={!canWrite || busy} onSelect={onReview}>
            <ShieldCheck aria-hidden="true"/><span>Review account<small>Documents and account status</small></span>
          </DropdownMenuItem>
          <DropdownMenuItem disabled={!canWrite || busy} onSelect={onAccess}>
            <KeyRound aria-hidden="true"/><span>Account access<small>Owner account and invitations</small></span>
          </DropdownMenuItem>
          <DropdownMenuItem disabled={busy} onSelect={onCheckTin}>
            <SearchCheck aria-hidden="true"/><span>Check TIN<small>Format and registry match</small></span>
          </DropdownMenuItem>
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  </div>;
}
