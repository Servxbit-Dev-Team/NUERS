<?php

use App\Services\BirCompliance;
use App\Services\EisDelivery;
use App\Services\EisDocumentReadiness;
use App\Services\IssuedDocumentLedger;
use App\Services\SignedWebhook;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Repair only missing internal outbox records. No network delivery, signing or synthetic acceptance.
Artisan::command('eis:prepare-pending {--limit=100}', function () {
    $limit = max(1, min(1000, (int) $this->option('limit')));
    $count = 0;
    $conflicts = 0;
    $rows = app(IssuedDocumentLedger::class)->candidates()->whereIn('document_source', ['invoice', 'receipt'])
        ->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('eis_document_outbox as o')->where('o.internal_schema', EisDocumentReadiness::INTERNAL_SCHEMA)->whereColumn('o.document_source', 'documents.document_source')->whereColumn('o.document_id', 'documents.document_id');
        })
        ->orderBy('id')->limit($limit)->get();
    foreach ($rows as $row) {
        try {
            app(EisDocumentReadiness::class)->prepare($row->document_source, $row->document_id);
            $count++;
        } catch (Throwable $e) {
            $conflicts++;
            report($e);
        }
    }
    $this->info("Prepared $count internal records; $conflicts errors; no BIR transmission performed.");

    return $conflicts ? 1 : 0;
})->purpose('Preserve pending issued documents in the blocked internal EIS outbox');
Schedule::command('eis:prepare-pending --limit=100')->everyMinute()->withoutOverlapping();

Artisan::command('eis:work {--limit=100}', function () {
    $limit = max(1, min(1000, (int) $this->option('limit')));
    for ($i = 0; $i < $limit; $i++) {
        $result = app(EisDelivery::class)->runOne();
        if (! $result) {
            break;
        }
        if ($result['state'] === 'blocked') {
            $this->warn($result['reason'] ?? 'Authority or source review blocks delivery.');
            break;
        }
    }
})->purpose('Process durable delivery through an explicitly supplied verified provider; blocked by default');

Artisan::command('eis:reconcile', function () {
    $notify = function (string $category, string $id, string $message, string $severity = 'high', ?string $merchant = null) {
        DB::table('operational_notifications')->insertOrIgnore(['id' => (string) Str::uuid(), 'dedupe_key' => hash('sha256', $category.'|'.$id.'|'.now()->toDateString()), 'merchant_id' => $merchant, 'category' => $category, 'subject_id' => $id, 'severity' => $severity, 'message' => $message, 'created_at' => now(), 'updated_at' => now()]);
    };
    foreach (DB::table('eis_delivery_jobs')->whereIn('state', ['dead_letter', 'uncertain'])->orderBy('id')->limit(1000)->cursor() as $j) {
        $notify('delivery_review', $j->id, 'Delivery '.$j->state.' requires review and authentic inquiry before replay.', 'high', $j->merchant_id);
    }
    foreach (DB::table('reconciliation_reviews')->whereNotIn('status', ['resolved', 'ignored'])->where('due_at', '<', now())->orderBy('id')->limit(1000)->cursor() as $c) {
        $notify('overdue_reconciliation', (string) $c->id, 'Reconciliation case is past its recorded due date. Owner: '.($c->owner_id ?? 'unassigned'));
    }
    foreach (DB::table('eis_operational_incidents')->where('severity', 'critical')->whereNull('reported_at')->orderBy('id')->limit(1000)->cursor() as $i) {
        $notify('critical_incident', $i->id, 'Authorized personnel must complete written incident reporting. Detection: '.$i->detected_at, 'critical', $i->merchant_id);
    }
    $this->info('Durable internal reminders reconciled. No external message or BIR submission sent.');
})->purpose('Reconcile delivery exceptions, overdue cases and critical incident reporting reminders');
Schedule::command('eis:reconcile')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('security:deployment-check', function () {
    $checks = [
        'debug_disabled' => ! config('app.debug'),
        'https_url' => str_starts_with(config('app.url'), 'https://'),
        'shared_cache' => in_array(config('cache.default'), ['redis', 'database'], true),
        'durable_queue' => in_array(config('queue.default'), ['database', 'redis', 'sqs'], true),
        'privileged_mfa' => config('security.enforce_privileged_mfa') === true,
        'checkpoint_key' => strlen((string) config('security.audit_checkpoint_key')) >= 32,
        'reviewed_release' => strlen((string) config('security.release_evidence_reference')) >= 10,
        'session_schema' => Schema::hasColumn('users', 'api_token_hash'),
        'durable_ingestion_schema' => Schema::hasTable('integration_inbox'),
    ];
    foreach ($checks as $name => $passed) {
        $this->line(($passed ? 'PASS ' : 'BLOCK ').$name);
    }
    $this->warn('This configuration check cannot establish WAF effectiveness, least-privilege grants, key custody, HA, restore correctness, regulatory authority or measured capacity.');

    return in_array(false, $checks, true) ? 1 : 0;
})->purpose('Fail deployment review when basic production configuration evidence is absent');

