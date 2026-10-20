<?php

namespace App\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Posts to a Threads profile with its own token: build a container (text, image, video, or carousel of 2 to 20),
 * wait until Threads has fetched its media, then publish it.
 */
class ThreadsPublisher implements Publisher
{
    /** Status checks for media containers, shared by all of a post's items: now, then after 5, 10, 15, 20, 25 seconds (75s in total), so a carousel stays well inside the job timeout. */
    private const POLLS = 6;

    public function publish(Post $post, SocialAccount $account): string
    {
        $user = $account->external_id;
        $token = $account->access_token;
        $media = $post->media ?? [];

        // shortcut: counts characters, while Threads counts emoji by UTF-8 bytes, so an emoji-heavy post near 500 may still be refused by the API.
        if (mb_strlen((string) $post->body) > 500) {
            throw new RuntimeException('Threads allows at most 500 characters.');
        }

        if (count($media) > 20) {
            throw new RuntimeException('Threads allows at most 20 items in a carousel.');
        }

        if ($media === []) {
            $creation = $this->container($user, $token, ['media_type' => 'TEXT', 'text' => $post->body]);
        } elseif (count($media) === 1) {
            $creation = $this->container($user, $token, [...$this->item($media[0]), 'text' => $post->body]);
            $this->waitUntilFinished([$creation], $token);
        } else {
            $children = collect($media)->map(fn ($url) => $this->container($user, $token, [...$this->item($url), 'is_carousel_item' => true]))->all();
            $this->waitUntilFinished($children, $token);

            $creation = $this->container($user, $token, ['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'text' => $post->body]);
            // Five polls (about 50s) keep both waits inside the job's 420s timeout.
            $this->waitUntilFinished([$creation], $token, 5);
        }

        return (string) ThreadsGraph::post("{$user}/threads_publish", $token, ['creation_id' => $creation])['id'];
    }

    public function url(string $remoteId, SocialAccount $account): ?string
    {
        return ThreadsGraph::get($remoteId, $account->access_token, ['fields' => 'permalink'])['permalink'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function item(string $url): array
    {
        return MetaGraph::isVideo($url) ? ['media_type' => 'VIDEO', 'video_url' => $url] : ['media_type' => 'IMAGE', 'image_url' => $url];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function container(string $user, string $token, array $params): string
    {
        return (string) ThreadsGraph::post("{$user}/threads", $token, array_filter($params, fn ($value) => $value !== null))['id'];
    }

    /**
     * Poll containers until all are FINISHED, within one shared bound. Images are usually done at the first check.
     *
     * @param  array<int, string>  $containers
     */
    private function waitUntilFinished(array $containers, string $token, int $polls = self::POLLS): void
    {
        for ($i = 1; $i <= $polls && $containers !== []; $i++) {
            foreach ($containers as $key => $container) {
                $status = ThreadsGraph::get($container, $token, ['fields' => 'status,error_message']);
                $code = $status['status'] ?? null;

                if (in_array($code, ['ERROR', 'EXPIRED'], true)) {
                    throw new RuntimeException('Threads could not process the media: '.($status['error_message'] ?? $code));
                }

                if ($code === 'FINISHED') {
                    unset($containers[$key]);
                }
            }

            if ($containers !== [] && $i < $polls) {
                Sleep::for($i * 5)->seconds();
            }
        }

        if ($containers !== []) {
            throw new RuntimeException('Threads did not finish processing the media in time.');
        }
    }
}
