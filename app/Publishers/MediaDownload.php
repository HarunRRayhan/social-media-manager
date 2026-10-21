<?php

namespace App\Publishers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Downloads a post's image or video for platforms that take raw bytes instead of a URL (Bluesky, LinkedIn, X).
 */
class MediaDownload
{
    /**
     * @param  string  $label  the platform name every error message starts with
     * @return array{0: string, 1: string} the bytes and their Content-Type
     */
    public static function image(string $url, int $maxBytes, string $label): array
    {
        return self::fetch($url, $maxBytes, $label, 'image');
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function video(string $url, int $maxBytes, string $label): array
    {
        return self::fetch($url, $maxBytes, $label, 'video');
    }

    /**
     * @param  'image'|'video'  $kind  the Content-Type prefix the file must have
     * @return array{0: string, 1: string}
     */
    private static function fetch(string $url, int $maxBytes, string $label, string $kind): array
    {
        $megabytes = $maxBytes / 1_000_000;
        $noun = $kind === 'image' ? 'an image' : 'a video';

        // Unlike Meta, SMM fetches the media itself, so a post URL must never reach a private address.
        // The checked IP is pinned for the request and redirects are off, so DNS rebinding cannot swap it.
        $parts = parse_url($url);
        // Normalized the way curl matches hosts, so the pinned entry is the one curl uses.
        $host = rtrim(strtolower((string) ($parts['host'] ?? '')), '.');
        $port = $parts['port'] ?? (($parts['scheme'] ?? '') === 'http' ? 80 : 443);
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (! preg_match('/^[a-z0-9.-]+$/', $host)
            || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            || IpUtils::isPrivateIp($ip)) {
            throw new RuntimeException("{$label}: {$noun} URL does not point to a public host.");
        }

        try {
            $file = Http::timeout(60)->withoutRedirecting()
                ->withOptions(['proxy' => '', 'curl' => [
                    CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
                    // Aborts mid-transfer past the limit, not only on a declared Content-Length.
                    CURLOPT_MAXFILESIZE => $maxBytes,
                ]])
                ->get($url);
        } catch (ConnectionException|RequestException) {
            // CURLOPT_MAXFILESIZE aborts the transfer, so an oversized image lands here too.
            throw new RuntimeException("{$label}: could not download {$noun}, or it is over {$megabytes} MB.");
        }

        if ($file->failed() || $file->redirect()) {
            throw new RuntimeException("{$label}: could not download {$noun} (HTTP {$file->status()}).");
        }

        $mime = (string) $file->header('Content-Type');

        if (! str_starts_with($mime, "{$kind}/")) {
            throw new RuntimeException("{$label}: a media URL is not {$noun}.");
        }

        if (strlen($file->body()) > $maxBytes) {
            throw new RuntimeException("{$label}: {$kind}s must be {$megabytes} MB or smaller.");
        }

        return [$file->body(), $mime];
    }
}
