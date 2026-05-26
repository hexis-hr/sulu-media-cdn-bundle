<?php

declare(strict_types=1);

namespace Hexis\SuluMediaCdnBundle\MessageHandler;

use Hexis\SuluMediaCdnBundle\Generator\VariationGenerator;
use Hexis\SuluMediaCdnBundle\Message\GenerateVariationsMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateVariationsHandler
{
    private readonly LoggerInterface $logger;

    /**
     * @param list<array{key?: string}> $allFormats         %sulu_media.image.formats%
     * @param list<string>              $excludedFormats
     */
    public function __construct(
        private readonly MediaRepositoryInterface $mediaRepository,
        private readonly VariationGenerator $generator,
        private readonly array $allFormats,
        private readonly string $syncAdminFormat,
        private readonly array $excludedFormats,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(GenerateVariationsMessage $message): void
    {
        $media = $this->mediaRepository->findMediaById($message->mediaId);
        if (!$media instanceof MediaInterface) {
            $this->logger->info('Skipping variation generation: media not found', [
                'mediaId' => $message->mediaId,
            ]);

            return;
        }

        $file = $media->getFiles()[0] ?? null;
        $fileVersion = $file?->getFileVersion($message->fileVersion);
        if (null === $fileVersion) {
            $this->logger->info('Skipping variation generation: file version not found', [
                'mediaId' => $message->mediaId,
                'fileVersion' => $message->fileVersion,
            ]);

            return;
        }

        foreach ($this->resolveFormatKeys($message->formatKeys) as $formatKey) {
            $this->generator->generate($media, $fileVersion, $formatKey);
        }
    }

    /**
     * @param  list<string>|null $whitelist
     * @return list<string>
     */
    private function resolveFormatKeys(?array $whitelist): array
    {
        if (null !== $whitelist && [] !== $whitelist) {
            return \array_values(\array_diff($whitelist, $this->excludedFormats));
        }

        $keys = [];
        foreach ($this->allFormats as $format) {
            $key = (string) ($format['key'] ?? '');
            if ('' === $key) {
                continue;
            }
            if ($key === $this->syncAdminFormat) {
                continue;
            }
            if (\in_array($key, $this->excludedFormats, true)) {
                continue;
            }
            $keys[] = $key;
        }

        return $keys;
    }
}
