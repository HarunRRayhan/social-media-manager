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
     * Take the created post down from the platform. Throws on failure, ReconnectRequired when the account
     * must be connected again to allow it.
     */
    public function delete(string $remoteId, SocialAccount $account): void;

    /**
     * The public link for a created post, if it can be found.
     */
    public function url(string $remoteId, SocialAccount $account): ?string;
}
