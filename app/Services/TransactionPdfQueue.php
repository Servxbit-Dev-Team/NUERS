<?php

namespace App\Services;

use Illuminate\Support\Facades\{Cache, Crypt, DB, File};
use Illuminate\Support\Str;
use Throwable;

/** Encrypted, actor-owned PDF snapshots rendered by the existing CLI report worker. */
class TransactionPdfQueue
{
    private function directory(): string
    {
        return config('document_pdf.export_directory', storage_path('app/private/transaction-exports'));
    }
    private function path(string $id): string
    {
        abort_unless(Str::isUuid($id), 404);
        return $this->directory().'/'.$id;
    }
    private function save(string $path, array $job): void
    {
        file_put_contents($path.'.tmp', json_encode($job, JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($path.'.tmp', 0600);
        rename($path.'.tmp', $path.'.json');
    }
    public function request(array $report, string $filename, object $actor)
    {
        File::ensureDirectoryExists($this->directory(), 0700, true);
        $this->expire();
        foreach (glob($this->directory().'/*.json') as $file) {
            $job = json_decode(file_get_contents($file), true);
            abort_if(($job['actor'] ?? null) === (string)$actor->id && in_array($job['state'] ?? '', ['queued','running'], true), 429, 'A PDF report is already preparing for your account. Please wait before requesting another.');
        }
        $id = (string)Str::uuid();
        $path = $this->path($id);
        $job = ['id' => $id, 'actor' => (string)$actor->id, 'state' => 'queued', 'created' => time(), 'updated' => time(), 'expires' => time() + 1200,
            'filename' => $filename.'.pdf', 'snapshot' => $report['hash'], 'records' => count($report['rows']), 'environment' => $report['environment']];
        // Neither customer/merchant report data nor the PDF is stored in plaintext.
        file_put_contents($path.'.input', Crypt::encryptString(view('reports.admin-transactions', ['report' => $report])->render()), LOCK_EX);
        chmod($path.'.input', 0600);
        $this->save($path, $job);
        app(BirCompliance::class)->audit('transactions.export.requested', 'transaction-workspace', (string)$actor->id, ['environment' => $report['environment'],'format' => 'pdf','records' => $job['records'],'report_id' => $report['id'],'snapshot_hash' => $report['hash']]);
        return $this->pending($job);
    }
    private function pending(array $job)
    {
        return response()->json(['state' => $job['state'],'export_id' => $job['id'],'retry_after' => 2,'message' => 'Preparing your private PDF in the background. This usually takes less than two minutes.'], 202, ['Cache-Control' => 'private, no-store']);
    }
    public function download(string $id, object $actor)
    {
        $path = $this->path($id);
        abort_unless(is_file($path.'.json'), 404);
        $job = json_decode(file_get_contents($path.'.json'), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($job['actor'], (string)$actor->id), 404);
        abort_if($job['expires'] <= time(), 410, 'This private report expired. Please export it again.');
        abort_if($job['state'] === 'failed' || ($job['state'] === 'running' && $job['updated'] < time() - 180), 503, 'The report worker could not finish this PDF. Please retry or contact your administrator.');
        if ($job['state'] !== 'ready') {
            return $this->pending($job);
        }
        abort_unless(is_file($path.'.pdf.enc'), 503, 'The report file is unavailable. Please export it again.');
        $pdf = Crypt::decryptString(file_get_contents($path.'.pdf.enc'));
        abort_unless(hash_equals($job['file_hash'], hash('sha256', $pdf)), 503, 'The report integrity check failed.');
        return response($pdf, 200, ['Cache-Control' => 'private, no-store','Content-Type' => 'application/pdf','Content-Disposition' => 'attachment; filename="'.$job['filename'].'"','X-Content-Type-Options' => 'nosniff','X-NUERS-Snapshot' => $job['snapshot']]);
    }
    public function runOne(): ?string
    {
        $this->expire();
        $files = glob($this->directory().'/*.json');
        sort($files);
        foreach ($files as $file) {
            $job = json_decode(file_get_contents($file), true);
            if (($job['state'] ?? '') !== 'queued') {
                continue;
            }
            $store = Cache::store(config('cache.default') === 'array' ? 'database' : config('cache.default'));
            $lock = $store->lock('transaction-pdf-worker:'.$job['id'], 120);
            if (!$lock->get()) {
                continue;
            }
            $path = $this->path($job['id']);
            try {
                $job = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                if ($job['state'] !== 'queued') {
                    continue;
                }
                $job['state'] = 'running';
                $job['updated'] = time();
                $this->save($path, $job);
                abort_unless(in_array(DB::table('profiles')->where('id', $job['actor'])->value('role'), ['admin','super_admin','bir'], true), 403);
                $html = Crypt::decryptString(file_get_contents($path.'.input'));
                $pdf = app(DocumentPdf::class)->renderHtml($html);
                file_put_contents($path.'.pdf.enc', Crypt::encryptString($pdf), LOCK_EX);
                chmod($path.'.pdf.enc', 0600);
                $job['file_hash'] = hash('sha256', $pdf);
                $job['state'] = 'ready';
                $job['updated'] = time();
                $this->save($path, $job);
                app(BirCompliance::class)->audit('transactions.exported', 'transaction-workspace', $job['actor'], ['environment' => $job['environment'],'format' => 'pdf','records' => $job['records'],'export_id' => $job['id'],'snapshot_hash' => $job['snapshot']]);
            } catch (Throwable $e) {
                $job['state'] = 'failed';
                $job['updated'] = time();
                $this->save($path, $job);
                report($e);
            } finally {
                if (is_file($path.'.input')) {
                    unlink($path.'.input');
                }
                $lock->release();
            }
            return $job['id'];
        }
        return null;
    }
    private function expire(): void
    {
        foreach (glob($this->directory().'/*.json') as $file) {
            $job = json_decode(file_get_contents($file), true);
            if (($job['expires'] ?? PHP_INT_MAX) > time()) {
                continue;
            }
            $path = $this->path($job['id']);
            foreach (['.input','.pdf.enc','.json','.tmp'] as $suffix) {
                if (is_file($path.$suffix)) {
                    unlink($path.$suffix);
                }
            }
        }
    }
}
