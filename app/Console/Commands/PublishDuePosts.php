<?php

namespace App\Console\Commands;

use App\Jobs\PublishPost;
use App\Models\Post;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('posts:publish-due')]
#[Description('Queue every scheduled post whose time has come')]
class PublishDuePosts extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Post::where('status', 'scheduled')->where('scheduled_at', '<=', now())->chunkById(100, fn ($posts) => $posts->each(function (Post $post) use (&$queued) {
            // The conditional update claims the post, so overlapping runs never queue it twice.
            if (Post::whereKey($post->id)->where('status', 'scheduled')->update(['status' => 'queued'])) {
                PublishPost::dispatch($post->id);
                $queued++;
            }
        }));

        $this->info("Queued {$queued} post(s).");

        return self::SUCCESS;
    }
}
