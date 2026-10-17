<?php

namespace App\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;

interface Publisher
{
    /**
     * Create the post on the account and return its remote id. Throws on failure.
     */
    public function publish(Post $post, SocialAccount $account): string;

    /**
     * The public link for a created post, if it can be found.
     */
    public function url(string $remoteId, SocialAccount $account): ?string;
}
