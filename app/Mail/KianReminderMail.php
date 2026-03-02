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
    public function __construct(int|TaxCase $taxCaseId, string $stageName, string $reason, int $stageId)
    {
        // DEFENSIVE: Handle case where model is passed instead of ID
        if ($taxCaseId instanceof TaxCase) {
            $this->taxCaseId = (int) $taxCaseId->id;
        } else {
            $this->taxCaseId = (int) $taxCaseId;
        }
        
        $this->stageName = $stageName;
        $this->reason = $reason;
        $this->stageId = (int) $stageId;
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
                'caseYear' => $taxCase->case_type === 'CIT' ? $taxCase->fiscalYear?->year : $taxCase->period?->period_code,
                'entityName' => $taxCase->entity?->name,
                'lossAmount' => $lossAmount,
                'currencyCode' => $currencyCode,
                'stageName' => $this->stageName,
                'caseUrl' => url('/tax-cases/' . $taxCase->id),
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
