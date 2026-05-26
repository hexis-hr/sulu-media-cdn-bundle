<?php

declare(strict_types=1);

namespace Hexis\SuluMediaCdnBundle\FormatManager;

use Hexis\SuluMediaCdnBundle\FormatCache\FlysystemFormatCache;
use Hexis\SuluMediaCdnBundle\Message\GenerateVariationsMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaRepositoryInterface;
use Sulu\Bundle\MediaBundle\Media\Exception\ImageProxyException;
use Sulu\Bundle\MediaBundle\Media\Exception\ImageProxyInvalidUrl;
use Sulu\Bundle\MediaBundle\Media\Exception\ImageProxyMediaNotFoundException;
use Sulu\Bundle\MediaBundle\Media\FormatManager\FormatManagerInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Decorates Sulu's FormatManager to remove on-the-fly conversion from the
 * web request. Reads variations from FlysystemFormatCache (S3 in prod);
 * on miss, applies the configured strategy and optionally dispatches a
 * self-healing GenerateVariationsMessage.
 */
final class AsyncFormatManager implements FormatManagerInterface
{
    public const STRATEGY_SERVE_ORIGINAL = 'serve_original';
    public const STRATEGY_ON_THE_FLY = 'on_the_fly';
    public const STRATEGY_404 = '404';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly FormatManagerInterface $inner,
        private readonly FlysystemFormatCache $formatCache,
        private readonly MediaRepositoryInterface $mediaRepository,
        private readonly StorageInterface $storage,
        private readonly MessageBusInterface $messageBus,
        private readonly string $missStrategy,
        private readonly bool $regenerateOnMiss,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function returnImage($id, $formatKey, $fileName, ?int $version = null)
    {
        try {
            $info = \pathinfo((string) $fileName);
            if (!isset($info['extension'])) {
                throw new ImageProxyInvalidUrl(\sprintf('No `extension` was found in the url "%s".', $fileName));
            }
            $imageFormat = $info['extension'];

            $media = $this->mediaRepository->findMediaByIdForRendering((int) $id, (string) $formatKey, $version);
            if (!$media) {
                throw new ImageProxyMediaNotFoundException(\sprintf('Media with id "%s" was not found.', $id));
            }

            $fileVersion = $this->getLatestFileVersion($media);
            $variationFileName = $this->replaceExtension($fileVersion->getName(), $imageFormat);

            if ($this->formatCache->exists((int) $media->getId(), $variationFileName, (string) $formatKey)) {
                // When presigning is enabled, generate the signed URL right here
                // (at request time). getMediaUrl() returned the proxy URL so HTML
                // is safe from `&inline=1` mangling; the 302 target is fresh and
                // un-tampered.
                $presigned = $this->formatCache->presignedUrl(
                    (int) $media->getId(),
                    $variationFileName,
                    (string) $formatKey,
                );
                if (null !== $presigned) {
                    return new RedirectResponse($presigned, 302);
                }

                $url = $this->formatCache->getMediaUrl(
                    (int) $media->getId(),
                    $variationFileName,
                    (string) $formatKey,
                    $fileVersion->getVersion(),
                    $fileVersion->getSubVersion(),
                );

                // Only 302-redirect when the variation lives on a DIFFERENT host (CDN).
                // For relative URLs (no CDN configured), redirecting back to the same
                // proxy path would loop, so we stream the variation from flysystem.
                if (\preg_match('#^https?://#', $url)) {
                    return new RedirectResponse($url, 302);
                }

                return $this->streamVariation(
                    (int) $media->getId(),
                    $variationFileName,
                    (string) $formatKey,
                    $fileVersion->getMimeType(),
                );
            }

            if ($this->regenerateOnMiss) {
                $this->dispatchRegeneration((int) $media->getId(), $fileVersion->getVersion(), (string) $formatKey);
            }

            return match ($this->missStrategy) {
                self::STRATEGY_ON_THE_FLY => $this->inner->returnImage($id, $formatKey, $fileName, $version),
                self::STRATEGY_404 => new Response(null, 404),
                default => $this->serveOriginal($fileVersion),
            };
        } catch (ImageProxyException $e) {
            $this->logger->debug($e->getMessage(), ['exception' => $e]);

            return new Response(null, 404);
        }
    }

