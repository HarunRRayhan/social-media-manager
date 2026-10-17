<?php

namespace App\Jobs;

use App\Models\PostTarget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Publishes a post to one account. Never retried: once the remote post exists, a retry would post twice.
 */
class PublishPostTarget implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Instagram video processing polls for up to ~4.5 minutes; queue retry_after must stay above this. */
    public int $timeout = 420;

    public function __construct(public int $targetId) {}

    public function handle(): void
    {
        // Claim it: only a pending target with no remote post is ever sent, so overlaps cannot double-post.
        if (! PostTarget::whereKey($this->targetId)->where('status', 'pending')->whereNull('remote_id')->update(['status' => 'publishing'])) {
            return;
        }

        $target = PostTarget::with('post', 'socialAccount')->findOrFail($this->targetId);
        $account = $target->socialAccount;

        try {
            if (! $account) {
                throw new RuntimeException('The account was disconnected.');
            }

            $publisher = app(PublishPost::PUBLISHERS[$account->provider]);
            $remoteId = $publisher->publish($target->post, $account);

            // Saved before anything else can fail, so this target is never published again.
            $target->update(['status' => 'published', 'remote_id' => $remoteId, 'error' => null, 'published_at' => now()]);

            // The post is live by now; a failed link lookup must not mark it failed.
            $target->update(['remote_url' => rescue(fn () => $publisher->url($remoteId, $account), null, false)]);
        } catch (RuntimeException $e) {
            $target->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }

        PublishPost::finish($target->post_id);
    }

    /** A crash or timeout mid-publish: record it without a message that could hold request details. */
    public function failed(?Throwable $e): void
    {
        PostTarget::whereKey($this->targetId)->whereNull('remote_id')->update(['status' => 'failed', 'error' => 'Publishing stopped unexpectedly.']);

        if ($postId = PostTarget::whereKey($this->targetId)->value('post_id')) {
            PublishPost::finish($postId);
        }
    }
}
