<?php

namespace App\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use RuntimeException;

/**
 * Posts to a Facebook Page with its page access token: text to /feed, one photo to /photos,
 * several photos as unpublished uploads attached to one /feed post.
 */
class FacebookPublisher implements Publisher
{
    public function publish(Post $post, SocialAccount $account): string
    {
        $page = $account->external_id;
        $token = $account->access_token;
        $media = $post->media ?? [];

        if (collect($media)->contains(fn ($url) => MetaGraph::isVideo($url))) {
            throw new RuntimeException('Facebook video posts are not supported yet.');
        }

        if (count($media) === 1) {
            $result = MetaGraph::post("{$page}/photos", $token, ['url' => $media[0], 'caption' => $post->body]);
            $id = $result['post_id'] ?? $result['id'];
        } else {
            $attached = collect($media)->map(fn ($url) => [
                'media_fbid' => MetaGraph::post("{$page}/photos", $token, ['url' => $url, 'published' => false])['id'],
            ])->all();

            $id = MetaGraph::post("{$page}/feed", $token, array_filter([
                'message' => $post->body,
                'attached_media' => $attached ?: null,
            ]))['id'];
        }

        return (string) $id;
    }

    public function url(string $remoteId, SocialAccount $account): string
    {
        return "https://www.facebook.com/{$remoteId}";
    }
}
