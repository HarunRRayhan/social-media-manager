<?php

namespace App\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use RuntimeException;

/**
 * Posts to a Bluesky account with its app password: sign in, upload up to 4 images as blobs,
 * then create one app.bsky.feed.post record with link facets and an images embed.
 */
class BlueskyPublisher implements Publisher
{
    private const MAX_GRAPHEMES = 300;

    private const MAX_IMAGES = 4;

    // Bluesky's blob limit for images.
    private const MAX_IMAGE_BYTES = 1_000_000;

    public function publish(Post $post, SocialAccount $account): string
    {
        $text = (string) $post->body;
        $media = $post->media ?? [];

        if (collect($media)->contains(fn ($url) => MetaGraph::isVideo($url))) {
            throw new RuntimeException('Bluesky video posts are not supported yet.');
        }

        if (count($media) > self::MAX_IMAGES) {
            throw new RuntimeException('Bluesky allows at most 4 images in a post.');
        }

        $length = (int) preg_match_all('/\X/u', $text);

        if ($length > self::MAX_GRAPHEMES) {
            throw new RuntimeException("Bluesky posts are limited to 300 characters; this one has {$length}.");
        }

        $session = Bluesky::session($account->external_id, $account->access_token);
        $token = $session['accessJwt'];

        $record = array_filter([
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => now()->toIso8601ZuluString('millisecond'),
            'facets' => self::linkFacets($text) ?: null,
            'embed' => $media ? [
                '$type' => 'app.bsky.embed.images',
                'images' => array_map(fn ($url) => ['alt' => '', 'image' => $this->blob($token, $url)], $media),
            ] : null,
        ]);

        return (string) Bluesky::post('com.atproto.repo.createRecord', $token, [
            'repo' => $account->external_id,
            'collection' => 'app.bsky.feed.post',
            'record' => $record,
        ])['uri'];
    }

    public function url(string $remoteId, SocialAccount $account): string
    {
        return "https://bsky.app/profile/{$account->external_id}/post/".basename($remoteId);
    }

    /**
     * Link facets for every http(s) URL, with UTF-8 byte offsets as Bluesky requires.
     *
     * @return list<array<string, mixed>>
     */
    private static function linkFacets(string $text): array
    {
        // PREG_OFFSET_CAPTURE offsets are already in bytes. Trailing punctuation stays out of the link.
        preg_match_all('#https?://[^\s]+?(?=[.,;:!?)\]]*(?:\s|$))#u', $text, $matches, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $match) => [
            'index' => ['byteStart' => $match[1], 'byteEnd' => $match[1] + strlen($match[0])],
            'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $match[0]]],
        ], $matches[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function blob(string $token, string $url): array
    {
        [$bytes, $mime] = MediaDownload::image($url, self::MAX_IMAGE_BYTES, 'Bluesky');

        return Bluesky::upload($token, $bytes, $mime);
    }
}
