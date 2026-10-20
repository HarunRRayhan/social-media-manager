<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Publishers\LinkedIn;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

#[Signature('linkedin:refresh-tokens')]
#[Description('Renew LinkedIn tokens within 14 days of expiry, for accounts that have a refresh token')]
class RefreshLinkedInTokens extends Command
{
    public function handle(): int
    {
        $refreshed = 0;

        SocialAccount::where('provider', 'linkedin')
            ->whereNotNull('meta->refresh_token')
            ->where('token_expires_at', '<=', now()->addDays(14))
            ->eachById(function (SocialAccount $account) use (&$refreshed) {
                try {
                    $token = LinkedIn::refresh(Crypt::decryptString($account->meta['refresh_token']));
                } catch (RuntimeException $e) {
                    report($e);

                    return;
                }

                $account->update([
                    'access_token' => $token['access_token'],
                    'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 60 * 24 * 3600)),
                    'meta' => ['refresh_token' => Crypt::encryptString($token['refresh_token'] ?? Crypt::decryptString($account->meta['refresh_token']))],
                ]);
                $refreshed++;
            });

        $this->info("Refreshed {$refreshed} LinkedIn token(s).");

        return self::SUCCESS;
    }
}
