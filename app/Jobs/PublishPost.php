<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostTarget;
use App\Publishers\BlueskyPublisher;
use App\Publishers\FacebookPublisher;
use App\Publishers\InstagramPublisher;
use App\Publishers\Publisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fans a due post out to one PublishPostTarget job per connected account, so each account's
 * (possibly slow) publish runs under its own timeout.
 */
class PublishPost implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @var array<string, class-string<Publisher>> */
    public const PUBLISHERS = [
        'facebook' => FacebookPublisher::class,
        'instagram' => InstagramPublisher::class,
        'bluesky' => BlueskyPublisher::class,
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

        if (! isset(self::PUBLISHERS[$post->platform])) {
            $post->update(['status' => 'failed', 'error' => "No publisher for {$post->platform}"]);

            return;
        }

        $accounts = $set->socialAccounts()->where('provider', $post->platform)->get();

        if ($accounts->isEmpty()) {
            $post->update(['status' => 'failed', 'error' => "No connected {$post->platform} account in social set {$set->name}"]);

            return;
        }

        $targets = $accounts->map(fn ($account) => $post->targets()->firstOrCreate(['social_account_id' => $account->id], ['status' => 'pending']));

        // Only targets never started; anything already publishing, published or failed is left alone.
        $pending = $targets->where('status', 'pending');

        if ($pending->isEmpty()) {
            self::finish($post->id);
        }

        $pending->each(fn (PostTarget $target) => PublishPostTarget::dispatch($target->id));
    }

    /**
     * Settle the post once no target is still pending or publishing. Safe to call from every target:
     * the post row is locked, and only a still-queued post is settled.
     */
    public static function finish(int $postId): void
    {
        DB::transaction(function () use ($postId) {
            $post = Post::whereKey($postId)->lockForUpdate()->first();
            $targets = $post?->targets()->with('socialAccount')->get();

            if (! $post || $post->status !== 'queued' || $targets->whereIn('status', ['pending', 'publishing'])->isNotEmpty()) {
                return;
            }

            $failed = $targets->where('status', 'failed');
            $allFailed = $failed->count() === $targets->count();

            $post->update([
                'status' => $allFailed ? 'failed' : 'published',
                'published_at' => $allFailed ? null : now(),
                'error' => $failed->isEmpty() ? null : 'Failed on '.$failed->map(fn (PostTarget $target) => ($target->socialAccount->name ?? 'a removed account').': '.$target->error)->implode('; '),
            ]);
        });
    }

    /** The fan-out itself died: say so instead of leaving the post queued forever. */
    public function failed(?Throwable $e): void
    {
        if (! Post::whereKey($this->postId)->has('targets')->exists()) {
            Post::whereKey($this->postId)->where('status', 'queued')->update(['status' => 'failed', 'error' => 'Publishing could not start.']);
        }
    }
}
