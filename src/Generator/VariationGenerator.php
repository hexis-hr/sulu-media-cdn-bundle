<?php

declare(strict_types=1);

namespace Hexis\SuluMediaCdnBundle\Generator;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\ImageConverterInterface;

/**
 * Runs the Imagine conversion and persists every supported output extension
 * for one (Media, FileVersion, formatKey) triple. The ONLY place in this
 * bundle where ImageConverterInterface::convert() is invoked - keeps
 * memory cost isolated to the Messenger worker or the upload subscriber.
 */
final class VariationGenerator
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ImageConverterInterface $converter,
        private readonly FormatCacheInterface $formatCache,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Generate and persist every supported output extension for one format key.
     */
    public function generate(MediaInterface $media, FileVersion $fileVersion, string $formatKey): bool
    {
        $mimeType = $fileVersion->getMimeType();
        $extensions = $this->converter->getSupportedOutputImageFormats($mimeType);

        if ([] === $extensions) {
            return false;
        }

        $success = true;
        foreach ($extensions as $extension) {
            try {
                $content = $this->converter->convert($fileVersion, $formatKey, $extension);
            } catch (\Throwable $e) {
                $this->logger->error('Conversion failed', [
                    'exception' => $e,
                    'mediaId' => $media->getId(),
                    'fileVersion' => $fileVersion->getId(),
                    'formatKey' => $formatKey,
                    'extension' => $extension,
                ]);
                $success = false;
                continue;
            }

            $saved = $this->formatCache->save(
                $content,
                (int) $media->getId(),
                $this->replaceExtension($fileVersion->getName(), $extension),
                $formatKey,
            );

            if (!$saved) {
                $this->logger->error('Failed to persist variation', [
                    'mediaId' => $media->getId(),
                    'fileVersion' => $fileVersion->getId(),
                    'formatKey' => $formatKey,
                    'extension' => $extension,
                ]);
                $success = false;
            }
        }

        return $success;
    }

    private function replaceExtension(string $fileName, string $extension): string
    {
        $info = \pathinfo($fileName);

        return ($info['filename'] ?? '') . '.' . $extension;
    }
}
