<?php

namespace KirbyBunny\Stream;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Http\Response;

class Webhook
{
    public static function handle(): Response
    {
        $rawBody = file_get_contents('php://input');

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new Response('Invalid JSON payload', 'text/plain', 400);
        }

        if (!$payload || !is_array($payload)) {
            return new Response('Invalid payload', 'text/plain', 400);
        }

        // Verify webhook secret if configured
        $secret = App::instance()->option('jonasfeige.kirby-bunny-stream.webhookSecret');
        if ($secret) {
            $signature = $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '';
            // Use raw body for signature verification to avoid JSON encoding inconsistencies
            if (!self::verifySignature($rawBody, $signature, $secret)) {
                return new Response('Unauthorized', 'text/plain', 401);
            }
        }

        // Handle encoding complete event (status 4 = Finished)
        $status = $payload['Status'] ?? null;
        $videoId = $payload['VideoGuid'] ?? null;

        if ($status === 4 && $videoId) {
            self::onEncodingComplete($videoId);
        }

        return new Response('OK', 'text/plain', 200);
    }

    /**
     * Verify webhook signature using HMAC-SHA256.
     * Uses raw body to avoid JSON encoding inconsistencies.
     */
    private static function verifySignature(string $rawBody, string $signature, string $secret): bool
    {
        $expectedSignature = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expectedSignature, $signature);
    }

    private static function onEncodingComplete(string $videoId): void
    {
        $file = self::findFileByVideoId($videoId);
        if (!$file) {
            return;
        }

        try {
            $client = BunnyStreamClient::instance();

            // Download final thumbnail
            $thumbnailData = $client->downloadThumbnail($videoId);
            if ($thumbnailData) {
                // Overwrite the placeholder thumbnail
                file_put_contents($file->root(), $thumbnailData);
            }

            // Refresh metadata
            $videoData = $client->getVideo($videoId);
            $file->update([
                'bunnydata' => json_encode($videoData),
            ]);
        } catch (\Exception $e) {
            kirby()->log('bunny-stream')->error('Webhook processing failed', [
                'videoId' => $videoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function findFileByVideoId(string $videoId): ?File
    {
        $site = App::instance()->site();

        // Search all pages for the file
        foreach ($site->index() as $page) {
            foreach ($page->files()->filterBy('template', 'bunny-video') as $file) {
                if ($file->content()->bunnyvideoid()->value() === $videoId) {
                    return $file;
                }
            }
        }

        // Also check site files
        foreach ($site->files()->filterBy('template', 'bunny-video') as $file) {
            if ($file->content()->bunnyvideoid()->value() === $videoId) {
                return $file;
            }
        }

        return null;
    }
}
