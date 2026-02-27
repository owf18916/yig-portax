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

    protected int $taxCaseId;
    protected string $stageName;
    protected string $reason;
    protected int $stageId;

    /**
     * Create a new message instance.
     */
    public function __construct(int $taxCaseId, string $stageName, string $reason, int $stageId)
    {
        $this->taxCaseId = $taxCaseId;
        $this->stageName = $stageName;
        $this->reason = $reason;
        $this->stageId = $stageId;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $taxCase = TaxCase::find($this->taxCaseId);
        
        return new Envelope(
            subject: "KIAN Required: Tax Case {$taxCase->case_number} - {$this->stageName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $taxCase = TaxCase::with(['entity', 'currency', 'fiscalYear', 'skpRecord', 'objectionSubmission', 'objectionDecision', 'appealSubmission', 'appealDecision', 'supremeCourtSubmission', 'supremeCourtDecision'])->find($this->taxCaseId);
        
        // Calculate loss amount directly (don't rely on kian_status_by_stage which isn't set in queue context)
        $lossAmount = $taxCase->calculateLossAtStage($this->stageId) ?? 0;
        
        // Get currency code from tax case
        $currencyCode = $taxCase->currency?->code ?? 'IDR';

        return new Content(
            view: 'emails.kian-reminder-compatible',
            with: [
                'caseNumber' => $taxCase->case_number,
                'caseType' => $taxCase->case_type,
                'caseYear' => $taxCase->fiscalYear?->name,
                'entityName' => $taxCase->entity?->name,
                'lossAmount' => $lossAmount,
                'currencyCode' => $currencyCode,
                'stageName' => $this->stageName,
                'caseUrl' => route('tax-cases.show', $taxCase->id),
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
