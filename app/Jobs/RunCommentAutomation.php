<?php

namespace App\Jobs;

use App\Models\CommentAutomation;
use App\Models\CommentAutomationRun;
use App\Publishers\MetaGraph;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Sends one automation's private reply (and optional public reply) to one comment, from the account that received it.
 * Never retried, and claimed by a unique run row first, so a comment is never DMed twice.
 */
class RunCommentAutomation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $automationId, public int $accountId, public string $commentId, public string $commenterId) {}

    public function handle(): void
    {
        $automation = CommentAutomation::find($this->automationId);

        if (! $automation) {
            return;
        }

        $claimed = CommentAutomationRun::query()->insertOrIgnore([
            'comment_automation_id' => $automation->id,
            'comment_id' => $this->commentId,
            'commenter_id' => $this->commenterId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $claimed) {
            return;
        }

        $run = $automation->runs()->where('comment_id', $this->commentId)->sole();
        // The account that received the comment, while it is still attached.
        $account = $automation->accounts()->find($this->accountId);

        // Turned off, or the account removed, between the webhook and this job.
        if (! $automation->active || ! $account) {
            $run->update(['status' => 'skipped', 'error' => $automation->active ? 'The account was removed from the automation.' : 'The automation was turned off.']);

            return;
        }

        $instagram = $account->provider === 'instagram';
        // Both platforms send private replies from the Page: an Instagram account through its linked Page.
        $page = $instagram ? (string) ($account->meta['page_id'] ?? '') : $account->external_id;

        try {
            MetaGraph::post("{$page}/messages", $account->access_token, [
                'recipient' => ['comment_id' => $this->commentId],
                'message' => ['text' => $automation->dm_message],
            ]);
        } catch (RuntimeException $e) {
            $run->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return;
        }

        $run->update(['status' => 'sent']);

        if (filled($automation->public_reply)) {
            try {
                MetaGraph::post($this->commentId.($instagram ? '/replies' : '/comments'), $account->access_token, ['message' => $automation->public_reply]);
            } catch (RuntimeException $e) {
                $run->update(['error' => 'DM sent, public reply failed: '.$e->getMessage()]);
            }
        }
    }

    /** A crash mid-send: record it without a message that could hold request details. */
    public function failed(?Throwable $e): void
    {
        CommentAutomationRun::query()
            ->where('comment_automation_id', $this->automationId)
            ->where('comment_id', $this->commentId)
            ->where('status', 'pending')
            ->update(['status' => 'failed', 'error' => 'Sending stopped unexpectedly.']);
    }
}
