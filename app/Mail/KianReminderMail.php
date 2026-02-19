<?php

namespace App\Mail;

use App\Models\TaxCase;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KianReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    protected TaxCase $taxCase;
    protected string $stageName;
    protected string $reason;

    /**
     * Create a new message instance.
     */
    public function __construct(TaxCase $taxCase, string $stageName, string $reason)
    {
        $this->taxCase = $taxCase;
        $this->stageName = $stageName;
        $this->reason = $reason;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "KIAN Required: Tax Case {$this->taxCase->case_number} - {$this->stageName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $lossAmount = max(0, $this->taxCase->disputed_amount - ($this->taxCase->getTotalRefundedAmount() ?? 0));

        return new Content(
            view: 'emails.kian-reminder',
            with: [
                'caseNumber' => $this->taxCase->case_number,
                'caseType' => $this->taxCase->case_type,
                'caseYear' => $this->taxCase->fiscal_year?->name,
                'entityName' => $this->taxCase->entity?->name,
                'disputedAmount' => $this->taxCase->disputed_amount,
                'refundedAmount' => $this->taxCase->getTotalRefundedAmount() ?? 0,
                'lossAmount' => $lossAmount,
                'stageName' => $this->stageName,
                'reason' => $this->reason,
                'caseUrl' => route('tax-cases.show', $this->taxCase->id),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
