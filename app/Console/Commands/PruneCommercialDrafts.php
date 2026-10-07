<?php

namespace App\Console\Commands;

use App\Services\Commercial\CommercialDraftService;
use Illuminate\Console\Command;

class PruneCommercialDrafts extends Command
{
    protected $signature = 'commercial:prune-drafts';

    protected $description = 'Delete Sales/Purchase workspace drafts not edited for 30 days (age is measured from the last edit)';

    public function handle(CommercialDraftService $drafts): int
    {
        $this->info('Pruned '.$drafts->pruneExpired().' expired draft(s).');
        return self::SUCCESS;
    }
}
