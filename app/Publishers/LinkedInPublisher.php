<?php

namespace App\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use RuntimeException;

/**
 * Posts to a member's own LinkedIn profile: upload each image through the Images API, then create one
 * post that is text only, a single image, or a multi-image post of 2 to 20.
 */
class LinkedInPublisher implements Publisher
{
    private const MAX_CHARS = 3000;

    private const MAX_IMAGES = 20;

    // shortcut: LinkedIn documents a pixel limit (36 million) but no byte limit; raise this if real images hit it.
    private const MAX_IMAGE_BYTES = 10_000_000;

    public function publish(Post $post, SocialAccount $account): string
    {
        $text = (string) $post->body;
        $media = $post->media ?? [];

        if (collect($media)->contains(fn ($url) => MetaGraph::isVideo($url))) {
            throw new RuntimeException('LinkedIn video posts are not supported yet.');
        }

        if (collect($media)->contains(fn ($url) => str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.pdf'))) {
            throw new RuntimeException('LinkedIn document (PDF) posts are not supported yet.');
        }

        if (count($media) > self::MAX_IMAGES) {
            throw new RuntimeException('LinkedIn allows at most 20 images in a post.');
        }

        $length = mb_strlen($text);

        if ($length > self::MAX_CHARS) {
            throw new RuntimeException("LinkedIn posts are limited to 3000 characters; this one has {$length}.");
        }

        $images = array_map(fn ($url) => $this->image($account, $url), $media);

        $body = [
            'author' => $account->external_id,
            'commentary' => self::commentary($text),
            'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        if (count($images) === 1) {
            $body['content'] = ['media' => ['id' => $images[0]]];
        } elseif ($images !== []) {
            $body['content'] = ['multiImage' => ['images' => array_map(fn ($id) => ['id' => $id], $images)]];
        }

        $urn = LinkedIn::rest('posts', $account->access_token, $body)->header('x-restli-id');

        if ($urn === '') {
            throw new RuntimeException('LinkedIn did not return the new post id.');
        }

        return $urn;
    }

    public function delete(string $remoteId, SocialAccount $account): void
    {
        LinkedIn::delete('posts/'.rawurlencode($remoteId), $account->access_token);
    }

    public function url(string $remoteId, SocialAccount $account): string
    {
        return "https://www.linkedin.com/feed/update/{$remoteId}/";
    }

    /**
     * Escape the characters LinkedIn's "little" text format reserves, so the body posts as written.
     * A # that starts a word is left alone, so hashtags still link.
     */
    public static function commentary(string $text): string
    {
        return (string) preg_replace('/([\\\\|{}@\[\]()<>*_~])|#(?![\p{L}\p{N}_])/u', '\\\\$0', $text);
    }

    /**
     * Upload one image and return its urn:li:image id.
     */
    private function image(SocialAccount $account, string $url): string
    {
        [$bytes, $mime] = MediaDownload::image($url, self::MAX_IMAGE_BYTES, 'LinkedIn');

        $upload = LinkedIn::rest('images?action=initializeUpload', $account->access_token, [
            'initializeUploadRequest' => ['owner' => $account->external_id],
        ])->json('value');

        if (! is_string($upload['uploadUrl'] ?? null) || ! is_string($upload['image'] ?? null)) {
            throw new RuntimeException('LinkedIn did not return an image upload URL.');
        }

        LinkedIn::upload($upload['uploadUrl'], $account->access_token, $bytes, $mime);

        return $upload['image'];
    }
}
