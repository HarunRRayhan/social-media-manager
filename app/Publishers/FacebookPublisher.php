<?php

namespace App\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Posts to a Facebook Page with its page access token: text to /feed, one photo to /photos,
 * several photos as unpublished uploads attached to one /feed post, one video as a Reel.
 */
class FacebookPublisher implements Publisher
{
    /** Upload status checks for a Reel, waiting 5, 10, 15... seconds (about 4.5 minutes in total). */
    private const POLLS = 10;

    public function publish(Post $post, SocialAccount $account): string
    {
        $page = $account->external_id;
        $token = $account->access_token;
        $media = $post->media ?? [];
        $hasVideo = collect($media)->contains(fn ($url) => MetaGraph::isVideo($url));

        if ($hasVideo && count($media) > 1) {
            throw new RuntimeException('Facebook cannot mix a video with other media; post the video on its own as a Reel.');
        }

        if ($hasVideo) {
            return $this->reel($page, $token, $media[0], $post->body);
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

    /**
     * Reels publishing API: start a session, have Meta fetch the hosted file, wait for the upload, then publish.
     * Nothing is public until the finish call, so every failure before it leaves no post behind.
     */
    private function reel(string $page, string $token, string $url, ?string $description): string
    {
        $videoId = (string) MetaGraph::post("{$page}/video_reels", $token, ['upload_phase' => 'start'])['video_id'];

        MetaGraph::uploadFromUrl($videoId, $token, $url);
        $this->waitForUpload($videoId, $token);

        MetaGraph::post("{$page}/video_reels", $token, array_filter([
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
            'description' => $description,
        ]));

        return $videoId;
    }

    private function waitForUpload(string $videoId, string $token): void
    {
        for ($i = 1; $i <= self::POLLS; $i++) {
            $status = MetaGraph::get($videoId, $token, ['fields' => 'status'])['status'] ?? [];
            $state = $status['video_status'] ?? null;

            if (in_array($state, ['error', 'expired', 'upload_failed'], true)) {
                throw new RuntimeException("Facebook could not take the Reel video ({$state}).");
            }

            // The docs use both spellings.
            if (in_array($status['uploading_phase']['status'] ?? null, ['complete', 'completed'], true)) {
                return;
            }

            Sleep::for($i * 5)->seconds();
        }

        throw new RuntimeException('Facebook did not finish receiving the Reel video in time.');
    }
}
