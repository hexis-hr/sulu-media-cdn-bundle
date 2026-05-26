<?php

declare(strict_types=1);

namespace Hexis\SuluMediaCdnBundle\MessageHandler;

use Hexis\SuluMediaCdnBundle\Message\MigrateOriginalMessage;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Mirrors one file version's original from the source flysystem to the target
 * flysystem at the same relative path. The DB row is NOT modified - sulu_media
 * keeps reading the local copy via `sulu_media.storage.flysystem_service`,
 * S3 gets a duplicate so the CDN/variation pipeline has the bytes too.
 */
#[AsMessageHandler]
final class MigrateOriginalHandler
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly MediaRepositoryInterface $mediaRepository,
        private readonly FilesystemOperator $sourceFilesystem,
        private readonly FilesystemOperator $targetFilesystem,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(MigrateOriginalMessage $message): void
    {
        $media = $this->mediaRepository->findMediaById($message->mediaId);
        if (!$media instanceof MediaInterface) {
            $this->logger->info('Skipping original migration: media not found', [
                'mediaId' => $message->mediaId,
            ]);

            return;
        }

        $file = $media->getFiles()[0] ?? null;
        $fileVersion = $file?->getFileVersion($message->fileVersion);
        if (null === $fileVersion) {
            $this->logger->info('Skipping original migration: file version not found', [
                'mediaId' => $message->mediaId,
                'fileVersion' => $message->fileVersion,
            ]);

            return;
        }

        $path = $this->pathFor($fileVersion);
        if (null === $path) {
            $this->logger->warning('Skipping original migration: storageOptions missing fileName/directory', [
                'mediaId' => $message->mediaId,
                'fileVersionId' => $fileVersion->getId(),
            ]);

            return;
        }

        try {
            if ($this->targetFilesystem->fileExists($path)) {
                return;
            }

            if (!$this->sourceFilesystem->fileExists($path)) {
                $this->logger->warning('Original missing on source flysystem; nothing to mirror', [
                    'path' => $path,
                    'mediaId' => $message->mediaId,
                ]);

                return;
            }

            $stream = $this->sourceFilesystem->readStream($path);
            try {
                $this->targetFilesystem->writeStream($path, $stream);
            } finally {
                if (\is_resource($stream)) {
                    @\fclose($stream);
                }
            }
        } catch (FilesystemException $e) {
            $this->logger->error('Failed to mirror original to target flysystem', [
                'exception' => $e,
                'cause' => $e->getPrevious()?->getMessage(),
                'path' => $path,
                'mediaId' => $message->mediaId,
            ]);

            throw $e;
        }
    }

    private function pathFor(FileVersion $fileVersion): ?string
    {
        $opts = $fileVersion->getStorageOptions();
        if (!\is_array($opts)) {
            return null;
        }

        $directory = $opts['directory'] ?? null;
        $fileName = $opts['fileName'] ?? null;
        if (null === $directory || null === $fileName) {
            return null;
        }

        return $directory . '/' . $fileName;
    }
}
