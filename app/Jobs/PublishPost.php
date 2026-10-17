<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostTarget;
use App\Publishers\FacebookPublisher;
use App\Publishers\InstagramPublisher;
use App\Publishers\Publisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PublishPost implements ShouldQueue
{
    use Queueable;

    /** Never retried: a retry after a remote create succeeded would post twice. */
    public int $tries = 1;

    /** Instagram video processing can take minutes. */
    public int $timeout = 600;

    /** @var array<string, class-string<Publisher>> */
    private const PUBLISHERS = [
        'facebook' => FacebookPublisher::class,
        'instagram' => InstagramPublisher::class,
    ];

    public function __construct(public int $postId) {}

    public function handle(): void
    {
        $post = Post::findOrFail($this->postId);
        $set = $post->socialSet;

        // Its set was deleted after this was queued: park it so a restore picks it up again.
        if (! $set) {
            $post->update(['status' => 'scheduled']);

            return;
        }

        $publisher = isset(self::PUBLISHERS[$post->platform]) ? app(self::PUBLISHERS[$post->platform]) : null;

        if (! $publisher) {
            $post->update(['status' => 'failed', 'error' => "No publisher for {$post->platform}"]);

            return;
        }

        $accounts = $set->socialAccounts()->where('provider', $post->platform)->get();

        if ($accounts->isEmpty()) {
            $post->update(['status' => 'failed', 'error' => "No connected {$post->platform} account in social set {$set->name}"]);

            return;
        }

        foreach ($accounts as $account) {
            $target = $post->targets()->firstOrCreate(['social_account_id' => $account->id]);

            // Already created remotely (by an earlier run): never create it again.
            if ($target->remote_id) {
                continue;
            }

            try {
                $result = $publisher->publish($post, $account);
                $target->update(['status' => 'published', 'remote_id' => $result['id'], 'remote_url' => $result['url'], 'error' => null, 'published_at' => now()]);
            } catch (Throwable $e) {
                report($e);
                $target->update(['status' => 'failed', 'error' => $e->getMessage()]);
            }
        }

        $targets = $post->targets()->with('socialAccount')->get();
        $failed = $targets->where('status', 'failed');

        $post->update([
            'status' => $failed->count() === $targets->count() ? 'failed' : 'published',
            'published_at' => $failed->count() === $targets->count() ? null : now(),
            'error' => $failed->isEmpty() ? null : 'Failed on '.$failed->map(fn (PostTarget $target) => ($target->socialAccount->name ?? 'a removed account').': '.$target->error)->implode('; '),
        ]);
    }

    /** The worker died or timed out: say so instead of leaving the post queued forever. */
    public function failed(?Throwable $e): void
    {
        Post::whereKey($this->postId)->where('status', 'queued')->update(['status' => 'failed', 'error' => 'Publishing stopped: '.($e?->getMessage() ?? 'unknown error')]);
    }
}