Artisan::command('eis:audit-checkpoint', function () {
    $key = (string) config('security.audit_checkpoint_key');
    if (strlen($key) < 32) {
        $this->error('A separately custodied audit checkpoint key is required.');

        return 1;
    }
    $policy = app(BirCompliance::class);
    if (! $policy->auditIntegrity()) {
        $this->error('Audit chain integrity failed.');

        return 1;
    }
    $body = ['schema' => 'NUERS-audit-checkpoint-1', 'at' => now()->toIso8601String(), 'head' => DB::table('eis_audit_head')->where('id', 1)->value('last_hash'), 'events' => DB::table('eis_audit_events')->count()];
    $raw = json_encode($body, JSON_THROW_ON_ERROR);
    $dir = storage_path('app/private/audit-checkpoints');
    File::ensureDirectoryExists($dir, 0700, true);
    $path = $dir.'/'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
    file_put_contents($path, json_encode(['checkpoint' => $body, 'hmac_sha256' => hash_hmac('sha256', $raw, $key)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
    $this->info('Signed checkpoint saved. Independent offsite immutable custody still requires an operational export and verification process.');
})->purpose('Create a signed audit checkpoint without exposing its independent secret');
Artisan::command('webhooks:work {--limit=20}', function () {
    for ($i = 0; $i < min(100, max(1, (int) $this->option('limit'))); $i++) {
        $state = app(SignedWebhook::class)->runOne();
        if (! $state || $state === 'disabled') {
            $this->line('Webhook delivery disabled or idle.');
            break;
        }
    }
})->purpose('Dispatch signed durable webhook events only after reviewed egress opt-in');

Artisan::command('eis:enqueue-prepared {--limit=100}', function () {
    $q = DB::table('eis_document_outbox as o')->where('o.internal_schema', EisDocumentReadiness::INTERNAL_SCHEMA)
        ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('eis_delivery_jobs as j')->whereColumn('j.outbox_id', 'o.id'))
        ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('eis_document_outbox as newer')->whereColumn('newer.document_source', 'o.document_source')->whereColumn('newer.document_id', 'o.document_id')->whereColumn('newer.source_review_revision', '>', 'o.source_review_revision'))
        ->orderBy('o.prepared_at')->limit(max(1, min(1000, (int) $this->option('limit'))));
    $count = 0;
    foreach ($q->get(['o.id']) as $o) {
        app(EisDelivery::class)->enqueue($o->id);
        $count++;
    }
    $this->info("$count internal preparations registered with blocked delivery jobs. No network calls performed.");
})->purpose('Register missing current internal preparations with the blocked durable delivery queue');


Artisan::command('notifications:work {--limit=25} {--watch}', function () {
    $limit=max(1,min(100,(int)$this->option('limit')));
    if(!config('notifications.dispatch_enabled')) { $this->warn('External notification dispatch is paused. Activate NUERS_NOTIFICATION_DISPATCH_ENABLED only after rollout authorization.'); return 1; }
    $nextReconcile=time()+3600;
    do {
        for($i=0;$i<$limit;$i++) if(!app(\App\Services\PlatformNotifications::class)->runOne()) break;
        if($this->option('watch')) { if(time()>=$nextReconcile) { app(\App\Services\NotificationEvents::class)->reconcile(); $nextReconcile=time()+3600; } sleep(5); }
    } while($this->option('watch'));
})->purpose('Deliver new encrypted NUERS transactional mail; ambiguous SMTP outcomes are never replayed automatically');
Schedule::command('notifications:work --limit=25')->everyMinute()->withoutOverlapping(10);
Artisan::command('notifications:reconcile', function () {
    app(\App\Services\NotificationEvents::class)->reconcile();
    $this->info('Current notification reminders reconciled. No BIR transmission performed.');
})->purpose('Queue deduplicated operations summaries and permit expiry reminders');
Schedule::command('notifications:reconcile')->hourly()->withoutOverlapping();

// Unclaimed registration documents expire; claimed account evidence is retained.
Artisan::command('registration:prune-staged-documents', function () {
    if (!Schema::hasTable('merchant_verification_documents')) return;
    if (Schema::hasTable('verification_upload_sessions')) {
        foreach (DB::table('verification_upload_sessions')->where('expires_at', '<', now())->limit(500)->get() as $upload) {
            DB::transaction(function () use ($upload) {
                $current = DB::table('verification_upload_sessions')->where('id', $upload->id)->where('expires_at', '<', now())->lockForUpdate()->first();
                if (! $current) return;
                \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory('verification-uploads/'.$current->id);
                DB::table('verification_upload_sessions')->where('id', $current->id)->delete();
            });
        }
    }
    $count=0;
    foreach(DB::table('merchant_verification_documents')->whereNull('merchant_id')->where('created_at','<',now()->subDay())->orderBy('id')->limit(500)->get() as $row) {
        DB::transaction(function () use($row,&$count) {
            // Same challenge lock as registration claim/upload prevents cleanup races.
            DB::table('registration_email_verifications')->where('id',$row->challenge_id)->lockForUpdate()->first();
            $current=DB::table('merchant_verification_documents')->where('id',$row->id)->whereNull('merchant_id')->first();
            if(!$current)return;
            if(\Illuminate\Support\Facades\Storage::disk('local')->exists($current->private_path) && !\Illuminate\Support\Facades\Storage::disk('local')->delete($current->private_path))throw new RuntimeException('Staged document cleanup failed.');
            DB::table('merchant_verification_documents')->where('id',$current->id)->delete();$count++;
        });
    }
    $this->info("Removed {$count} expired, unclaimed registration uploads; account evidence retained.");
});
Schedule::command('registration:prune-staged-documents')->daily()->withoutOverlapping();

Schedule::command('merchant-webhooks:work --limit=100 --max-seconds=45')->everyMinute()->withoutOverlapping(5);

// Erase expired unused authenticator setup secrets; active devices stay intact.
Artisan::command('security:prune-mfa-setups', function () {
    if (!Schema::hasTable('account_mfa_devices')) return;
    $count = DB::table('account_mfa_devices')->where('state', 'pending')->where('expires_at', '<=', now())
        ->update(['state'=>'expired', 'encrypted_secret'=>null, 'setup_binding'=>null, 'updated_at'=>now()]);
    $this->info("Expired {$count} unused authenticator setups.");
})->purpose('Erase expired authenticator setup secrets');
Schedule::command('security:prune-mfa-setups')->everyTenMinutes()->withoutOverlapping();
