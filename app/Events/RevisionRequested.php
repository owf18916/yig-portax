<?php

namespace App\Events;

use App\Models\Revision;
use App\Models\TaxCase;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RevisionRequested
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Revision $revision,
        public TaxCase $taxCase,
    ) {}
}
