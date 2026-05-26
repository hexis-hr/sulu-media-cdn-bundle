<?php

declare(strict_types=1);

namespace Hexis\SuluMediaCdnBundle\EventSubscriber;

use Hexis\SuluMediaCdnBundle\Generator\VariationGenerator;
use Hexis\SuluMediaCdnBundle\Message\GenerateVariationsMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Bundle\MediaBundle\Domain\Event\MediaCreatedEvent;
use Sulu\Bundle\MediaBundle\Domain\Event\MediaVersionAddedEvent;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pre-generates variations on upload: sync for the admin thumbnail format,
 * async for everything else via Messenger.
 */
final class MediaUploadSubscriber implements EventSubscriberInterface
{
    private readonly LoggerInterface $logger;

    /**
     * @param list<string> $asyncFormats     Empty = handler resolves "all minus sync_admin_format minus excluded"
     * @param list<string> $excludedFormats
     */
    public function __construct(
        private readonly VariationGenerator $generator,
        private readonly MessageBusInterface $messageBus,
        private readonly bool $onMediaCreated,
        private readonly bool $onMediaVersionAdded,
        private readonly ?string $syncAdminFormat,
        private readonly array $asyncFormats,
        private readonly array $excludedFormats,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MediaCreatedEvent::class => 'onMediaCreated',
            MediaVersionAddedEvent::class => 'onMediaVersionAdded',
        ];
    }

    public function onMediaCreated(MediaCreatedEvent $event): void
    {
        if (!$this->onMediaCreated) {
            return;
        }

        $media = $event->getMedia();
        $file = $media->getFiles()[0] ?? null;
        $fileVersion = $file?->getLatestFileVersion();

        if (null === $fileVersion) {
            return;
        }

        $this->handle($media, $fileVersion);
    }

    public function onMediaVersionAdded(MediaVersionAddedEvent $event): void
    {
        if (!$this->onMediaVersionAdded) {
            return;
        }

        $media = $event->getMedia();
        $file = $media->getFiles()[0] ?? null;
        $fileVersion = $file?->getFileVersion($event->getVersion());

        if (null === $fileVersion) {
            return;
        }

        $this->handle($media, $fileVersion);
    }

    private function handle(MediaInterface $media, FileVersion $fileVersion): void
    {
        if (!$this->isImage($fileVersion)) {
            return;
        }

        if (null !== $this->syncAdminFormat && '' !== $this->syncAdminFormat) {
            try {
                $this->generator->generate($media, $fileVersion, $this->syncAdminFormat);
            } catch (\Throwable $e) {
                $this->logger->error('Sync admin variation generation failed', [
                    'exception' => $e,
                    'mediaId' => $media->getId(),
                    'formatKey' => $this->syncAdminFormat,
                ]);
            }
        }

        $whitelist = [] === $this->asyncFormats
            ? null
            : \array_values(\array_diff($this->asyncFormats, $this->excludedFormats));

        try {
            $this->messageBus->dispatch(new GenerateVariationsMessage(
                (int) $media->getId(),
                $fileVersion->getVersion(),
                $whitelist,
            ));
        } catch (MessengerException $e) {
            $this->logger->error('Failed to dispatch GenerateVariationsMessage', [
                'exception' => $e,
                'mediaId' => $media->getId(),
            ]);
        }
    }

    private function isImage(FileVersion $fileVersion): bool
    {
        $mimeType = $fileVersion->getMimeType();

        return null !== $mimeType && \str_starts_with($mimeType, 'image/');
    }
}
