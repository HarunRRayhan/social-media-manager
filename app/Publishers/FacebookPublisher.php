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
    public function publish(Post $post, SocialAccount $account): array
    {
        $page = $account->external_id;
        $token = $account->access_token;
        $media = $post->media ?? [];

        if (collect($media)->contains(fn ($url) => MetaGraph::isVideo($url))) {
            throw new RuntimeException('Facebook video posts are not supported yet.');
        }

        if (count($media) === 1) {
            $result = MetaGraph::post("{$page}/photos", ['url' => $media[0], 'caption' => $post->body, 'access_token' => $token]);
            $id = $result['post_id'] ?? $result['id'];
        } else {
            $attached = collect($media)->map(fn ($url) => [
                'media_fbid' => MetaGraph::post("{$page}/photos", ['url' => $url, 'published' => false, 'access_token' => $token])['id'],
            ])->all();

            $id = MetaGraph::post("{$page}/feed", array_filter([
                'message' => $post->body,
                'attached_media' => $attached ?: null,
                'access_token' => $token,
            ]))['id'];
        }

        return ['id' => (string) $id, 'url' => "https://www.facebook.com/{$id}"];
    }
}
