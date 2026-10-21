<?php

namespace App\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Posts to an X account: upload up to 4 images or 1 video through the chunked media upload, then create one post.
 * The access token lasts two hours, so it is renewed first when it is about to expire.
 */
class XPublisher implements Publisher
{
    private const MAX_CHARS = 280;

    private const MAX_IMAGES = 4;

    // tweet_image allows 5 MB.
    private const MAX_IMAGE_BYTES = 5_000_000;

    // shortcut: X takes far larger videos, but the file is held in memory; raise this once uploads stream from disk.
    private const MAX_VIDEO_BYTES = 50_000_000;

    private const CHUNK_BYTES = 4_000_000;

    // Video processing is polled at most this long, in check_after_secs steps.
    private const MAX_WAIT_SECONDS = 300;

    public function publish(Post $post, SocialAccount $account): string
    {
        $text = (string) $post->body;
        $media = $post->media ?? [];
        $videos = array_values(array_filter($media, fn ($url) => MetaGraph::isVideo($url)));

        if ($videos !== [] && count($media) > 1) {
            throw new RuntimeException('X takes either one video or up to 4 images in a post, not both.');
        }

        if (count($media) > self::MAX_IMAGES) {
            throw new RuntimeException('X allows at most 4 images in a post.');
        }

        $length = self::length($text);

        if ($length > self::MAX_CHARS) {
            throw new RuntimeException("X posts are limited to 280 characters; this one has {$length}.");
        }

        $account = X::fresh($account);
        $token = $account->access_token;

        $ids = $videos !== []
            ? [$this->upload($token, ...MediaDownload::video($videos[0], self::MAX_VIDEO_BYTES, 'X'), category: 'tweet_video')]
            : array_map(fn ($url) => $this->upload($token, ...MediaDownload::image($url, self::MAX_IMAGE_BYTES, 'X'), category: 'tweet_image'), $media);

        $body = ['text' => $text];

        if ($ids !== []) {
            $body['media'] = ['media_ids' => $ids];
        }

        $id = X::post('tweets', $token, $body)['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('X did not return the new post id.');
        }

        return $id;
    }

    public function delete(string $remoteId, SocialAccount $account): void
    {
        X::delete('tweets/'.rawurlencode($remoteId), X::fresh($account)->access_token);
    }

    public function url(string $remoteId, SocialAccount $account): string
    {
        return 'https://x.com/'.($account->username ?: 'i/web')."/status/{$remoteId}";
    }

    /**
     * How X counts a post: every URL weighs 23 characters whatever its length.
     * shortcut: CJK and emoji weighting is skipped; X itself still refuses a post that is over.
     */
    public static function length(string $text): int
    {
        return mb_strlen((string) preg_replace('~https?://\S+~u', str_repeat('x', 23), $text));
    }

    /**
     * INIT, APPEND in chunks, FINALIZE, then wait for processing. Returns the media id.
     */
    private function upload(string $token, string $bytes, string $mime, string $category): string
    {
        $id = X::post('media/upload/initialize', $token, ['media_type' => $mime, 'total_bytes' => strlen($bytes), 'media_category' => $category])['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('X did not return a media id.');
        }

        foreach (str_split($bytes, self::CHUNK_BYTES) as $index => $chunk) {
            X::append($id, $token, $index, $chunk);
        }

        $info = X::post("media/upload/{$id}/finalize", $token, [])['processing_info'] ?? null;
        $waited = 0;

        // Images are done at FINALIZE; a video reports pending or in_progress until X has transcoded it.
        while (is_array($info) && ! in_array($info['state'] ?? null, ['succeeded', 'failed'], true)) {
            if ($waited >= self::MAX_WAIT_SECONDS) {
                throw new RuntimeException('X did not finish processing the video in time.');
            }

            $wait = max(1, (int) ($info['check_after_secs'] ?? 5));
            Sleep::for($wait)->seconds();
            $waited += $wait;

            $info = X::get('media/upload', $token, ['command' => 'STATUS', 'media_id' => $id])['processing_info'] ?? ['state' => 'succeeded'];
        }

        if (($info['state'] ?? null) === 'failed') {
            throw new RuntimeException('X could not process the media: '.($info['error']['message'] ?? 'unknown error'));
        }

        return $id;
    }
}
