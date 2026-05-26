<?php

declare(strict_types=1);

namespace Hexis\SuluMediaCdnBundle\FormatCache;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Bundle\MediaBundle\Media\Exception\ImageProxyInvalidUrl;
use Sulu\Bundle\MediaBundle\Media\Exception\ImageProxyUrlNotFoundException;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;

final class FlysystemFormatCache implements FormatCacheInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly FilesystemOperator $filesystem,
        private readonly string $pathPrefix,
        private readonly string $cdnBaseUrl,
        private readonly string $mediaProxyPath,
        private readonly int $segments,
        private readonly bool $cdnEnabled,
        private readonly bool $versionQuery,
        private readonly string $objectKeyPrefix = '',
        private readonly bool $presignEnabled = false,
        private readonly int $presignTtl = 3600,
        private readonly ?string $visibility = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function save($content, $id, $fileName, $format)
    {
        $config = null !== $this->visibility ? ['visibility' => $this->visibility] : [];
        $path = $this->getStoragePath((int) $id, (string) $fileName, (string) $format);

        try {
            $this->filesystem->write($path, $content, $config);
        } catch (FilesystemException $e) {
            $this->logger->error('Failed to write variation to flysystem', [
                'exception' => $e,
                'cause' => $e->getPrevious()?->getMessage(),
                'path' => $path,
                'visibility' => $this->visibility,
            ]);

            return false;
        }

        return true;
    }

    public function purge($id, $fileName, $format)
    {
        try {
            $this->filesystem->delete(
                $this->getStoragePath((int) $id, (string) $fileName, (string) $format),
            );
        } catch (FilesystemException) {
            // already gone; treat as success
        }

        return true;
    }

    public function getMediaUrl($id, $fileName, $format, $version, $subVersion)
    {
        $slug = $this->getRelativeSlug((int) $id, (string) $fileName, (string) $format);

        if ($this->cdnEnabled && '' !== $this->cdnBaseUrl && !$this->presignEnabled) {
            // CDN points at the storage backend (S3, public bucket, CloudFront).
            // The URL must match the actual object key.
            $parts = \array_filter([
                \trim($this->objectKeyPrefix, '/'),
                \trim($this->pathPrefix, '/'),
                $slug,
            ], static fn (string $p): bool => '' !== $p);

            $url = \rtrim($this->cdnBaseUrl, '/') . '/' . \implode('/', $parts);
        } else {
            // Use Sulu's relative proxy path. This handles two cases:
            // 1. No CDN configured -> Symfony origin streams the variation.
            // 2. Presigning is enabled -> we MUST NOT return the presigned URL here
            //    because Sulu's admin JS (MediaFormats.js) and the Twig disposition
            //    helper blindly append `&inline=1` after whatever URL we return,
            //    which corrupts the AWS SigV4 signature. The proxy indirection lets
            //    us presign at request time inside AsyncFormatManager, where no
            //    further mangling can happen between signing and the 302.
            $url = \str_replace('{slug}', $slug, $this->mediaProxyPath);
        }

        if ($this->versionQuery) {
            $url .= '?v=' . $version . '-' . $subVersion;
        }

        return $url;
    }

    public function presignedUrl(int $id, string $fileName, string $format): ?string
    {
        if (!$this->presignEnabled) {
            return null;
        }

        $path = $this->getStoragePath($id, $fileName, $format);

        try {
            return $this->filesystem->temporaryUrl(
                $path,
                new \DateTimeImmutable('+' . $this->presignTtl . ' seconds'),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    public function analyzedMediaUrl($url)
    {
        if (empty($url)) {
            throw new ImageProxyUrlNotFoundException('The given url was empty');
        }

        $basename = \basename($url);
        $idParts = \explode('-', $basename, 2);

        if (\count($idParts) < 2 || '' === $idParts[1]) {
            throw new ImageProxyInvalidUrl('No `filename` was found in the url');
        }

        $id = $idParts[0];

        if (\preg_match('/[^0-9]/', $id)) {
            throw new ImageProxyInvalidUrl('The found `id` was not a valid integer');
        }

        $pathParts = \array_reverse(\explode('/', \dirname($url)));

        if (\count($pathParts) < 2) {
            throw new ImageProxyInvalidUrl('No `format` was found in the url');
        }

        return [
            'id' => (int) $id,
            'format' => $pathParts[1],
            'fileName' => \rawurldecode($idParts[1]),
        ];
    }

    public function clear()
    {
        try {
            $this->filesystem->deleteDirectory(\trim($this->pathPrefix, '/'));
        } catch (FilesystemException $e) {
            throw new \RuntimeException('Unable to clear format cache: ' . $e->getMessage(), previous: $e);
        }
    }

    public function exists(int $id, string $fileName, string $format): bool
    {
        try {
            return $this->filesystem->fileExists($this->getStoragePath($id, $fileName, $format));
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * @return resource
     */
    public function readStream(int $id, string $fileName, string $format)
    {
        return $this->filesystem->readStream($this->getStoragePath($id, $fileName, $format));
    }

    private function getStoragePath(int $id, string $fileName, string $format): string
    {
        return \sprintf(
            '%s/%s/%s/%d-%s',
            \trim($this->pathPrefix, '/'),
            $format,
            $this->getSegment($id),
            $id,
            $fileName,
        );
    }

    private function getRelativeSlug(int $id, string $fileName, string $format): string
    {
        return \sprintf(
            '%s/%s/%d-%s',
            $format,
            $this->getSegment($id),
            $id,
            \rawurlencode($fileName),
        );
    }

    private function getSegment(int $id): string
    {
        $width = \strlen((string) $this->segments);

        return \sprintf('%0' . $width . 'd', $id % $this->segments);
    }
}