    public function getFormats($id, $fileName, $version, $subVersion, $mimeType)
    {
        return $this->inner->getFormats($id, $fileName, $version, $subVersion, $mimeType);
    }

    public function getFormatDefinition($formatKey, $locale = null)
    {
        return $this->inner->getFormatDefinition($formatKey, $locale);
    }

    public function getFormatDefinitions($locale = null)
    {
        return $this->inner->getFormatDefinitions($locale);
    }

    public function purge($idMedia, $fileName, $mimeType)
    {
        return $this->inner->purge($idMedia, $fileName, $mimeType);
    }

    public function clearCache(): void
    {
        $this->inner->clearCache();
    }

    private function streamVariation(int $mediaId, string $fileName, string $formatKey, ?string $mimeType): Response
    {
        try {
            $resource = $this->formatCache->readStream($mediaId, $fileName, $formatKey);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to stream cached variation from flysystem', [
                'exception' => $e,
                'mediaId' => $mediaId,
                'formatKey' => $formatKey,
            ]);

            return new Response(null, 404);
        }

        $response = new StreamedResponse(static function () use ($resource): void {
            $out = \fopen('php://output', 'wb');
            if (false === $out) {
                return;
            }
            \stream_copy_to_stream($resource, $out);
            \fclose($out);
        });

        $response->headers->set('Content-Type', $this->variationMimeType($fileName, $mimeType));
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');

        return $response;
    }

    private function variationMimeType(string $fileName, ?string $fallback): string
    {
        $ext = \strtolower((string) \pathinfo($fileName, \PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'gif' => 'image/gif',
            default => $fallback ?? 'application/octet-stream',
        };
    }

    private function serveOriginal(FileVersion $fileVersion): Response
    {
        try {
            $resource = $this->storage->load($fileVersion->getStorageOptions());
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load original FileVersion for miss-fallback', [
                'exception' => $e,
                'fileVersion' => $fileVersion->getId(),
            ]);

            return new Response(null, 404);
        }

        $response = new StreamedResponse(static function () use ($resource): void {
            $out = \fopen('php://output', 'wb');
            if (false === $out) {
                return;
            }
            \stream_copy_to_stream($resource, $out);
            \fclose($out);
        });

        $response->headers->set('Content-Type', $fileVersion->getMimeType() ?? 'application/octet-stream');
        // Short cache so a CDN doesn't pin the unprocessed original forever;
        // by the time it expires, the worker has usually generated the variation.
        $response->headers->set('Cache-Control', 'public, max-age=60, must-revalidate');

        return $response;
    }

    private function dispatchRegeneration(int $mediaId, int $version, string $formatKey): void
    {
        try {
            $this->messageBus->dispatch(new GenerateVariationsMessage($mediaId, $version, [$formatKey]));
        } catch (MessengerException $e) {
            // Self-healing is best-effort; never block the request on a queue outage.
            $this->logger->warning('Failed to dispatch GenerateVariationsMessage', [
                'exception' => $e,
                'mediaId' => $mediaId,
                'version' => $version,
                'formatKey' => $formatKey,
            ]);
        }
    }

    private function getLatestFileVersion(MediaInterface $media): FileVersion
    {
        $file = $media->getFiles()[0] ?? null;
        if (null === $file) {
            throw new ImageProxyMediaNotFoundException(\sprintf('Media "%s" has no files.', $media->getId()));
        }

        $fileVersion = $file->getLatestFileVersion();
        if (null === $fileVersion) {
            throw new ImageProxyMediaNotFoundException(\sprintf('Media "%s" has no file version.', $media->getId()));
        }

        return $fileVersion;
    }

    private function replaceExtension(string $fileName, string $extension): string
    {
        $info = \pathinfo($fileName);

        return ($info['filename'] ?? '') . '.' . $extension;
    }
}
