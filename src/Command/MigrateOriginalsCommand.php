<?php

declare(strict_types=1);

namespace Hexis\SuluMediaCdnBundle\Command;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Copies every FileVersion's binary file from the source flysystem to the
 * target flysystem at the same relative path so storageOptions stays valid
 * across adapters. Idempotent - re-running skips files already on the target.
 *
 * Run BEFORE flipping sulu_media.storage.flysystem_service so originals stay
 * accessible during the migration. After the run completes and you've
 * verified counts, change sulu_media.yaml to point at the target service.
 */
#[AsCommand(
    name: 'hexis:media:migrate-originals',
    description: 'Copy media originals from one flysystem to another (e.g. local -> S3).',
)]
final class MigrateOriginalsCommand extends Command
{
    public function __construct(
        private readonly MediaRepositoryInterface $mediaRepository,
        private readonly FilesystemOperator $sourceFilesystem,
        private readonly FilesystemOperator $targetFilesystem,
        private readonly int $defaultBatchSize,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('media', null, InputOption::VALUE_REQUIRED, 'Comma-separated media IDs (default: all media)')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Pagination size when iterating all media')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be copied without writing')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Re-copy files that already exist on the target');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $overwrite = (bool) $input->getOption('overwrite');
        $batchSize = (int) ($input->getOption('batch-size') ?? $this->defaultBatchSize);

        $mediaIds = $this->resolveMediaIds($input);

        $mode = $dryRun ? 'DRY-RUN' : 'live';
        if (null !== $mediaIds) {
            $io->title(\sprintf('Migrating %d media [%s]', \count($mediaIds), $mode));
            $stats = $this->migrateBatch($mediaIds, $overwrite, $dryRun, $io);
            $this->summary($io, $stats);

            return 0 === $stats['failed'] ? self::SUCCESS : self::FAILURE;
        }

        $io->title(\sprintf('Migrating ALL media in batches of %d [%s]', $batchSize, $mode));

        $offset = 0;
        $totals = ['scanned' => 0, 'copied' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0];
        while (true) {
            $batch = $this->mediaRepository->findBy([], ['id' => 'ASC'], $batchSize, $offset);
            if ([] === $batch) {
                break;
            }
            $batchIds = \array_map(static fn (MediaInterface $m): int => (int) $m->getId(), $batch);
            $batchStats = $this->migrateBatch($batchIds, $overwrite, $dryRun, $io);
            foreach ($batchStats as $key => $count) {
                $totals[$key] += $count;
            }
            $offset += $batchSize;
            $io->writeln(\sprintf(
                ' batch done - scanned=%d copied=%d skipped=%d missing=%d failed=%d',
                $totals['scanned'], $totals['copied'], $totals['skipped'], $totals['missing'], $totals['failed'],
            ));
        }

        $this->summary($io, $totals);

        return 0 === $totals['failed'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<int> $mediaIds
     * @return array{scanned: int, copied: int, skipped: int, missing: int, failed: int}
     */
    private function migrateBatch(array $mediaIds, bool $overwrite, bool $dryRun, SymfonyStyle $io): array
    {
        $stats = ['scanned' => 0, 'copied' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0];

        foreach ($mediaIds as $mediaId) {
            $media = $this->mediaRepository->findMediaById($mediaId);
            if (!$media instanceof MediaInterface) {
                continue;
            }

            foreach ($media->getFiles() as $file) {
                foreach ($file->getFileVersions() as $fileVersion) {
                    ++$stats['scanned'];
                    $outcome = $this->migrateFileVersion($fileVersion, $overwrite, $dryRun, $io);
                    ++$stats[$outcome];
                }
            }
        }

        return $stats;
    }

    /**
     * @return 'copied'|'skipped'|'missing'|'failed'
     */
    private function migrateFileVersion(FileVersion $fileVersion, bool $overwrite, bool $dryRun, SymfonyStyle $io): string
    {
        $path = $this->pathFor($fileVersion);
        if (null === $path) {
            $io->warning(\sprintf('FileVersion %d: storageOptions missing fileName/directory; skipping', $fileVersion->getId()));

            return 'failed';
        }

        try {
            if (!$this->sourceFilesystem->fileExists($path)) {
                if ($io->isVerbose()) {
                    $io->writeln(\sprintf(' <comment>missing on source</comment>: %s', $path));
                }

                return 'missing';
            }

            if (!$overwrite && $this->targetFilesystem->fileExists($path)) {
                if ($io->isVerbose()) {
                    $io->writeln(\sprintf(' <info>skip</info> (already on target): %s', $path));
                }

                return 'skipped';
            }

            if ($dryRun) {
                $io->writeln(\sprintf(' <comment>[dry-run]</comment> would copy %s', $path));

                return 'copied';
            }

            $stream = $this->sourceFilesystem->readStream($path);
            try {
                $this->targetFilesystem->writeStream($path, $stream);
            } finally {
                if (\is_resource($stream)) {
                    @\fclose($stream);
                }
            }

            if ($io->isVerbose()) {
                $io->writeln(\sprintf(' <info>copied</info>: %s', $path));
            }

            return 'copied';
        } catch (FilesystemException $e) {
            $io->error(\sprintf('Failed to migrate %s: %s', $path, $e->getMessage()));

            return 'failed';
        }
    }

    private function pathFor(FileVersion $fileVersion): ?string
    {
        $opts = $fileVersion->getStorageOptions();
        if (!\is_array($opts)) {
            return null;
        }

        // Sulu's FlysystemStorage stores the path under `segment` + `fileName`.
        // Some legacy data may carry `directory` instead.
        $segment = $opts['segment'] ?? $opts['directory'] ?? null;
        $fileName = $opts['fileName'] ?? null;
        if (null === $segment || null === $fileName) {
            return null;
        }

        return $segment . '/' . $fileName;
    }

    /**
     * @return list<int>|null Null = iterate ALL media
     */
    private function resolveMediaIds(InputInterface $input): ?array
    {
        $mediaOption = $input->getOption('media');
        if (null === $mediaOption) {
            return null;
        }

        return \array_values(\array_filter(
            \array_map('intval', \explode(',', (string) $mediaOption)),
            static fn (int $id): bool => $id > 0,
        ));
    }

    /**
     * @param array{scanned: int, copied: int, skipped: int, missing: int, failed: int} $stats
     */
    private function summary(SymfonyStyle $io, array $stats): void
    {
        $message = \sprintf(
            'Scanned %d file versions: %d copied, %d skipped, %d missing on source, %d failed.',
            $stats['scanned'], $stats['copied'], $stats['skipped'], $stats['missing'], $stats['failed'],
        );

        if (0 === $stats['failed']) {
            $io->success($message);
        } else {
            $io->error($message);
        }
    }
}
