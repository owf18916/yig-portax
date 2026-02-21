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
    protected int $stageId;

    /**
     * Create a new message instance.
     */
    public function __construct(TaxCase $taxCase, string $stageName, string $reason, int $stageId)
    {
        $this->taxCase = $taxCase;
        $this->stageName = $stageName;
        $this->reason = $reason;
        $this->stageId = $stageId;
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
        // Get stage-specific loss amount from kian_status_by_stage
        $stageKianData = $this->taxCase->kian_status_by_stage[$this->stageId] ?? null;
        $lossAmount = $stageKianData ? ($stageKianData['lossAmount'] ?? 0) : 0;
        
        // Get currency code from tax case
        $currencyCode = $this->taxCase->currency?->code ?? 'IDR';

        return new Content(
            view: 'emails.kian-reminder',
            with: [
                'caseNumber' => $this->taxCase->case_number,
                'caseType' => $this->taxCase->case_type,
                'caseYear' => $this->taxCase->fiscal_year?->name,
                'entityName' => $this->taxCase->entity?->name,
                'lossAmount' => $lossAmount,
                'currencyCode' => $currencyCode,
                'stageName' => $this->stageName,
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
