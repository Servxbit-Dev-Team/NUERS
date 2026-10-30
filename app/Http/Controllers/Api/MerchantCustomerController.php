<?php

namespace App\Http\Controllers\Api;

use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\IssuedDocumentLedger;
use App\Services\MerchantCustomers;
use Carbon\CarbonImmutable as C;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MerchantCustomerController
{
    private function merchant(Request $r): object
    {
        return app(ApiAccess::class)->merchant($r);
    }

    private function customer(string $merchant, string $id): object
    {
        $row = DB::table('merchant_customers')->where('merchant_id', $merchant)->where('id', $id)->first();
        abort_unless($row, 404, 'Customer not found.');

        return $row;
    }

    private function filter(Request $r): array
    {
        $v = $r->validate(['search' => 'nullable|string|min:2|max:80', 'type' => 'nullable|in:business,retail,walk_in', 'status' => 'nullable|in:active,inactive', 'branch' => 'nullable|string|max:100', 'group' => 'nullable|string|max:80', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,25,50,100']);
        abort_if(! empty($v['from']) && ! empty($v['to']) && $v['to'] < $v['from'], 422, 'Date To must be on or after Date From.');

        return $v;
    }

    private function query(string $merchant, array $v)
    {
        $q = DB::table('merchant_customers')->where('merchant_id', $merchant);
        foreach (['type' => 'type', 'status' => 'status', 'branch' => 'branch_code', 'group' => 'group_name'] as $key => $column) {
            if (! empty($v[$key])) {
                $q->where($column, $v[$key]);
            }
        }
        if (! empty($v['from'])) {
            $q->where('created_at', '>=', C::parse($v['from'], 'Asia/Manila')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
        }
        if (! empty($v['to'])) {
            $q->where('created_at', '<', C::parse($v['to'], 'Asia/Manila')->addDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
        }
        if (! empty($v['search'])) {
            $search = app(MerchantCustomers::class)->normalized($v['search']);
            $terms = [$search];
            if (preg_match('/^[+0-9 ()-]+$/', $search)) {
                $terms[] = preg_replace('/\D/', '', $search);
            }
            $hashes = array_map(fn ($term) => app(MerchantCustomers::class)->hash($merchant, 'term|'.$term), array_unique($terms));
            $q->where(function ($q) use ($search, $merchant, $hashes) {
                $q->where('code', 'like', strtoupper($search).'%')->orWhereIn('id', DB::table('merchant_customer_search')->where('merchant_id', $merchant)->whereIn('term_hash', $hashes)->select('customer_id'));
            });
        }

        return $q;
    }

    public function index(Request $r)
    {
        $m = $this->merchant($r);
        $v = $this->filter($r);
        $service = app(MerchantCustomers::class);
        $rows = $this->query($m->id, $v)->orderByDesc('created_at')->orderBy('id')->paginate($v['per_page'] ?? 10);
        $base = DB::table('merchant_customers')->where('merchant_id', $m->id);
        $summary = (clone $base)->selectRaw("COUNT(*) as total,SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active,SUM(CASE WHEN has_tin=1 THEN 1 ELSE 0 END) as with_tin,SUM(CASE WHEN details_reviewed_at IS NOT NULL THEN 1 ELSE 0 END) as reviewed")->first();
        $segments = (clone $base)->select('type')->selectRaw('COUNT(*) as total')->groupBy('type')->get();
        $month = C::now('Asia/Manila')->startOfMonth()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        foreach (['total', 'active', 'with_tin', 'reviewed'] as $key) {
            $summary->$key = (int) $summary->$key;
        }
        $summary->new_month = (clone $base)->where('created_at', '>=', $month)->count();
        $linked = $service->linked($m->id);
        $summary->recent = $linked->where('d.business_date', '>=', C::now('Asia/Manila')->subDays(30)->toDateString())->where('d.business_date', '<=', C::now('Asia/Manila')->toDateString().' 23:59:59')->distinct()->count('l.customer_id');
        $metrics = $service->metrics($m->id, collect($rows->items())->pluck('id')->all());

        return response()->json(['data' => collect($rows->items())->map(fn ($row) => [...$service->row($row), ...($metrics[$row->id] ?? ['document_count' => 0, 'last_document' => null, 'recorded_invoice_total' => 0])]), 'total' => $rows->total(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'summary' => $summary, 'segments' => $segments, 'branches' => $service->branches($m->id), 'groups' => (clone $base)->distinct()->orderBy('group_name')->pluck('group_name'), 'updated_at' => now()->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    public function options(Request $r)
    {
        $merchant = $this->merchant($r);
        $v = $r->validate(['search' => 'nullable|string|min:2|max:80', 'page' => 'sometimes|integer|min:1']);
        $rows = $this->query($merchant->id, $v)->where('status', 'active')->orderByDesc('created_at')->orderBy('id')->paginate(20);

        return response()->json(['data' => collect($rows->items())->map(fn ($row) => array_intersect_key(app(MerchantCustomers::class)->row($row), array_flip(['id', 'code', 'name', 'tin', 'email', 'phone', 'address', 'type', 'status', 'revision']))), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()])->header('Cache-Control', 'private, no-store');
    }

    private function validateProfile(array $input, string $merchant, bool $update = false): array
    {
        $v = Validator::make($input, ['name' => 'required|string|min:2|max:255', 'tin' => ['nullable', 'string', 'regex:/^[0-9 -]+$/', 'max:30'], 'email' => 'nullable|email:rfc|max:255', 'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{7,30}$/'], 'address' => 'nullable|string|max:1000', 'type' => 'required|in:business,retail,walk_in', 'status' => 'required|in:active,inactive', 'group_name' => 'required|string|min:2|max:80', 'branch_code' => 'nullable|string|max:100', 'review_reference' => 'nullable|string|min:8|max:300', 'reason' => 'required|string|min:10|max:1000', ...($update ? ['revision' => 'required|integer|min:1'] : ['request_id' => 'required|uuid']), 'source' => 'nullable|array:document_source,document_id', 'source.document_source' => 'required_with:source|in:invoice,receipt', 'source.document_id' => 'required_with:source|string|max:120'])->validate();
        foreach (['name', 'email', 'phone', 'address', 'group_name', 'review_reference'] as $key) {
            if (isset($v[$key])) {
                $v[$key] = trim($v[$key]);
                abort_if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v[$key]), 422, 'Control characters are not permitted.');
            }
        }
        abort_if(mb_strlen($v['name']) < 2, 422, 'Enter the customer name.');
        abort_if(mb_strlen($v['group_name']) < 2, 422, 'Enter a customer group.');
        if (! empty($v['tin'])) {
            $v['tin'] = preg_replace('/\D/', '', $v['tin']);
            abort_unless(in_array(strlen($v['tin']), [9, 12]), 422, 'TIN must contain nine digits, or twelve including its recorded legacy suffix.');
        }
        if (! empty($v['phone'])) {
            abort_unless(strlen(preg_replace('/\D/', '', $v['phone'])) >= 7 && strlen(preg_replace('/\D/', '', $v['phone'])) <= 15, 422, 'Phone must contain between seven and fifteen digits.');
        }
        if (! empty($v['branch_code'])) {
            abort_unless(app(MerchantCustomers::class)->branches($merchant)->contains('branch_code', $v['branch_code']), 422, 'Select a branch recorded for your own business.');
        }

        return $v;
    }

    public function store(Request $r)
    {
        $m = $this->merchant($r);
        $v = $this->validateProfile($r->all(), $m->id);
        $hash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($m, $v, $hash, $r) {
            DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            app(\App\Services\MerchantTeamAccess::class)->recheckMutation($r);
            $old = DB::table('merchant_customers')->where('merchant_id', $m->id)->where('request_id', $v['request_id'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'This request identifier was already used for different content.');

                return response()->json(['data' => app(MerchantCustomers::class)->row($old), 'message' => 'Existing customer returned.']);
            }
            $row = app(MerchantCustomers::class)->save($m->id, (string) $r->attributes->get('api_actor')->id, [...$v, 'request_hash' => $hash]);
            if (! empty($v['source'])) {
                app(MerchantCustomers::class)->link($m->id, $row['id'], (string) $r->attributes->get('api_actor')->id, $v['source']['document_source'], $v['source']['document_id'], $v['reason']);
            }
            app(BirCompliance::class)->audit('customer.created', $row['id'], (string) $r->attributes->get('api_actor')->id, ['merchant_id' => $m->id, 'revision' => 1]);

            return response()->json(['data' => $row, 'message' => 'Customer saved. Historical documents are unchanged.'], 201);
        });
    }

    public function show(Request $r, string $id)
    {
        $r->validate(['page' => 'sometimes|integer|min:1', 'draft_page' => 'sometimes|integer|min:1']);
        $m = $this->merchant($r);
        $row = $this->customer($m->id, $id);
        $service = app(MerchantCustomers::class);
        $documents = $service->linked($m->id, [$id])->orderByDesc('d.business_date')->paginate(10);
        $drafts = DB::table('business_invoices as b')->join('merchant_customer_documents as l', function ($j) {
            $j->on('l.document_id', '=', 'b.id')->on('l.merchant_id', '=', 'b.merchant_id')->where('l.document_source', 'invoice');
        })->where('l.customer_id', $id)->where('l.merchant_id', $m->id)->where('b.merchant_id', $m->id)->where('b.status', 'draft')->orderByDesc('b.created_at')->orderBy('b.id')->paginate(10, ['b.id', 'b.invoice_number', 'b.issue_date', 'b.document_type', 'b.total_amount', 'b.status'], 'draft_page');

        return response()->json(['data' => [...$service->row($row), ...($service->metrics($m->id, [$id])[$id] ?? ['document_count' => 0, 'last_document' => null, 'recorded_invoice_total' => 0])], 'documents' => $documents, 'drafts' => $drafts, 'history' => DB::table('merchant_customer_history')->where('merchant_id', $m->id)->where('customer_id', $id)->orderByDesc('revision')->limit(20)->get(['revision', 'created_at', 'actor_id'])])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $r, string $id)
    {
        $m = $this->merchant($r);
        $v = $this->validateProfile($r->all(), $m->id, true);
        abort_if(! empty($v['source']), 422, 'Use the explicit document relationship action.');

        return DB::transaction(function () use ($m, $v, $r, $id) {
            DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            app(\App\Services\MerchantTeamAccess::class)->recheckMutation($r);
            $old = $this->customer($m->id, $id);
            abort_unless($old->revision === $v['revision'], 409, 'This customer was updated. Reload before saving again.');
            $row = app(MerchantCustomers::class)->save($m->id, (string) $r->attributes->get('api_actor')->id, $v, $old);
            app(BirCompliance::class)->audit('customer.updated', $id, (string) $r->attributes->get('api_actor')->id, ['merchant_id' => $m->id, 'revision' => $row['revision']]);

            return response()->json(['data' => $row, 'message' => 'Customer updated. Existing invoice snapshots remain unchanged.']);
        });
    }

    public function sourceBuyers(Request $r)
    {
        $m = $this->merchant($r);
        $v = $r->validate(['search' => 'nullable|string|max:80', 'page' => 'sometimes|integer|min:1']);
        $q = app(IssuedDocumentLedger::class)->query($m->id)->where('merchant_id', $m->id)->whereIn('document_source', ['invoice', 'receipt'])->whereNotNull('customer_name')->whereNotExists(fn ($x) => $x->selectRaw('1')->from('merchant_customer_documents as l')->whereColumn('l.merchant_id', 'ranked.merchant_id')->whereColumn('l.document_source', 'ranked.document_source')->whereColumn('l.document_id', 'ranked.document_id'));
        if (! empty($v['search'])) {
            $q->where(fn ($x) => $x->where('customer_name', 'like', '%'.$v['search'].'%')->orWhere('receipt_number', 'like', '%'.$v['search'].'%'));
        }

        return response()->json($q->orderByDesc('recorded_at')->select('document_source', 'document_id', 'receipt_number', 'customer_name', 'customer_tin', 'business_date')->paginate(15))->header('Cache-Control', 'private, no-store');
    }

    public function link(Request $r, string $id)
    {
        $m = $this->merchant($r);
        $v = $r->validate(['document_source' => 'required|in:invoice,receipt', 'document_id' => 'required|string|max:120', 'reason' => 'required|string|min:10|max:1000']);

        return DB::transaction(function () use ($m, $r, $id, $v) {
            DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            app(\App\Services\MerchantTeamAccess::class)->recheckMutation($r);
            $this->customer($m->id, $id);
            app(MerchantCustomers::class)->link($m->id, $id, (string) $r->attributes->get('api_actor')->id, $v['document_source'], $v['document_id'], $v['reason']);
            app(BirCompliance::class)->audit('customer.document_linked', $id, (string) $r->attributes->get('api_actor')->id, ['merchant_id' => $m->id, 'source' => $v['document_source'], 'document_id' => $v['document_id']]);

            return response()->json(['message' => 'Document relationship recorded; source document unchanged.']);
        });
    }

    public function export(Request $r)
    {
        $m = $this->merchant($r);
        $v = $this->filter($r);
        $q = $this->query($m->id, $v);
        if ($r->has('report_limit')) {
            \App\Services\ReportCsv::limit($r, (clone $q)->count());
        }
        abort_if((clone $q)->count() > 10000, 422, 'Narrow the filters to export at most 10,000 customers.');
        app(BirCompliance::class)->audit('customer.exported', $m->id, (string) $r->attributes->get('api_actor')->id, ['filtered_count' => (clone $q)->count()]);

        return response()->streamDownload(function () use ($q) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Code', 'Name', 'Type', 'TIN', 'Email', 'Phone', 'Address', 'Branch', 'Group', 'Status', 'Details reviewed'], ',', '"', '');
            foreach ($q->orderBy('id')->cursor() as $r) {
                $v = app(MerchantCustomers::class)->row($r);
                $cells = [$v['code'], $v['name'], $v['type'], $v['tin'], $v['email'], $v['phone'], $v['address'], $v['branch_code'], $v['group_name'], $v['status'], $v['details_reviewed_at'] ? 'Yes (merchant review)' : 'No'];
                fputcsv($f, array_map(fn ($s) => preg_match('/^[\s]*[=+\-@\t\r]/', (string) $s) ? "'".$s : (string) $s, $cells), ',', '"', '');
            }fclose($f);
        }, 'NUERS-customers.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function import(Request $r)
    {
        $m = $this->merchant($r);
        $r->validate(['file' => 'required|file|max:2048', 'request_id' => 'required|uuid']);
        $path = $r->file('file')->getRealPath();
        abort_unless(mb_check_encoding(file_get_contents($path), 'UTF-8'), 422, 'Save the CSV as UTF-8 before importing.');
        $hash = hash_file('sha256', $path);
        $f = fopen($path, 'r');
        $header = fgetcsv($f, 0, ',', '"', '');
        abort_unless($header, 422, 'CSV header is required.');
        $header = array_map(fn ($s) => strtolower(trim(ltrim($s, "\xEF\xBB\xBF"))), $header);
        $allowed = ['name', 'type', 'tin', 'email', 'phone', 'address', 'branch_code', 'group_name', 'status'];
        abort_unless(in_array('name', $header) && count($header) === count(array_unique($header)) && ! array_diff($header, $allowed), 422, 'Use the supplied CSV template columns.');
        $rows = [];
        while (($cells = fgetcsv($f, 0, ',', '"', '')) !== false) {
            if (count($cells) === 1 && trim($cells[0] ?? '') === '') {
                continue;
            }abort_if(count($rows) >= 500, 422, 'Import at most 500 customers per file.');
            abort_unless(count($cells) === count($header), 422, 'CSV column count is inconsistent.');
            $data = array_map(fn ($s) => trim($s) === '' ? null : $s, $cells);
            $input = [...array_combine($header, $data), 'request_id' => (string) Str::uuid(), 'review_reference' => null, 'reason' => 'Customer CSV import by authorized merchant'];
            $input['type'] = $input['type'] ?? 'retail';
            $input['status'] = $input['status'] ?? 'active';
            $input['group_name'] = $input['group_name'] ?? 'Standard';
            $rows[] = $this->validateProfile($input, $m->id);
        }fclose($f);
        abort_unless($rows, 422, 'CSV has no customer rows.');

        return DB::transaction(function () use ($rows, $m, $r, $hash) {
            DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            app(\App\Services\MerchantTeamAccess::class)->recheckMutation($r);
            $old = DB::table('merchant_customer_imports')->where('merchant_id', $m->id)->where('request_id', $r->request_id)->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Import identifier was already used for another file.');

                return response()->json(['created' => $old->created_count, 'message' => 'Existing import returned; no duplicates created.']);
            }foreach ($rows as $v) {
                app(MerchantCustomers::class)->save($m->id, (string) $r->attributes->get('api_actor')->id, [...$v, 'request_hash' => hash('sha256', json_encode($v))]);
            }$id = (string) Str::uuid();
            DB::table('merchant_customer_imports')->insert(['id' => $id, 'merchant_id' => $m->id, 'request_id' => $r->request_id, 'request_hash' => $hash, 'created_count' => count($rows), 'created_at' => now(), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('customer.imported', $id, (string) $r->attributes->get('api_actor')->id, ['merchant_id' => $m->id, 'created' => count($rows)]);

            return response()->json(['created' => count($rows), 'message' => count($rows).' customers imported. Review their details before invoicing.'], 201);
        });
    }
}
