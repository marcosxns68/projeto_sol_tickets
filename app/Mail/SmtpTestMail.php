<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SmtpTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Teste de e-mail — Sutoorii Tickets',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.smtp-test',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
