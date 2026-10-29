<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/** Dedicated TLS-only outbound mail; never falls back to log or a different mailer. */
class PlatformMail
{
    public function settings(): ?array
    {
        $row = DB::table('platform_mail_settings')->where('id', 1)->first();
        return $row ? json_decode(Crypt::decryptString($row->encrypted_settings), true, flags: JSON_THROW_ON_ERROR) : null;
    }

    public function connectionHash(array $settings): string
    {
        unset($settings['enabled']);
        ksort($settings);
        return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
    }

    public function send(array $settings, string $recipient, string $subject, string $body, ?string $pdf = null, string $filename = 'NUERS-document.pdf', ?string $actionUrl = null): void
    {
        // Pin a vetted public IPv4 address, retain the original TLS certificate/SNI name.
        [$host, $ip] = app(SignedWebhook::class)->address('https://'.$settings['host']);
        $mailer = Mail::build(['transport' => 'smtp', 'scheme' => $settings['encryption'] === 'tls' ? 'smtps' : 'smtp', 'host' => $ip,
            'port' => $settings['port'], 'username' => $settings['username'], 'password' => $settings['password'],
            'timeout' => 10, 'require_tls' => true, 'auto_tls' => true, 'local_domain' => 'nuers.net']);
        $stream = $mailer->getSymfonyTransport()->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setStreamOptions(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $host, 'SNI_enabled' => true, 'SNI_server_name' => $host]]);
        }
        $subject = preg_replace('/[\r\n]+/', ' ', $subject);
        $html = view('emails.platform', compact('subject', 'body', 'actionUrl'))->render();
        $mailer->html($html, function ($message) use ($settings, $recipient, $subject, $body, $actionUrl, $pdf, $filename) {
            // The transport mailbox remains provider-authorized; the public identity is always NUERS.
            $message->from($settings['from_address'], 'NUERS')->to($recipient)->subject('NUERS | '.$subject);
            $message->getSymfonyMessage()->text($body.($actionUrl ? "\n\nOpen NUERS: ".$actionUrl : '')."\n\nNUERS — National Unified Electronic Receipt System\nDigital Transactions. A Stronger Philippines.");
            if ($pdf !== null) $message->attachData($pdf, $filename, ['mime' => 'application/pdf']);
        });
    }
}
