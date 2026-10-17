<?php

namespace App\Console\Commands;

use App\Models\SocialSet;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('social-sets:purge')]
#[Description('Permanently delete social sets (and their posts) deleted more than 30 days ago')]
class PurgeDeletedSocialSets extends Command
{
    public function handle(): int
    {
        $purged = 0;

        // Posts go with the set through the cascading foreign key.
        SocialSet::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays(SocialSet::RESTORE_DAYS))
            ->each(function (SocialSet $set) use (&$purged) {
                $set->forceDelete();
                $purged++;
            });

        $this->info("Purged {$purged} social set(s).");

        return self::SUCCESS;
    }
}
