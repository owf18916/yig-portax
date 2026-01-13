<?php

namespace App\Events;

use App\Models\Revision;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RevisionSubmitted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Revision $revision) {}
}
