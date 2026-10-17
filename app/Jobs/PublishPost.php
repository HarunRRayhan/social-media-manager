<?php

namespace App\Jobs;

use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishPost implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $postId) {}

    public function handle(): void
    {
        $post = Post::findOrFail($this->postId);

        // Its set was deleted after this was queued: park it so a restore picks it up again.
        if (! $post->socialSet) {
            $post->update(['status' => 'scheduled']);

            return;
        }

        // No platform publishers exist yet (Meta comes first), so fail loudly instead of faking a publish.
        $post->update(['status' => 'failed', 'error' => "No publisher for {$post->platform}"]);
    }
}
