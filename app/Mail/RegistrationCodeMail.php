<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RegistrationCodeMail extends Mailable
{
    public function __construct(public string $code)
    {
    }
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your NUERS registration verification code');
    }
    public function content(): Content
    {
        return new Content(text: 'emails.registration-code');
    }
}
