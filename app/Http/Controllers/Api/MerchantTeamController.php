<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\ApiAccess;
use App\Services\AtomicQuota;
use App\Services\MerchantTeamAccess;
use App\Services\PlatformMail;
use App\Services\PlatformNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Ramsey\Uuid\Uuid;

class MerchantTeamController
{
    private function owner(Request $r, bool $write = false): object
    {
        $a = app(ApiAccess::class);
        $u = $a->require($r, ['merchant', 'admin', 'super_admin']);
        $m = DB::table('merchants')->where('merchant_account_id', $u->id)->orderBy('id')->first();
        abort_unless($m, 403, 'Team access is managed by the registered business owner.');
        if ($write) {
            abort_if(in_array($m->status, ['suspended', 'inactive'], true), 403, 'Business access is suspended.');
            if (config('security.enforce_privileged_mfa') && ! app(\App\Services\MfaSession::class)->verified($u)) {
                abort(response()->json(['message' => 'Verify MFA before changing merchant access.', 'code' => 'MFA_REQUIRED', 'enrollment_required' => ! $u->mfa_secret], 403));
            }
            app(AtomicQuota::class)->admit('merchant-team-write:'.$m->id, 300, 30);
        }

        return $m;
    }

    private function roles(string $id): array
    {
        $rows = DB::table('merchant_team_roles')->where('merchant_id', $id)->orderBy('created_at')->orderBy('id')->get()->map(fn ($r) => [...(array) $r, 'permissions' => json_decode($r->permissions, true)])->all();
        if ($rows) {
            $order = array_column(app(MerchantTeamAccess::class)->defaults(), 0);
            usort($rows, fn ($a, $b) => (array_search($a['name'], $order, true) === false ? 99 : array_search($a['name'], $order, true)) <=> (array_search($b['name'], $order, true) === false ? 99 : array_search($b['name'], $order, true)));

            return $rows;
        }

        return array_map(fn ($x) => ['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'nuers-team:'.$id.':'.$x[0])->toString(), 'merchant_id' => $id, 'name' => $x[0], 'description' => $x[1], 'color' => $x[2], 'permissions' => $x[3], 'revision' => 1], app(MerchantTeamAccess::class)->defaults());
    }

    private function directoryRoles(string $merchant): array
    {
        $roles = $this->roles($merchant);
        if (! collect($roles)->contains('name', MerchantTeamAccess::OWNER_ROLE)) {
            array_unshift($roles, ['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'nuers-business-admin:'.$merchant)->toString(), 'merchant_id' => $merchant, 'name' => MerchantTeamAccess::OWNER_ROLE, 'description' => 'Registered business owner. Full business administration; ownership remains protected.', 'color' => 'blue', 'permissions' => array_keys(MerchantTeamAccess::PERMISSIONS), 'revision' => 0, 'owner_only' => true]);
        }

        return $roles;
    }

    private function ensureRoles(string $id): void
    {
        if (! DB::table('merchant_team_roles')->where('merchant_id', $id)->exists()) {
            foreach ($this->roles($id) as $r) {
                DB::table('merchant_team_roles')->insert([...$r, 'permissions' => json_encode($r['permissions']), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function iso(?string $value): ?string
    {
        return $value ? CarbonImmutable::parse($value, config('app.timezone'))->toIso8601String() : null;
    }

    private function memberQuery(string $merchant): Builder
    {
        return DB::table('merchant_team_members as tm')->leftJoin('users as tu', 'tu.id', '=', 'tm.user_id')->where('tm.merchant_id', $merchant)
            ->select('tm.*', 'tu.api_authenticated_at as team_last_login')->selectRaw("CASE WHEN tu.mfa_secret IS NULL OR tu.mfa_secret='' THEN 0 ELSE 1 END as team_mfa");
    }

    private function row(object $r): array
    {
        $p = json_decode(Crypt::decryptString($r->profile_encrypted), true, flags: JSON_THROW_ON_ERROR);

        return ['id' => $r->id, 'user_id' => $r->user_id, ...$p, 'role_id' => $r->role_id, 'scope' => $r->scope, 'branch_ids' => json_decode($r->branch_ids, true), 'terminal_scope' => $r->terminal_scope, 'terminal_ids' => json_decode($r->terminal_ids, true), 'status' => $r->status === 'invited' && now()->gte($r->expires_at) ? 'expired' : $r->status, 'revision' => $r->revision, 'expires_at' => $this->iso($r->expires_at), 'last_login' => $this->iso($r->team_last_login), 'mfa_enabled' => (bool) $r->team_mfa, 'created_at' => $this->iso($r->created_at), 'protected' => false];
    }

    public function access(Request $r)
    {
        $u = app(ApiAccess::class)->require($r, ['merchant', 'admin', 'super_admin']);
        $m = app(ApiAccess::class)->merchant($r);
        $member = app(MerchantTeamAccess::class)->context($r);
        $owner = ! $member;

        return response()->json(['owner' => $owner, 'merchant' => ['id' => $m->id, 'name' => $m->business_name], 'role' => $owner ? MerchantTeamAccess::OWNER_ROLE : DB::table('merchant_team_roles')->where('id', $member->role_id)->value('name'), 'permissions' => $owner ? array_keys(MerchantTeamAccess::PERMISSIONS) : app(MerchantTeamAccess::class)->permissions($member), 'scope' => $owner ? 'all' : $member->scope, 'terminal_scope' => $owner ? 'all' : $member->terminal_scope])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $r)
    {
        $m = $this->owner($r);
        $v = $r->validate(['search' => 'nullable|string|max:120', 'role' => 'nullable|uuid', 'branch' => 'nullable|uuid', 'status' => 'nullable|in:active,invited,expired,suspended,revoked', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|in:8,10,25,50']);
        // Decrypt only this owner's tenant directory; no cross-tenant PII or plaintext indexed email.
        $all = $this->memberQuery((string) $m->id)->orderBy('tm.created_at')->orderBy('tm.id')->get()->map(fn ($x) => $this->row($x));
        $u = $r->attributes->get('api_actor');
        $roles = $this->directoryRoles((string) $m->id);
        $ownerRole = collect($roles)->firstWhere('name', MerchantTeamAccess::OWNER_ROLE);
        $all->prepend(['id' => 'owner', 'user_id' => (string) $u->id, 'name' => $m->owner_name ?: ($u->name ?? $m->business_name), 'email' => $u->email, 'mobile' => $m->phone ?? '', 'role_id' => $ownerRole['id'], 'scope' => 'all', 'branch_ids' => [], 'terminal_scope' => 'all', 'terminal_ids' => [], 'status' => 'active', 'revision' => 0, 'expires_at' => null, 'last_login' => $u->api_authenticated_at, 'mfa_enabled' => (bool) $u->mfa_secret, 'created_at' => $u->created_at, 'protected' => true]);
        $roleLabels = collect($roles)->pluck('name', 'id')->all();
        $branchLabels = DB::table('merchant_branches')->where('merchant_id', $m->id)->pluck('name', 'id')->all();
        $filtered = $all->filter(function ($x) use ($v, $roleLabels, $branchLabels) {
            return (empty($v['search']) || str_contains(mb_strtolower($x['name'].' '.$x['email'].' '.$x['id'].' '.($x['protected'] ? MerchantTeamAccess::OWNER_ROLE : ($roleLabels[$x['role_id']] ?? '')).' '.($x['scope'] === 'all' ? 'All Branches' : implode(' ', array_map(fn ($id) => $branchLabels[$id] ?? '', $x['branch_ids'])))), mb_strtolower($v['search']))) && (empty($v['role']) || $v['role'] === $x['role_id']) && (empty($v['status']) || $v['status'] === $x['status']) && (empty($v['branch']) || $x['scope'] === 'all' || in_array($v['branch'], $x['branch_ids'], true));
        })->values();
        $per = (int) ($v['per_page'] ?? 8);
        $page = (int) ($v['page'] ?? 1);
        $events = DB::table('merchant_team_events')->where('merchant_id', $m->id)->orderByDesc('created_at')->limit(12)->get(['id', 'subject_id', 'actor_id', 'action', 'created_at'])->map(fn ($event) => [...(array) $event, 'created_at' => $this->iso($event->created_at)]);

        return response()->json(['merchant' => ['id' => $m->id, 'name' => $m->business_name], 'summary' => ['total' => $all->whereIn('status', ['active', 'suspended'])->count(), 'active' => $all->where('status', 'active')->count(), 'invited' => $all->where('status', 'invited')->count(), 'roles' => count($roles), 'mfa' => $all->where('status', 'active')->where('mfa_enabled', true)->count(), 'scoped' => $all->where('status', 'active')->filter(fn ($x) => $x['scope'] !== 'all' || $x['terminal_scope'] !== 'all')->count()],
            'members' => ['data' => $filtered->slice(($page - 1) * $per, $per)->values(), 'total' => $filtered->count(), 'page' => $page, 'last_page' => max(1, (int) ceil($filtered->count() / $per))],
            'roles' => array_map(fn ($role) => [...$role, 'user_count' => $all->where('role_id', $role['id'])->where('status', 'active')->count()], $roles), 'permissions' => MerchantTeamAccess::PERMISSIONS, 'branches' => DB::table('merchant_branches')->where('merchant_id', $m->id)->get(['id', 'name', 'code', 'bir_branch_code', 'status']),
            'terminals' => DB::table('merchant_terminals')->where('merchant_id', $m->id)->get(['id', 'name', 'code', 'branch_id', 'status']),
            'invites' => $all->whereIn('status', ['invited', 'expired'])->values(), 'events' => $events, 'security' => ['password_min' => 12, 'idle_minutes' => config('security.token_minutes'), 'absolute_minutes' => config('security.session_absolute_minutes'), 'step_up_minutes' => null, 'mfa_challenge' => config('security.mfa_challenge'), 'mfa_enforced' => config('security.enforce_privileged_mfa'), 'access_changes_ready' => ! config('security.enforce_privileged_mfa') || app(\App\Services\MfaSession::class)->verified($u), 'smtp_enabled' => ! empty(app(PlatformMail::class)->settings()['enabled'])]])->header('Cache-Control', 'private, no-store');
    }

    private function grants(Request $r, object $m): array
    {
        $v = $r->validate(['role_id' => 'required|uuid', 'scope' => 'required|in:all,branches', 'branch_ids' => 'present|array|max:100', 'branch_ids.*' => 'uuid|distinct', 'terminal_scope' => 'required|in:all,terminals', 'terminal_ids' => 'present|array|max:100', 'terminal_ids.*' => 'uuid|distinct', 'reason' => 'required|string|min:10|max:1000']);
        abort_unless(DB::table('merchant_team_roles')->where('merchant_id', $m->id)->where('id', $v['role_id'])->exists(), 422, 'Select a role from this business.');
        $branches = DB::table('merchant_branches')->where('merchant_id', $m->id)->where('status', 'active')->whereIn('id', $v['branch_ids'])->get();
        abort_unless(count($v['branch_ids']) === $branches->count(), 422, 'Select active branches belonging to this business.');
        if ($v['scope'] === 'branches') {
            abort_unless(count($v['branch_ids']) > 0 && $branches->whereNull('bir_branch_code')->isEmpty(), 422, 'Select at least one branch with an exact registered five-digit branch code.');
        } else {
            abort_unless(! $v['branch_ids'], 422, 'Business-wide scope must not contain branch selections.');
        }
        $terminals = DB::table('merchant_terminals')->where('merchant_id', $m->id)->where('status', 'active')->whereIn('id', $v['terminal_ids'])->get();
        abort_unless(count($v['terminal_ids']) === $terminals->count(), 422, 'Select active terminals belonging to this business.');
        if ($v['terminal_scope'] === 'terminals') {
            abort_unless($terminals->count() > 0 && ($v['scope'] === 'all' || $terminals->every(fn ($t) => in_array($t->branch_id, $v['branch_ids'], true))), 422, 'Selected terminals must belong to the assigned branches.');
        } else {
            abort_unless(! $v['terminal_ids'], 422, 'All-terminal scope must not contain terminal selections.');
        }
        $p = json_decode(DB::table('merchant_team_roles')->where('id', $v['role_id'])->value('permissions'), true);
        if ($v['scope'] !== 'all' || $v['terminal_scope'] !== 'all') {
            abort_unless(! array_diff($p, ['documents.view', 'documents.export']), 422, 'This role includes business-wide workflows. Choose a document-only role for branch or terminal access.');
        }

        return $v;
    }

    private function locked(Request $r, callable $f)
    {
        $m = $this->owner($r, true);

        return DB::transaction(function () use ($r, $m, $f) {
            DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            $this->ensureRoles((string) $m->id);

            return $f($m, (string) $r->attributes->get('api_actor')->id);
        });
    }

    private function inviteMail(object $m, object $row, string $token): void
    {
        $p = json_decode(Crypt::decryptString($row->profile_encrypted), true);
        $n = app(PlatformNotifications::class);
        abort_unless(! empty(app(PlatformMail::class)->settings()['enabled']) && $n->ready(), 503, 'Configure and enable NUERS email delivery before inviting users.');
        $id = $n->queue($p['email'], 'merchant.team.invitation', $row->invite_hash, 'You are invited to '.$m->business_name, 'You have been invited to join '.$m->business_name.' on NUERS. Review the assigned role and access scope before accepting. This link expires in 72 hours, works once, and is intended only for this email address. Never share this link. MFA is required for delegated work access. Merchant access does not grant ownership or BIR authority.', 'security', merchantId: (string) $m->id, action: $n->url('accept-team-invite').'#token='.$token, expires: now()->addHours(72), context: ['member_id' => $row->id, 'token_hash' => $row->invite_hash]);
        abort_unless($id, 503, 'The invitation could not be queued. No access was granted.');
    }

    public function invite(Request $r)
    {
        return $this->locked($r, function ($m, $actor) use ($r) {
            $v = $r->validate(['name' => 'required|string|min:2|max:120', 'email' => 'required|email:rfc|max:254', 'mobile' => 'nullable|string|max:40']);
            $g = $this->grants($r, $m);
            $email = strtolower(trim($v['email']));
            abort_if(DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists(), 422, 'Use a separate work email that is not already registered with NUERS.');
            abort_if(DB::table('merchant_team_members')->where('merchant_id', $m->id)->where('email_hash', hash('sha256', $email))->exists(), 409, 'This person already has a team record. Review or resend their existing invitation.');
            abort_if(DB::table('merchant_team_members')->where('merchant_id', $m->id)->count() >= 1000, 422, 'The directory supports up to 1,000 work records per business. Contact NUERS support before adding more.');
            $id = (string) Str::uuid();
            $token = Str::random(64);
            DB::table('merchant_team_members')->insert(['id' => $id, 'merchant_id' => $m->id, 'user_id' => null, 'role_id' => $g['role_id'], 'profile_encrypted' => Crypt::encryptString(json_encode(['name' => $v['name'], 'email' => $email, 'mobile' => $v['mobile'] ?? ''])), 'email_hash' => hash('sha256', $email), 'scope' => $g['scope'], 'branch_ids' => json_encode($g['branch_ids']), 'terminal_scope' => $g['terminal_scope'], 'terminal_ids' => json_encode($g['terminal_ids']), 'status' => 'invited', 'invite_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(72), 'invited_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('merchant_team_members')->where('id', $id)->first();
            $this->inviteMail($m, $row, $token);
            $this->event($m, $id, $actor, 'invited', ['reason' => $g['reason'], 'role_id' => $g['role_id'], 'scope' => $g['scope']]);

            return response()->json(['message' => 'Invitation queued. Access starts only after acceptance and MFA.', 'id' => $id], 201);
        });
    }

    private function event(object $m, string $id, string $actor, string $action, array $details): void
    {
        app(MerchantTeamAccess::class)->event((string) $m->id, $id, $actor, $action, $details);
    }

    private function workIdentity(?string $id): bool
    {
        return $id && DB::table('profiles')->where('id', $id)->value('role') === 'merchant'
            && ! DB::table('merchants')->where('merchant_account_id', $id)->exists();
    }

    public function update(Request $r, string $id)
    {
        return $this->locked($r, function ($m, $actor) use ($r, $id) {
            $v = $r->validate(['revision' => 'required|integer|min:1', 'action' => 'required|in:grants,suspend,activate,revoke,revoke_sessions,resend,reset_password', 'reason' => 'required|string|min:10|max:1000']);
            $row = DB::table('merchant_team_members')->where('merchant_id', $m->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless((int) $row->revision === (int) $v['revision'], 409, 'This team record changed. Refresh and review the current access before trying again.');
            if (in_array($v['action'], ['reset_password', 'revoke_sessions'], true)) {
                abort_unless($this->workIdentity($row->user_id), 409, 'This identity is managed outside this merchant team. Contact a platform administrator.');
            }
            if ($v['action'] === 'reset_password') {
                abort_unless($row->user_id && $row->status === 'active', 409, 'Only active accepted work accounts can receive a reset link.');
                $email = DB::table('users')->where('id', $row->user_id)->value('email');
                $reset = Request::create('/api/auth/forgot-password', 'POST', ['email' => $email]);
                $result = app(AccountSecurityController::class)->forgot($reset);
                $this->event($m, $id, $actor, 'reset_password_requested', ['reason' => $v['reason']]);

                return $result;
            }
            $changes = ['revision' => $row->revision + 1, 'updated_at' => now()];
            if ($v['action'] === 'grants') {
                $g = $this->grants($r, $m);
                abort_if(in_array($row->status, ['invited', 'revoked'], true), 409, 'Accept or cancel the existing invitation before changing access. Expired invitations can be resent.');
                foreach (['role_id', 'scope', 'terminal_scope'] as $k) {
                    $changes[$k] = $g[$k];
                }foreach (['branch_ids', 'terminal_ids'] as $k) {
                    $changes[$k] = json_encode($g[$k]);
                }
            } elseif ($v['action'] === 'resend') {
                abort_unless($row->status === 'invited', 409, 'Only pending or expired invitations can be resent.');
                abort_if(now()->diffInSeconds($row->updated_at, true) < 60, 429, 'Wait one minute before resending.');
                $token = Str::random(64);
                $changes['invite_hash'] = hash('sha256', $token);
                $changes['expires_at'] = now()->addHours(72);
            } elseif ($v['action'] !== 'revoke_sessions') {
                $allowed = match ($v['action']) {
                    'suspend' => ['active'],'activate' => ['suspended'],'revoke' => ['invited', 'active', 'suspended'],default => []
                };
                abort_unless(in_array($row->status, $allowed, true), 409, 'This transition is unavailable for the current account state.');
                $changes['status'] = match ($v['action']) {
                    'suspend' => 'suspended','activate' => 'active','revoke' => 'revoked'
                };
                if ($v['action'] === 'revoke') {
                    $changes['invite_hash'] = null;
                }
            } else {
                abort_unless($row->user_id, 409, 'This invitation has no signed-in account.');
            }
            DB::table('merchant_team_members')->where('id', $id)->update($changes);
            if ($this->workIdentity($row->user_id)) {
                DB::table('users')->where('id', $row->user_id)->update(app(ApiAccess::class)->revocation());
            }
            if (isset($token)) {
                $this->inviteMail($m, DB::table('merchant_team_members')->where('id', $id)->first(), $token);
            }
            $this->event($m, $id, $actor, $v['action'], ['reason' => $v['reason'], 'previous_revision' => $row->revision, 'changes' => $changes]);
            if ($row->user_id) {
                app(PlatformNotifications::class)->user($row->user_id, 'merchant.team.access_changed', $id.':'.$changes['revision'], 'Your merchant access changed', 'The business owner updated your NUERS work access or revoked existing sessions. Sign in again to review your current role. Contact the business owner if this change was unexpected.', 'security', (string) $m->id);
            }

            return ['message' => 'Access change recorded. The updated permissions apply to this merchant membership.'];
        });
    }

    public function role(Request $r, ?string $id = null)
    {
        return $this->locked($r, function ($m, $actor) use ($r, $id) {
            $v = $r->validate(['name' => 'required|string|min:2|max:80', 'description' => 'required|string|max:300', 'color' => 'required|in:blue,green,purple,orange,pink,cyan,slate', 'permissions' => 'present|array|max:20', 'permissions.*' => ['string', 'distinct', Rule::in(array_keys(MerchantTeamAccess::PERMISSIONS))], 'revision' => 'nullable|integer|min:1', 'reason' => 'required|string|min:10|max:1000']);
            foreach (['documents.export' => 'documents.view', 'documents.create' => 'documents.view', 'customers.manage' => 'customers.view', 'reports.export' => 'reports.view'] as $a => $b) {
                abort_if(in_array($a, $v['permissions'], true) && ! in_array($b, $v['permissions'], true), 422, 'The selected action requires its view permission.');
            }
            abort_if(DB::table('merchant_team_roles')->where('merchant_id', $m->id)->where('name', $v['name'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists(), 422, 'A role with that name already exists.');
            $old = $id ? DB::table('merchant_team_roles')->where('merchant_id', $m->id)->where('id', $id)->lockForUpdate()->first() : null;
            if ($id) {
                abort_unless($old, 404);
                abort_unless((int) $old->revision === (int) ($v['revision'] ?? 0), 409, 'Role changed. Refresh before saving.');
            }
            abort_if(DB::table('merchant_team_roles')->where('merchant_id', $m->id)->count() >= 25 && ! $id, 422, 'A maximum of 25 roles is supported per business.');
            if ($id && array_diff($v['permissions'], ['documents.view', 'documents.export'])) {
                abort_if(DB::table('merchant_team_members')->where('role_id', $id)->whereIn('status', ['active', 'suspended', 'invited'])->where(fn ($q) => $q->where('scope', '!=', 'all')->orWhere('terminal_scope', '!=', 'all'))->exists(), 422, 'This role has scoped users. Remove business-wide permissions or reassign those users first.');
            }
            $id ??= (string) Str::uuid();
            $values = ['merchant_id' => $m->id, 'name' => $v['name'], 'description' => $v['description'], 'color' => $v['color'], 'permissions' => json_encode($v['permissions']), 'revision' => $old ? $old->revision + 1 : 1, 'updated_at' => now()];
            if ($old) {
                DB::table('merchant_team_roles')->where('id', $id)->update($values);
            } else {
                DB::table('merchant_team_roles')->insert(['id' => $id, ...$values, 'created_at' => now()]);
            }
            foreach (DB::table('merchant_team_members')->where('role_id', $id)->whereNotNull('user_id')->pluck('user_id') as $user) {
                if (! $this->workIdentity($user)) {
                    continue;
                }
                DB::table('users')->where('id', $user)->update(app(ApiAccess::class)->revocation());
                app(PlatformNotifications::class)->user($user, 'merchant.team.role_changed', $id.':'.$values['revision'], 'Your work role was updated', 'The business owner updated your NUERS role permissions. Existing sessions were revoked. Sign in again to review the current access.', 'security', (string) $m->id);
            }
            $this->event($m, $id, $actor, 'role_saved', ['reason' => $v['reason'], 'permissions' => $v['permissions'], 'revision' => $values['revision']]);

            return ['message' => 'Role saved. Affected sessions revoked.'];
        });
    }

    public function export(Request $r)
    {
        $m = $this->owner($r, true);
        $r->validate(['reason' => 'required|string|min:10|max:1000']);
        $rows = $this->index($r)->getData(true);
        $all = $this->memberQuery((string) $m->id)->orderBy('tm.created_at')->orderBy('tm.id')->get()->map(fn ($x) => $this->row($x));
        $roles = collect($rows['roles'])->keyBy('id');
        $owner = $r->attributes->get('api_actor');
        $all->prepend(['id' => 'owner', 'name' => $m->owner_name ?: $owner->name, 'email' => $owner->email, 'role_id' => 'owner', 'status' => 'active', 'scope' => 'all', 'mfa_enabled' => (bool) $owner->mfa_secret]);
        $roles->put('owner', ['name' => MerchantTeamAccess::OWNER_ROLE]);
        $this->event($m, (string) $m->id, (string) $r->attributes->get('api_actor')->id, 'exported', ['reason' => $r->input('reason'), 'count' => $all->count()]);

        return response()->streamDownload(function () use ($all, $roles) {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['User ID', 'Name', 'Email', 'Role', 'Status', 'Scope', 'MFA enrolled'], escape: '');
            foreach ($all as $x) {
                $line = [$x['id'], $x['name'], $x['email'], $roles[$x['role_id']]['name'] ?? 'Unavailable', $x['status'], $x['scope'], $x['mfa_enabled'] ? 'Yes' : 'No'];
                fputcsv($f, array_map(fn ($s) => preg_match('/^[=+\-@\x00-\x20]/', (string) $s) ? "'".$s : $s, $line), escape: '');
            }fclose($f);
        }, 'NUERS-team-directory.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
    }

    private function invitation(Request $r): object
    {
        $v = $r->validate(['token' => 'required|string|size:64']);
        $row = DB::table('merchant_team_members')->where('invite_hash', hash('sha256', $v['token']))->lockForUpdate()->first();
        abort_unless($row && $row->status === 'invited' && now()->lt($row->expires_at), 422, 'This invitation is invalid, expired or cancelled. Ask the business owner for a new invitation.');
        abort_unless(DB::table('merchants')->where('id', $row->merchant_id)->whereNotIn('status', ['suspended', 'inactive'])->exists(), 403, 'Business access is unavailable.');

        return $row;
    }

    public function preview(Request $r)
    {
        return DB::transaction(function () use ($r) {
            $x = $this->invitation($r);
            $p = json_decode(Crypt::decryptString($x->profile_encrypted), true);

            return response()->json(['name' => $p['name'], 'email' => $p['email'], 'business' => DB::table('merchants')->where('id', $x->merchant_id)->value('business_name'), 'role' => DB::table('merchant_team_roles')->where('id', $x->role_id)->value('name'), 'notice_version' => BusinessRegistrationController::NOTICE_VERSION, 'scope' => $x->scope, 'terminal_scope' => $x->terminal_scope, 'expires_at' => $x->expires_at])->header('Cache-Control', 'no-store');
        });
    }

    public function accept(Request $r)
    {
        $v = $r->validate(['token' => 'required|string|size:64', 'password' => ['required', 'string', 'confirmed', 'max:72', Password::min(12)->letters()->numbers(), function ($a, $value, $fail) {
            if (strlen($value) > 72) {
                $fail('Password must not exceed 72 bytes.');
            }
        }], 'accept_terms' => 'required|accepted', 'notice_version' => ['required', Rule::in([BusinessRegistrationController::NOTICE_VERSION])]]);

        return DB::transaction(function () use ($r, $v) {
            // Serialize acceptance with owner revocation and role changes, then recheck the token under lock.
            $first = DB::table('merchant_team_members')->where('invite_hash', hash('sha256', (string) $r->input('token')))->first();
            abort_unless($first, 422, 'Invalid invitation.');
            DB::table('merchants')->where('id', $first->merchant_id)->lockForUpdate()->first();
            $x = $this->invitation($r);
            $p = json_decode(Crypt::decryptString($x->profile_encrypted), true);
            abort_if(User::whereRaw('LOWER(email) = ?', [$p['email']])->exists(), 409, 'This email is already registered. Contact the business owner for a separate work invitation.');
            $role = DB::table('merchant_team_roles')->where('id', $x->role_id)->where('merchant_id', $x->merchant_id)->first();
            abort_unless($role, 409, 'The assigned role is no longer available.');
            if ($x->scope !== 'all' || $x->terminal_scope !== 'all') {
                abort_unless(! array_diff(json_decode($role->permissions, true), ['documents.view', 'documents.export']), 409, 'The role scope changed. Ask the owner to review the invitation.');
            }
            if ($x->terminal_scope === 'terminals') {
                abort_unless(DB::table('merchant_terminals')->where('merchant_id', $x->merchant_id)->where('status', 'active')->whereIn('id', json_decode($x->terminal_ids, true))->when($x->scope === 'branches', fn ($q) => $q->whereIn('branch_id', json_decode($x->branch_ids, true)))->count() === count(json_decode($x->terminal_ids, true)), 409, 'Assigned terminals changed. Ask the owner to review the invitation.');
            }
            if ($x->scope === 'branches') {
                abort_unless(DB::table('merchant_branches')->where('merchant_id', $x->merchant_id)->where('status', 'active')->whereIn('id', json_decode($x->branch_ids, true))->count() === count(json_decode($x->branch_ids, true)), 409, 'Assigned branches changed. Ask the owner to review the invitation.');
            }
            $u = User::create(['name' => $p['name'], 'email' => $p['email'], 'password' => Hash::make($v['password'])]);
            $u->forceFill(['email_verified_at' => now()])->save();
            DB::table('profiles')->insert(['id' => $u->id, 'full_name' => $p['name'], 'email' => $p['email'], 'role' => 'merchant', 'organization' => DB::table('merchants')->where('id', $x->merchant_id)->value('business_name')]);
            DB::table('merchant_team_members')->where('id', $x->id)->update(['user_id' => (string) $u->id, 'status' => 'active', 'invite_hash' => null, 'accepted_at' => now(), 'revision' => $x->revision + 1, 'updated_at' => now()]);
            $this->event((object) ['id' => $x->merchant_id], $x->id, (string) $u->id, 'accepted', ['role_id' => $role->id, 'terms_accepted' => true, 'terms_version' => BusinessRegistrationController::NOTICE_VERSION, 'privacy_acknowledged' => true, 'privacy_version' => BusinessRegistrationController::NOTICE_VERSION]);
            app(PlatformNotifications::class)->merchant($x->merchant_id, 'merchant.team.accepted', $x->id, 'A team invitation was accepted', 'A new work account accepted its invitation. Review the member and assigned role in User Management.', 'security');

            return ['message' => 'Invitation accepted. Sign in with this work email, then enroll in MFA.', 'email' => $p['email']];
        });
    }
}
