<?php

declare(strict_types=1);

namespace Hexis\SuluMediaCdnBundle\Message;

/**
 * Async request to mirror one media file version's original from the source
 * flysystem (e.g. local) to the target flysystem (e.g. S3). Consumed by
 * MigrateOriginalHandler.
 */
final readonly class MigrateOriginalMessage
{
    /**
     * @param int $mediaId      Sulu media primary key
     * @param int $fileVersion  Version number (FileVersion::getVersion()), NOT the PK
     */
    public function __construct(
        public int $mediaId,
        public int $fileVersion,
    ) {
    }
}
