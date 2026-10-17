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

    public function publish(Post $post, SocialAccount $account): array
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
            $creation = MetaGraph::post("{$ig}/media", [
                'media_type' => 'CAROUSEL', 'children' => $children, 'caption' => $post->body, 'access_token' => $token,
            ])['id'];
        }

        $id = (string) MetaGraph::post("{$ig}/media_publish", ['creation_id' => $creation, 'access_token' => $token])['id'];

        // The post is live by now; a failed permalink lookup must not mark it failed.
        $url = rescue(fn () => MetaGraph::get($id, ['fields' => 'permalink', 'access_token' => $token])['permalink'] ?? null, null, false);

        return ['id' => $id, 'url' => $url];
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

        $id = (string) MetaGraph::post("{$ig}/media", [...$params, ...$extra, 'access_token' => $token])['id'];

        if ($video) {
            $this->waitUntilFinished($id, $token);
        }

        return $id;
    }

    private function waitUntilFinished(string $container, string $token): void
    {
        for ($i = 1; $i <= self::POLLS; $i++) {
            Sleep::for($i * 5)->seconds();

            $status = MetaGraph::get($container, ['fields' => 'status_code,status', 'access_token' => $token]);
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
