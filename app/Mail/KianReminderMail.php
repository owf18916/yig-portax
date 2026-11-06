<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KianReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $snapshot) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->snapshot['subject']);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->snapshot['html']);
    }

    public function attachments(): array
    {
        return [];
    }
}
