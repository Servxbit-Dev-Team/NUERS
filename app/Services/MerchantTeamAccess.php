<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Human delegation is distinct from merchant ownership, API credentials and BIR authority. */
class MerchantTeamAccess
{
    public const OWNER_ROLE = 'Merchant Admin';

    public const PERMISSIONS = [
        'documents.view' => 'View invoices & receipts', 'documents.export' => 'Download document copies / CSV',
        'documents.create' => 'Create invoices (business-wide)', 'customers.view' => 'View customers (business-wide)',
        'customers.manage' => 'Manage customers (business-wide)', 'reports.view' => 'View sales reports (business-wide)',
        'reports.export' => 'Export sales reports (business-wide)', 'wallet.view' => 'View wallet (business-wide)',
        'integrations.view' => 'View integration metadata (business-wide)',
    ];

    public function ready(): bool
    {
        return Schema::hasTable('merchant_team_members');
    }

    public function membership(string $user): ?object
    {
        return $this->ready() ? DB::table('merchant_team_members')->where('user_id', $user)->first() : null;
    }

    public function owner(string $user): bool
    {
        return DB::table('merchants')->where('merchant_account_id', $user)->exists();
    }

    public function context(Request $r): ?object
    {
        $u = $r->attributes->get('api_actor');
        if (! $u || $this->owner((string) $u->id)) {
            return null;
        }

        return $this->membership((string) $u->id);
    }

    /** Recheck delegated mutations after the controller has locked the merchant row. */
    public function recheckMutation(Request $r): void
    {
        if ($this->context($r)) {
            app(ApiAccess::class)->require($r, ['merchant']);
            $this->enforce($r);
        }
    }

    public function permissions(object $m): array
    {
        if ($m->status !== 'active') {
            return [];
        }
        $role = DB::table('merchant_team_roles')->where('id', $m->role_id)->where('merchant_id', $m->merchant_id)->first();

        return array_values(array_intersect(array_keys(self::PERMISSIONS), json_decode($role->permissions ?? '[]', true)));
    }

    public function enforce(Request $r): void
    {
        $u = $r->attributes->get('api_actor');
        if (! $u || $r->attributes->get('api_role') !== 'merchant' || $this->owner((string) $u->id)) {
            return;
        }
        $m = $this->membership((string) $u->id);
        $path = $r->decodedPath();
        $self = Str::is(['api/auth/mfa/*', 'api/auth/change-password', 'api/auth/notification-preferences', 'api/auth/resend-verification'], $path)
          || ($path === 'api/tables/profiles' && $r->isMethod('GET') && (string) (json_decode($r->query('eq', '{}'), true)['id'] ?? '') === (string) $u->id);
        if ($self) {
            return;
        }
        // Legacy unlinked merchant accounts retain their existing empty projections.
        // Accepted work identities always have a preserved membership, including revoked rows.
        if (! $m) {
            return;
        }
        abort_unless($m->status === 'active', 403, 'No active merchant team access. Contact your business owner.');
        $business = DB::table('merchants')->where('id', $m->merchant_id)->first();
        abort_unless($business && ! in_array($business->status, ['suspended', 'inactive'], true), 403, 'Business access is suspended.');
        if ($path === 'api/merchant/access') {
            return;
        }
        $permission = null;
        if ($r->isMethod('GET')) {
            if (Str::is(['api/merchant/records', 'api/merchant/records/*'], $path)) {
                $permission = str_ends_with($path, '/export') ? 'documents.export' : 'documents.view';
            } elseif (Str::is('api/document-copies/*/*/pdf', $path)) {
                $permission = 'documents.export';
            } elseif (Str::is('api/document-copies/*/*', $path)) {
                $permission = 'documents.view';
            } elseif (Str::is(['api/business-invoices', 'api/business-invoices/*'], $path)) {
                $permission = 'documents.view';
            } elseif (Str::is(['api/merchant/customers', 'api/merchant/customers/*'], $path)) {
                $permission = str_ends_with($path, '/export') ? 'customers.manage' : 'customers.view';
            } elseif ($path === 'api/merchant/sales-reports') {
                $permission = 'reports.view';
            } elseif (Str::is(['api/merchant/sales-reports/export', 'api/merchant/sales-reports/exports', 'api/merchant/sales-reports/exports/*'], $path)) {
                $permission = 'reports.export';
            } elseif (Str::is(['api/merchant/wallet', 'api/merchant/wallet/statement'], $path)) {
                $permission = 'wallet.view';
            } elseif (Str::is(['api/merchant/sandbox-records','api/merchant/sandbox-records/*', 'api/merchant/api-keys', 'api/merchant/api-keys/logs', 'api/merchant/webhooks', 'api/merchant/webhooks/*', 'api/merchant/pos-devices', 'api/merchant/api-branches'], $path)) {
                $permission = 'integrations.view';
            }
            // Identity lookup exposes only the linked business's existing profile, never a membership grant.
            elseif (in_array($path, ['api/business-accounts/current', 'api/dashboard-revision'], true)) {
                $this->sessionMfa($u);

                return;
            }
        } elseif ($path === 'api/merchant/sales-reports/exports' && $r->isMethod('POST')) {
            $permission = 'reports.export';
        } elseif ($path === 'api/business-invoices' && $r->isMethod('POST')) {
            $permission = 'documents.create';
        } elseif (Str::is(['api/merchant/customers', 'api/merchant/customers/*'], $path) && in_array($r->method(), ['POST', 'PATCH'], true)) {
            $permission = 'customers.manage';
        }
        $p = $this->permissions($m);
        abort_unless($permission && in_array($permission, $p, true), 403, 'Your merchant role does not permit this operation.');
        abort_unless(in_array('documents.view', $p, true) || ! str_starts_with($permission, 'documents.'), 403, 'Document view permission is required.');
        // Only document reads/exports have branch and source-terminal projections. All other modules fail closed.
        $narrow = $m->scope !== 'all' || $m->terminal_scope !== 'all';
        abort_if($narrow && (! in_array($permission, ['documents.view', 'documents.export'], true) || $path === 'api/business-invoices' || Str::is('api/business-invoices/*', $path)), 403, 'This workflow requires business-wide access. Use the scoped Invoices & Receipts workspace.');
        $this->sessionMfa($u);
    }

