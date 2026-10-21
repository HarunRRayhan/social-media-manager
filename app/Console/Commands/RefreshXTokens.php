<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Publishers\X;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('x:refresh-tokens')]
#[Description('Renew X access tokens within 30 minutes of expiry; a refused refresh flags the account for reconnecting')]
class RefreshXTokens extends Command
{
    public function handle(): int
    {
        $refreshed = 0;

        SocialAccount::where('provider', 'x')
            ->whereNotNull('meta->refresh_token')
            ->where('token_expires_at', '<=', now()->addMinutes(30))
            ->eachById(function (SocialAccount $account) use (&$refreshed) {
                try {
                    X::fresh($account, 30);
                    $refreshed++;
                } catch (RuntimeException $e) {
                    report($e);
                }
            });

        $this->info("Refreshed {$refreshed} X token(s).");

        return self::SUCCESS;
    }
}
