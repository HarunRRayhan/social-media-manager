<?php

namespace App\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;

interface Publisher
{
    /**
     * Create the post on the account and return its remote id and URL. Throws on failure.
     *
     * @return array{id: string, url: string|null}
     */
    public function publish(Post $post, SocialAccount $account): array;
}
