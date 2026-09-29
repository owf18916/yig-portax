<?php

namespace App\Console\Commands;

use App\Services\HistoricalRefundBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillHistoricalRefunds extends Command
{
    protected $signature = 'portax:backfill-historical-refunds
        {--apply : Insert reviewed, still-eligible RefundProcess rows}
        {--case= : Limit discovery to one exact case number}
        {--stage= : Limit discovery to origin stage 4, 7, 10, or 12}';

    protected $description = 'Dry-run or apply the controlled historical Decision Point RefundProcess backfill';

    public function handle(HistoricalRefundBackfillService $service): int
    {
        $stage = $this->option('stage');
        if ($stage !== null && (! ctype_digit((string) $stage) || ! in_array((int) $stage, [4, 7, 10, 12], true))) {
            $this->error('--stage must be one of 4, 7, 10, or 12.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        $this->components->info($apply ? 'APPLY MODE' : 'READ-ONLY DRY RUN');
        $this->line(sprintf(
            'Context: environment=%s database=%s connection=%s',
            app()->environment(),
            DB::connection()->getDatabaseName(),
            DB::connection()->getDriverName(),
        ));

        try {
            $result = $service->discover($this->option('case'), $stage === null ? null : (int) $stage);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Candidate manifest');
        $this->table(
            ['tax_case_id', 'case_number', 'stage', 'decision', 'history_id', 'amount', 'submitter/time', 'existing RefundProcess', 'result', 'reason'],
            collect($result['rows'])->map(fn ($row) => [
                $row['tax_case_id'],
                $row['case_number'],
                $row['stage_id'],
                class_basename($row['decision_type']).'#'.($row['decision_id'] ?? '?'),
                $row['history_id'] ?? '-',
                $row['refund_amount'] ?? '-',
                ($row['submitted_by'] ?? '-').'/'.($row['source_timestamp'] ?? '-'),
                $row['existing_refund_status'],
                $row['result'],
                $row['reason'],
            ])->all(),
        );

        $counts = $result['counts'];
        $this->newLine();
        $this->line("Candidate count: {$counts['candidates']}");
        $this->line("Skipped count: {$counts['skipped']}");
        $this->line("Manual-review count: {$counts['manual_review']}");
        $this->line("Collision count: {$counts['collisions']}");
        $this->line("Would-insert count: {$counts['would_insert']}");

        if (! $apply) {
            $this->components->info('Dry run complete: zero writes performed.');

            return self::SUCCESS;
        }

        $manifest = collect($result['rows'])->where('result', 'ELIGIBLE')->values()->all();
        try {
            $applied = $service->apply($manifest);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->warn('Apply failed: the transaction was rolled back.');

            return self::FAILURE;
        }

        $ids = $applied['inserted_ids'] === [] ? 'none' : implode(', ', $applied['inserted_ids']);
        $this->components->info(sprintf('Apply complete: %d inserted; verification %s.', count($applied['inserted_ids']), $applied['verification']));
        $this->line("Created RefundProcess IDs: {$ids}");

        return self::SUCCESS;
    }
}
