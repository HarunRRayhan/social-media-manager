<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Publishers\ThreadsGraph;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('threads:refresh-tokens')]
#[Description('Extend Threads tokens that are past the halfway point of their 60 days')]
class RefreshThreadsTokens extends Command
{
    public function handle(): int
    {
        $refreshed = 0;

        // A token can be refreshed once a day old and until it expires; an expired one needs a new sign-in.
        SocialAccount::where('provider', 'threads')
            ->where('token_expires_at', '<=', now()->addDays(30))
            ->where('token_expires_at', '>', now())
            ->each(function (SocialAccount $account) use (&$refreshed) {
                try {
                    $token = ThreadsGraph::refresh($account->access_token);
                } catch (RuntimeException $e) {
                    report($e);

                    return;
                }

                $account->update([
                    'access_token' => $token['access_token'],
                    'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 60 * 24 * 3600)),
                ]);
                $refreshed++;
            });

        $this->info("Refreshed {$refreshed} Threads token(s).");

        return self::SUCCESS;
    }
}
