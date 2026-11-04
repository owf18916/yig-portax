<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\RefundStageController;
use App\Models\RefundProcess;
use App\Models\TaxCase;
use App\Services\OrdinaryRefundPayment;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\SQLiteConnection;
use Mockery;
use Tests\TestCase;

class RefundStage4ReadResponseTest extends TestCase
{
    public function test_stage4_serializes_selected_persisted_method_for_ordinary_and_preliminary_without_database_access(): void
    {
        // Return read fixtures at the connection boundary: no database, schema, seeds or payment commands.
        $originalResolver = RefundProcess::getConnectionResolver();
        $connection = Mockery::mock(SQLiteConnection::class)->makePartial();
        $connection->__construct(null, ':memory:');
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);
        $resolver->shouldReceive('getDefaultConnection')->andReturn('sqlite');
        $payment = Mockery::mock(OrdinaryRefundPayment::class);
        $payment->shouldReceive('canSubmit')->once()->andReturnFalse();
        $this->app->instance(OrdinaryRefundPayment::class, $payment);

        try {
            RefundProcess::setConnectionResolver($resolver);
            foreach ([41 => false, 42 => true] as $id => $preliminary) {
                $method = $preliminary ? 'check' : 'bank_transfer';
                $refund = (object) ['id' => $id, 'tax_case_id' => 9, 'stage_id' => $preliminary ? 0 : 4,
                    'refund_number' => 'REF-'.$id, 'refund_amount' => '800.12',
                    'refund_method' => $method, 'refund_status' => 'completed'];
                $transfer = (object) ['id' => $id + 100, 'refund_process_id' => $id,
                    'transfer_status' => 'completed', 'received_date' => '2026-11-04',
                    'received_amount' => '800.12', 'receipt_number' => 'RCPT-'.$id];
                $connection->shouldReceive('select')->times(3)->andReturnUsing(function ($sql, $bindings) use ($refund, $transfer, $id) {
                    if (str_contains($sql, 'from "refund_processes"')) {
                        $this->assertSame([$id, 9], $bindings);

                        return [$refund];
                    }
                    $this->assertStringContainsString('from "bank_transfer_requests"', $sql);
                    $this->assertTrue(in_array($id, $bindings, true) || str_contains($sql, 'in ('.$id.')'));

                    return [$transfer];
                });
                $case = new TaxCase(['spt_type' => $preliminary ? 'Pengembalian Pendahuluan' : 'Restitusi']);
                $case->id = 9;
                $data = (new RefundStageController())->showRefundStage4($case, $id)->getData(true)['data'];
                $this->assertSame($id, $data['refund_id']);
                $this->assertSame($method, $data['refund_method']);
                $this->assertSame($preliminary, $data['is_preliminary']);
                $this->assertSame('RCPT-'.$id, $data['receipt_number']);
                $this->assertSame('800.12', $data['received_amount']);
                $this->assertSame('completed', $data['refund_status']);
                $this->assertSame(4, $data['current_stage']);
                $this->assertSame($preliminary, $data['can_submit']);
            }
        } finally {
            RefundProcess::setConnectionResolver($originalResolver);
        }
    }
}
