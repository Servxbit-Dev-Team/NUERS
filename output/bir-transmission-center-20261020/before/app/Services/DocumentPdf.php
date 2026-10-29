<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\ProcessSignaledException;

class DocumentPdf
{
    public function render(array $document): string
    {
        $renderer = base_path('bootstrap/ssr/document-renderer.mjs');
        abort_unless(is_file($renderer), 503, 'The shared document renderer needs to be built. Run npm run build:document.');
        $chrome = $this->chrome();
        $directory = storage_path('app/private/pdf-'.Str::uuid());
        File::makeDirectory($directory, 0700, true);
        try {
            $environment = $this->environment($directory);
            $render = new Process([config('document_pdf.node_binary'), $renderer, '--stdin'], base_path(), $environment);
            $render->setInput(json_encode($document, JSON_THROW_ON_ERROR));
            $render->setTimeout(config('document_pdf.timeout_seconds'));
            $render->run();
            abort_unless($render->isSuccessful(), 503, 'The invoice preview renderer is unavailable.');
            $html = $directory.'/preview.html'; $output = $directory.'/copy.pdf';
            file_put_contents($html, $render->getOutput()); chmod($html, 0600);
            // A separate ephemeral browser profile never touches the user's browser session.
            // Keep Chromium's sandbox enabled; the HTML embeds only vetted local assets and blocks network content.
            $print = new Process([$chrome, '--headless=new', '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-background-networking', '--disable-component-update', '--disable-sync', '--no-proxy-server', '--host-resolver-rules=MAP * ~NOTFOUND',
                '--user-data-dir='.$directory.'/profile', '--no-pdf-header-footer', '--print-to-pdf='.$output, 'file://'.$html], base_path(), $environment);
            $print->setTimeout(config('document_pdf.timeout_seconds'));
            $print->run();
            abort_unless($print->isSuccessful() && is_file($output), 503, 'The invoice PDF renderer is unavailable. Check the configured Chromium runtime.');
            $pdf = file_get_contents($output);
            abort_unless(str_starts_with($pdf, '%PDF-'), 503, 'The invoice PDF could not be generated.');
            return $pdf;
        } catch (ProcessTimedOutException) {
            abort(503, 'The invoice PDF renderer timed out. Try again or contact your administrator.');
        } catch (ProcessSignaledException $e) {
            $this->recordRuntimeFailure($e);
            abort(503, 'The invoice PDF renderer could not start. Please contact your administrator.');
        } finally {
            // Source identity, financial data and the temporary browser profile are not retained on disk.
            File::deleteDirectory($directory);
        }
    }

    /** Print only server-rendered, escaped report HTML with a restrictive embedded-content CSP. */
    public function renderHtml(string $markup): string
    {
        $directory = storage_path('app/private/pdf-'.Str::uuid());
        File::makeDirectory($directory, 0700, true);
        try {
            $html = $directory.'/report.html'; $output = $directory.'/report.pdf';
            file_put_contents($html, $markup); chmod($html, 0600);
            $print = new Process([$this->chrome(), '--headless=new', '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-background-networking', '--disable-component-update', '--disable-sync', '--no-proxy-server', '--host-resolver-rules=MAP * ~NOTFOUND', '--user-data-dir='.$directory.'/profile', '--no-pdf-header-footer', '--print-to-pdf='.$output, 'file://'.$html], base_path(), $this->environment($directory));
            $print->setTimeout(config('document_pdf.timeout_seconds')); $print->run();
            abort_unless($print->isSuccessful() && is_file($output), 503, 'The sales report PDF renderer is unavailable.');
            $pdf = file_get_contents($output);
            abort_unless(str_starts_with($pdf, '%PDF-'), 503, 'The sales report PDF could not be generated.');
            return $pdf;
        } catch (ProcessTimedOutException) {
            abort(503, 'Report PDF timed out. Narrow the report period or use Excel / CSV.');
        } catch (ProcessSignaledException $e) {
            $this->recordRuntimeFailure($e);
            abort(503, 'The report PDF renderer could not start. Please contact your administrator.');
        } finally { File::deleteDirectory($directory); }
    }

    private function chrome(): string
    {
        $configured = config('document_pdf.chrome_binary');
        $candidates = $configured ? [$configured] : (PHP_OS_FAMILY === 'Darwin' ? ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'] : ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/opt/google/chrome/chrome']);
        foreach ($candidates as $candidate) if (is_file($candidate) && is_executable($candidate)) return $candidate;
        abort(503, 'Configure NUERS_PDF_CHROME with the installed Chrome or Chromium executable to render the invoice preview PDF.');
    }

    private function environment(string $directory): array
    {
        // The renderer has no reason to inherit database, OAuth, SMTP or application credentials.
        $environment = array_fill_keys(array_keys(array_merge(getenv(), $_ENV, $_SERVER)), false);
        foreach (['PATH', 'TMPDIR', 'LANG', 'LC_ALL', 'TZ', 'SystemRoot', 'WINDIR'] as $key) {
            $value = getenv($key);
            if ($value !== false) $environment[$key] = $value;
        }
        // Native macOS Chrome helpers expect the signed-in user's home. Its
        // browser profile remains isolated by --user-data-dir in every render.
        $nativeHome = getenv('HOME');
        if (PHP_OS_FAMILY === 'Darwin' && $nativeHome && is_writable($nativeHome)) {
            $environment['HOME'] = $nativeHome;
            return $environment;
        }
        // FPM may omit HOME or point it at a non-writable directory. Chromium's
        // crash handler and desktop helpers also need a private writable home.
        $environment['HOME'] = $directory;
        // Leave Chromium's socket temporary path short (Unix sockets cap it at
        // roughly 108 bytes); the profile and home remain in the private folder.
        $environment['XDG_CONFIG_HOME'] = $directory.'/config';
        $environment['XDG_CACHE_HOME'] = $directory.'/cache';
        $environment['XDG_DATA_HOME'] = $directory.'/data';
        return $environment;
    }
    private function recordRuntimeFailure(ProcessSignaledException $e): void
    {
        // Runtime diagnostics stay in the private log, never in an API response.
        $lines = preg_split('/\R/', $e->getProcess()->getErrorOutput());
        $diagnostic = array_values(array_filter($lines, fn ($line) => preg_match('/FATAL|crashpad|sandbox|Permission denied|not permitted/i', $line)));
        logger()->error('Document PDF runtime failed', [
            'signal'=>$e->getProcess()->getTermSignal(),
            'diagnostic'=>mb_substr(implode("\n", $diagnostic), 0, 1600),
        ]);
    }
    public function filename(array $document): string
    {
        return (preg_replace('/[^a-zA-Z0-9_-]/', '-', $document['number'] ?: 'NUERS-document')).'.pdf';
    }
}
