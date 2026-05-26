<?php

declare(strict_types=1);

namespace Hexis\SuluMediaCdnBundle\Message;

/**
 * Async request to (re)generate one or more image variations for a single
 * media file version. Consumed by GenerateVariationsHandler.
 */
final readonly class GenerateVariationsMessage
{
    /**
     * @param int                $mediaId      Sulu media primary key
     * @param int                $fileVersion  Version number (FileVersion::getVersion()), NOT the PK
     * @param list<string>|null  $formatKeys   null = all configured formats; otherwise the whitelist
     */
    public function __construct(
        public int $mediaId,
        public int $fileVersion,
        public ?array $formatKeys = null,
    ) {
    }
}
