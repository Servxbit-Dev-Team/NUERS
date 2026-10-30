<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MerchantCustomers
{
    public function hash(string $merchant, string $value): string
    {
        return hash_hmac('sha256', $merchant.'|'.$value, config('app.key'));
    }

    public function normalized(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    }

    public function branches(string $merchant)
    {
        $legacy = DB::table('merchant_api_keys')->where('merchant_id', $merchant)->whereNotNull('branch_code')->where('branch_code', '!=', '')->select('branch_code', 'branch_name')->distinct()->get();
        $registry = \Illuminate\Support\Facades\Schema::hasTable('merchant_branches') ? DB::table('merchant_branches')->where('merchant_id', $merchant)->whereNotNull('bir_branch_code')->select('bir_branch_code as branch_code', 'name as branch_name')->get() : collect();
        return $registry->concat($legacy)->unique('branch_code')->sortBy('branch_code')->values();
    }

    public function row(object $r): array
    {
        $p = json_decode(Crypt::decryptString($r->profile_encrypted), true, flags: JSON_THROW_ON_ERROR);

        return ['id' => $r->id, 'code' => $r->code, 'type' => $r->type, 'status' => $r->status, 'group_name' => $r->group_name, 'branch_code' => $r->branch_code, 'revision' => $r->revision, 'created_at' => CarbonImmutable::parse($r->created_at, config('app.timezone'))->toIso8601String(), 'details_reviewed_at' => $r->details_reviewed_at, 'review_reference' => $r->review_reference_encrypted ? Crypt::decryptString($r->review_reference_encrypted) : null, ...$p];
    }

    public function identity(array $v): string
    {
        return $this->normalized($v['name']).'|'.($v['tin'] ?? '').'|'.$this->normalized($v['email'] ?? '').'|'.preg_replace('/\D/', '', $v['phone'] ?? '');
    }

    public function save(string $merchant, string $actor, array $v, ?object $old = null): array
    {
        $profile = array_intersect_key($v, array_flip(['name', 'tin', 'email', 'phone', 'address']));
        foreach (['tin', 'email', 'phone', 'address'] as $key) {
            $profile[$key] = $profile[$key] ?? null;
        }
        $identity = $this->hash($merchant, 'identity|'.$this->identity($profile));
        if (DB::table('merchant_customers')->where('merchant_id', $merchant)->where('identity_hash', $identity)->when($old, fn ($q) => $q->where('id', '!=', $old->id))->exists()) {
            throw ValidationException::withMessages(['name' => 'An identical customer profile already exists. Review that profile instead.']);
        }
        $id = $old?->id ?? (string) Str::uuid();
        $record = [...$this->matchHashes($merchant, $profile), 'merchant_id' => $merchant, 'type' => $v['type'], 'status' => $v['status'], 'group_name' => $v['group_name'], 'branch_code' => $v['branch_code'] ?? null, 'profile_encrypted' => Crypt::encryptString(json_encode($profile, JSON_THROW_ON_ERROR)), 'identity_hash' => $identity, 'has_tin' => ! empty($profile['tin']), 'revision' => $old ? $old->revision + 1 : 1, 'details_reviewed_at' => ! empty($v['review_reference']) ? now() : null, 'reviewed_by' => ! empty($v['review_reference']) ? $actor : null, 'review_reference_encrypted' => ! empty($v['review_reference']) ? Crypt::encryptString($v['review_reference']) : null, 'updated_at' => now()];
        if ($old) {
            DB::table('merchant_customers')->where('id', $id)->update($record);
        } else {
            DB::table('merchant_customers')->insert([...$record, 'id' => $id, 'code' => 'CUST-'.strtoupper(substr(str_replace('-', '', $id), 0, 12)), 'request_id' => $v['request_id'], 'request_hash' => $v['request_hash'], 'created_at' => now()]);
        }
        DB::table('merchant_customer_search')->where('customer_id', $id)->delete();
        $terms = [];
        foreach ([$profile['name'], $profile['email'], $profile['phone'], $profile['tin']] as $value) {
            $normal = $this->normalized((string) $value);
            $words = preg_split('/[^\pL\pN@._+-]+/u', $normal, -1, PREG_SPLIT_NO_EMPTY);
            $words[] = $normal;
            $words[] = preg_replace('/[^\pL\pN]/u', '', $normal);
            foreach ($words as $word) {
                for ($n = 2; $n <= min(80, mb_strlen($word)); $n++) {
                    $terms[$this->hash($merchant, 'term|'.mb_substr($word, 0, $n))] = true;
                }
            }
        }
        foreach (array_chunk(array_keys($terms), 200) as $chunk) {
            DB::table('merchant_customer_search')->insert(array_map(fn ($term) => ['merchant_id' => $merchant, 'customer_id' => $id, 'term_hash' => $term], $chunk));
        }
        $saved = $this->row(DB::table('merchant_customers')->where('id', $id)->first());
        DB::table('merchant_customer_history')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'customer_id' => $id, 'revision' => $saved['revision'], 'actor_id' => $actor, 'change_encrypted' => Crypt::encryptString(json_encode(['before' => $old ? $this->row($old) : null, 'after' => $saved, 'reason' => $v['reason']], JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now()]);

        return $saved;
    }

    public function matchHashes(string $merchant, array $profile): array
    {
        $values = ['name' => $this->normalized($profile['name'] ?? ''), 'tin' => preg_replace('/\D/', '', $profile['tin'] ?? ''), 'email' => $this->normalized($profile['email'] ?? ''), 'phone' => preg_replace('/\D/', '', $profile['phone'] ?? '')];

        return collect($values)->mapWithKeys(fn ($value, $key) => [$key.'_hash' => $value !== '' ? $this->hash($merchant, 'match:'.$key.'|'.$value) : null])->all();
    }

    /** Resolve under the merchant row lock in the invoice transaction. No profile contact is silently changed. */
    public function resolveInvoiceBuyer(string $merchant, string $actor, array $input): array
    {
        $profile = ['name' => trim($input['buyer_name']), 'tin' => preg_replace('/\D/', '', $input['buyer_tin'] ?? '') ?: null, 'email' => trim($input['buyer_email'] ?? '') ?: null, 'phone' => trim($input['buyer_phone'] ?? '') ?: null, 'address' => trim($input['buyer_address'] ?? '') ?: null];
        $hashes = $this->matchHashes($merchant, $profile);
        $base = DB::table('merchant_customers')->where('merchant_id', $merchant);
        if (! empty($input['customer_id'])) {
            $row = (clone $base)->where('id', $input['customer_id'])->first();
            abort_unless($row && $row->status === 'active', 422, 'Select an active customer from your own business.');
            abort_if(isset($input['customer_revision']) && $row->revision !== (int) $input['customer_revision'], 409, 'The customer profile changed. Select it again to reload its current details.');
            abort_unless($row->name_hash === $hashes['name_hash'] && $row->tin_hash === $hashes['tin_hash'], 422, 'The customer name or TIN changed. Select the correct customer or edit its profile in Customers.');

            return ['customer' => $this->row($row), 'created' => false];
        }
        $sameName = (clone $base)->where('name_hash', $hashes['name_hash']);
        $matches = (clone $sameName)->where('tin_hash', $hashes['tin_hash']);
        if (! $hashes['tin_hash']) {
            if ($hashes['email_hash']) {
                $matches->where('email_hash', $hashes['email_hash']);
            } elseif ($hashes['phone_hash']) {
                $matches->where('phone_hash', $hashes['phone_hash']);
            } else {
                $matches->whereNull('email_hash')->whereNull('phone_hash');
            }
        }
        $found = $matches->limit(2)->get();
        abort_if($found->count() > 1, 422, 'Multiple customer profiles match this buyer. Select the correct customer from the dropdown.');
        if ($found->count() === 1) {
            abort_unless($found[0]->status === 'active', 422, 'This buyer matches an inactive customer. Review and reactivate the profile in Customers before invoicing.');

            return ['customer' => $this->row($found[0]), 'created' => false];
        }
        $possible = $hashes['tin_hash'] && (clone $base)->where('tin_hash', $hashes['tin_hash'])->exists();
        if (! $possible && $hashes['email_hash']) {
            $possible = (clone $sameName)->where('email_hash', $hashes['email_hash'])->exists();
        }
        if (! $possible && $hashes['phone_hash']) {
            $possible = (clone $sameName)->where('phone_hash', $hashes['phone_hash'])->exists();
        }
        if (! $hashes['tin_hash'] && ! $hashes['email_hash'] && ! $hashes['phone_hash']) {
            $possible = (clone $sameName)->exists();
        }
        abort_if($possible, 422, 'Existing customer details may match this buyer. Select the correct customer from the dropdown to avoid a duplicate or conflicting identity.');
        $payload = [...$profile, 'type' => $input['buyer_customer_type'] ?? 'retail', 'status' => 'active', 'group_name' => 'Standard', 'branch_code' => null, 'review_reference' => null, 'request_id' => (string) Str::uuid(), 'reason' => 'New buyer saved automatically with an invoice', 'request_hash' => hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR))];
        $customer = $this->save($merchant, $actor, $payload);
        app(BirCompliance::class)->audit('customer.created', $customer['id'], $actor, ['merchant_id' => $merchant, 'revision' => 1, 'source' => 'invoice_buyer']);

        return ['customer' => $customer, 'created' => true];
    }

    public function linked(string $merchant, ?array $ids = null)
    {
        $documents = app(IssuedDocumentLedger::class)->query($merchant)->where('merchant_id', $merchant)->whereIn('document_source', ['invoice', 'receipt']);

        return DB::query()->fromSub($documents, 'd')->join('merchant_customer_documents as l', function ($j) {
            $j->on('l.merchant_id', '=', 'd.merchant_id')->on('l.document_source', '=', 'd.document_source')->on('l.document_id', '=', 'd.document_id');
        })->where('l.merchant_id', $merchant)->when($ids !== null, fn ($q) => $q->whereIn('l.customer_id', $ids))->select('d.*', 'l.customer_id');
    }

    public function metrics(string $merchant, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        // Recorded primary invoice amounts, not payments, tax returns or a BIR acceptance claim.
        return $this->linked($merchant, $ids)->select('l.customer_id')->selectRaw("COUNT(*) as document_count,MAX(d.business_date) as last_document, SUM(CASE WHEN d.status='success' AND d.document_family='invoice' AND d.identity_count=1 THEN ROUND(d.amount*100) ELSE 0 END) as invoice_centavos")->groupBy('l.customer_id')->reorder()->get()->keyBy('customer_id')->map(fn ($r) => ['document_count' => (int) $r->document_count, 'last_document' => $r->last_document, 'recorded_invoice_total' => (float) $r->invoice_centavos / 100])->all();
    }

    public function link(string $merchant, string $customer, string $actor, string $source, string $document, string $reason = 'New invoice created from selected customer profile'): void
    {
        $table = $source === 'invoice' ? 'business_invoices' : 'transaction_receipts';
        abort_unless(DB::table($table)->where('id', $document)->where('merchant_id', $merchant)->exists(), 404, 'Document not found.');
        $old = DB::table('merchant_customer_documents')->where('merchant_id', $merchant)->where('document_source', $source)->where('document_id', $document)->first();
        abort_if($old && $old->customer_id !== $customer, 409, 'This document is already linked to another customer. Its relationship is preserved for review.');
        if (! $old) {
            DB::table('merchant_customer_documents')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'customer_id' => $customer, 'document_source' => $source, 'document_id' => $document, 'actor_id' => $actor, 'reason_encrypted' => Crypt::encryptString($reason), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