    private function sessionMfa(object $u): void
    {
        if (config('security.enforce_privileged_mfa') && ! app(MfaSession::class)->verified($u)) {
            abort(response()->json(['message' => 'Complete multifactor verification for team access.', 'code' => 'MFA_REQUIRED', 'enrollment_required' => ! $u->mfa_secret], 403));
        }
    }

    public function scopeRecords(Builder $q, string $alias = 'r'): Builder
    {
        $m = $this->context(request());
        if (! $m) {
            return $q;
        }
        if ($m->scope !== 'all' || $m->terminal_scope !== 'all') {
            $q->where($alias.'.document_source', '!=', 'payment');
        } // Payment ledger lacks immutable terminal provenance.
        if ($m->scope !== 'all') {
            $codes = DB::table('merchant_branches')->where('merchant_id', $m->merchant_id)->where('status', 'active')->whereIn('id', json_decode($m->branch_ids, true))->whereNotNull('bir_branch_code')->pluck('bir_branch_code')->all();
            $q->whereIn($alias.'.branch', $codes);
        }
        if ($m->terminal_scope !== 'all') {
            $ids = DB::table('merchant_terminals')->where('merchant_id', $m->merchant_id)->where('status', 'active')->whereIn('id', json_decode($m->terminal_ids, true))->pluck('id')->all();
            $q->where(function ($s) use ($alias, $ids, $m) {
                foreach (['invoice' => 'business_invoices', 'receipt' => 'transaction_receipts'] as $source => $table) {
                    $s->orWhere(function ($a) use ($alias, $ids, $m, $source, $table) {
                        $a->where($alias.'.document_source', $source)->whereExists(fn ($b) => $b->selectRaw('1')->from($table.' as ts')->whereColumn('ts.id', $alias.'.document_id')->where('ts.merchant_id', $m->merchant_id)->whereIn('ts.issuer_terminal_id', $ids));
                    });
                }
            });
        }

        return $q;
    }

    public function assertDocument(string $source, object $row): void
    {
        $m = $this->context(request());
        if (! $m) {
            return;
        }
        $q = DB::query()->fromSub(DB::query()->selectRaw('? as document_source, ? as document_id, ? as branch', [$source, $row->id, $row->seller_branch_code ?? null]), 'r');
        abort_unless($this->scopeRecords($q)->exists(), 404, 'Document not available within your access scope.');
    }

    public function event(string $merchant, string $id, string $actor, string $action, array $details): void
    {
        $event = (string) Str::uuid();
        DB::table('merchant_team_events')->insert(['id' => $event, 'merchant_id' => $merchant, 'subject_id' => $id, 'actor_id' => $actor, 'action' => $action, 'details_encrypted' => Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR)), 'created_at' => now()]);
        app(BirCompliance::class)->audit('merchant_team.'.$action, $id, $actor, ['merchant_id' => $merchant, 'event_id' => $event, 'outcome' => 'success', 'actor_role' => DB::table('profiles')->where('id', $actor)->value('role'), 'correlation_id' => $event, 'details_hash' => hash('sha256', json_encode($details, JSON_THROW_ON_ERROR))]);
    }

    public function defaults(): array
    {
        return [
            ['Merchant Admin', 'Business-wide operational access. Team grants remain owner-controlled.', 'blue', array_keys(self::PERMISSIONS)],
            ['Branch Manager', 'Read and export documents within assigned branches / terminals.', 'green', ['documents.view', 'documents.export']],
            ['Cashier', 'Create invoices and manage buyers with business-wide access.', 'purple', ['documents.view', 'documents.create', 'customers.view', 'customers.manage']],
            ['Accountant', 'Review documents and sales reports.', 'orange', ['documents.view', 'documents.export', 'reports.view', 'reports.export']],
            ['Compliance Officer', 'Review recorded documents and reporting evidence. No BIR approval authority.', 'pink', ['documents.view', 'documents.export', 'reports.view']],
            ['Viewer / Auditor', 'Read recorded documents within the assigned access scope.', 'cyan', ['documents.view']],
            ['API Operator', 'Inspect integration metadata; credential changes remain owner-controlled.', 'slate', ['integrations.view']],
        ];
    }
}
