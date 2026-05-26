<?php

declare(strict_types=1);

namespace Hexis\SuluMediaCdnBundle\Command;

use Hexis\SuluMediaCdnBundle\Generator\VariationGenerator;
use Hexis\SuluMediaCdnBundle\Message\GenerateVariationsMessage;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaRepositoryInterface;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'hexis:media:regenerate-variations',
    description: 'Regenerate image variations (async by default, --sync to run in-process).',
)]
final class RegenerateVariationsCommand extends Command
{
    /**
     * @param list<array{key?: string}> $allFormats   %sulu_media.image.formats%
     */
    public function __construct(
        private readonly MediaRepositoryInterface $mediaRepository,
        private readonly VariationGenerator $generator,
        private readonly FormatCacheInterface $formatCache,
        private readonly MessageBusInterface $messageBus,
        private readonly array $allFormats,
        private readonly int $defaultBatchSize,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('media', null, InputOption::VALUE_REQUIRED, 'Comma-separated media IDs (default: all media)')
            ->addOption('collection', null, InputOption::VALUE_REQUIRED, 'Collection ID to scope the run')
            ->addOption('format', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Format keys to (re)generate (repeatable). Default: all configured formats.')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Run the conversion in-process instead of dispatching to Messenger')
            ->addOption('purge-first', null, InputOption::VALUE_NONE, 'Delete existing variations before regenerating')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Pagination size for the "all media" case');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $formatKeys */
        $formatKeys = $input->getOption('format');
        if ([] === $formatKeys) {
            $formatKeys = $this->allConfiguredFormatKeys();
        }

        $sync = (bool) $input->getOption('sync');
        $purgeFirst = (bool) $input->getOption('purge-first');
        $batchSize = (int) ($input->getOption('batch-size') ?? $this->defaultBatchSize);

        $mediaIds = $this->resolveMediaIds($input);

        if (null !== $input->getOption('media') || null !== $input->getOption('collection')) {
            $io->title(\sprintf('Regenerating variations for %d media (%s)', \count($mediaIds), $sync ? 'sync' : 'async'));
            $this->process($mediaIds, $formatKeys, $sync, $purgeFirst, $io);

            return self::SUCCESS;
        }

        $io->title(\sprintf('Regenerating variations for ALL media in batches of %d (%s)', $batchSize, $sync ? 'sync' : 'async'));

        $offset = 0;
        $total = 0;
        while (true) {
            $batch = $this->mediaRepository->findBy([], ['id' => 'ASC'], $batchSize, $offset);
            if ([] === $batch) {
                break;
            }
            $batchIds = \array_map(static fn (MediaInterface $m): int => (int) $m->getId(), $batch);
            $this->process($batchIds, $formatKeys, $sync, $purgeFirst, $io);
            $total += \count($batchIds);
            $offset += $batchSize;
            $io->writeln(\sprintf(' processed %d so far', $total));
        }

        $io->success(\sprintf('Done. %d media processed.', $total));

        return self::SUCCESS;
    }

    /**
     * @param list<int>    $mediaIds
     * @param list<string> $formatKeys
     */
    private function process(array $mediaIds, array $formatKeys, bool $sync, bool $purgeFirst, SymfonyStyle $io): void
    {
        foreach ($mediaIds as $mediaId) {
            $media = $this->mediaRepository->findMediaById($mediaId);
            if (!$media instanceof MediaInterface) {
                continue;
            }

            $file = $media->getFiles()[0] ?? null;
            $fileVersion = $file?->getLatestFileVersion();
            if (null === $fileVersion) {
                continue;
            }

            if ($purgeFirst) {
                foreach ($formatKeys as $formatKey) {
                    $this->formatCache->purge((int) $media->getId(), $fileVersion->getName(), $formatKey);
                }
            }

            if ($sync) {
                foreach ($formatKeys as $formatKey) {
                    $this->generator->generate($media, $fileVersion, $formatKey);
                }
                continue;
            }

            $this->messageBus->dispatch(new GenerateVariationsMessage(
                (int) $media->getId(),
                $fileVersion->getVersion(),
                $formatKeys,
            ));
        }
    }

    /**
     * @return list<int>
     */
    private function resolveMediaIds(InputInterface $input): array
    {
        $mediaOption = $input->getOption('media');
        if (null !== $mediaOption) {
            return \array_values(\array_filter(
                \array_map('intval', \explode(',', (string) $mediaOption)),
                static fn (int $id): bool => $id > 0,
            ));
        }

        $collectionId = $input->getOption('collection');
        if (null !== $collectionId) {
            $media = $this->mediaRepository->findMediaByCollectionId((int) $collectionId, \PHP_INT_MAX, 0);

            return \array_map(static fn (MediaInterface $m): int => (int) $m->getId(), $media);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function allConfiguredFormatKeys(): array
    {
        $keys = [];
        foreach ($this->allFormats as $format) {
            $key = (string) ($format['key'] ?? '');
            if ('' !== $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
