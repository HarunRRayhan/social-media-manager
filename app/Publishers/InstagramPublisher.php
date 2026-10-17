<?php

namespace App\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Posts to an Instagram professional account through its linked Page's token:
 * build a media container (image, reel, or carousel of up to 10), wait for videos, then publish it.
 */
class InstagramPublisher implements Publisher
{
    /** Status checks for a video container, waiting 5, 10, 15... seconds (about 4.5 minutes in total). */
    private const POLLS = 10;

    public function publish(Post $post, SocialAccount $account): string
    {
        $ig = $account->external_id;
        $token = $account->access_token;
        $media = $post->media ?? [];

        if ($media === []) {
            throw new RuntimeException('Instagram needs at least one image or video.');
        }

        if (count($media) > 10) {
            throw new RuntimeException('Instagram allows at most 10 items in a carousel.');
        }

        if (count($media) === 1) {
            $creation = $this->container($ig, $token, $media[0], ['caption' => $post->body]);
        } else {
            $children = collect($media)->map(fn ($url) => $this->container($ig, $token, $url, ['is_carousel_item' => true]))->implode(',');
            $creation = MetaGraph::post("{$ig}/media", $token, [
                'media_type' => 'CAROUSEL', 'children' => $children, 'caption' => $post->body,
            ])['id'];
        }

        return (string) MetaGraph::post("{$ig}/media_publish", $token, ['creation_id' => $creation])['id'];
    }

    public function url(string $remoteId, SocialAccount $account): ?string
    {
        return MetaGraph::get($remoteId, $account->access_token, ['fields' => 'permalink'])['permalink'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function container(string $ig, string $token, string $url, array $extra): string
    {
        $video = MetaGraph::isVideo($url);
        $params = $video
            ? ['media_type' => ($extra['is_carousel_item'] ?? false) ? 'VIDEO' : 'REELS', 'video_url' => $url]
            : ['image_url' => $url];

        $id = (string) MetaGraph::post("{$ig}/media", $token, [...$params, ...$extra])['id'];

        if ($video) {
            $this->waitUntilFinished($id, $token);
        }

        return $id;
    }

    private function waitUntilFinished(string $container, string $token): void
    {
        for ($i = 1; $i <= self::POLLS; $i++) {
            Sleep::for($i * 5)->seconds();

            $status = MetaGraph::get($container, $token, ['fields' => 'status_code,status']);
            $code = $status['status_code'] ?? null;

            if ($code === 'FINISHED') {
                return;
            }

            if (in_array($code, ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException('Instagram could not process the video: '.($status['status'] ?? $code));
            }
        }

        throw new RuntimeException('Instagram did not finish processing the video in time.');
    }
}
