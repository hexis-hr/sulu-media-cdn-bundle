<?php

declare(strict_types=1);

namespace Hexis\SuluMediaCdnBundle\Tests\Unit\FormatCache;

use Hexis\SuluMediaCdnBundle\FormatCache\FlysystemFormatCache;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Media\Exception\ImageProxyInvalidUrl;

final class FlysystemFormatCacheTest extends TestCase
{
    public function testSaveWritesToFlysystemAtSegmentedPath(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())
            ->method('write')
            ->with('media-formats/sulu-100x100/05/15-photo.jpg', 'PNG-bytes', []);

        $cache = $this->makeCache($filesystem, cdnEnabled: false);
        self::assertTrue($cache->save('PNG-bytes', 15, 'photo.jpg', 'sulu-100x100'));
    }

    public function testSavePassesVisibilityConfigWhenSet(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())
            ->method('write')
            ->with('media-formats/sulu-100x100/05/15-photo.jpg', 'PNG-bytes', ['visibility' => 'public']);

        $cache = $this->makeCache($filesystem, cdnEnabled: false, visibility: 'public');
        self::assertTrue($cache->save('PNG-bytes', 15, 'photo.jpg', 'sulu-100x100'));
    }

    public function testGetMediaUrlReturnsRelativeWhenCdnDisabled(): void
    {
        $cache = $this->makeCache($this->createMock(FilesystemOperator::class), cdnEnabled: false);
        $url = $cache->getMediaUrl(42, 'photo.jpg', 'sulu-100x100', 3, 7);

        self::assertSame('/uploads/media/sulu-100x100/02/42-photo.jpg?v=3-7', $url);
    }

    public function testGetMediaUrlPrefixesCdnBaseUrlWithStoragePath(): void
    {
        // When CDN base_url is set, the URL must match the storage backend's
        // object key (pathPrefix + slug), not Sulu's proxy path.
        $cache = $this->makeCache(
            $this->createMock(FilesystemOperator::class),
            cdnBaseUrl: 'https://cdn.example.com/',
            cdnEnabled: true,
        );
        $url = $cache->getMediaUrl(42, 'photo.jpg', 'sulu-100x100', 3, 7);

        self::assertSame('https://cdn.example.com/media-formats/sulu-100x100/02/42-photo.jpg?v=3-7', $url);
    }

    public function testGetMediaUrlIncludesObjectKeyPrefixWhenProvided(): void
    {
        // Mirrors a flysystem `prefix` option (e.g. aws.storage prefix=media)
        // so the CDN URL aligns with the actual S3 key.
        $cache = $this->makeCache(
            $this->createMock(FilesystemOperator::class),
            cdnBaseUrl: 'https://cdn.example.com',
            cdnEnabled: true,
            objectKeyPrefix: 'media',
        );
        $url = $cache->getMediaUrl(42, 'photo.jpg', 'sulu-100x100', 3, 7);

        self::assertSame('https://cdn.example.com/media/media-formats/sulu-100x100/02/42-photo.jpg?v=3-7', $url);
    }

    public function testGetMediaUrlOmitsVersionQueryWhenDisabled(): void
    {
        $cache = $this->makeCache(
            $this->createMock(FilesystemOperator::class),
            cdnEnabled: false,
            versionQuery: false,
        );
        $url = $cache->getMediaUrl(42, 'photo.jpg', 'sulu-100x100', 3, 7);

        self::assertSame('/uploads/media/sulu-100x100/02/42-photo.jpg', $url);
    }

    public function testGetMediaUrlUrlEncodesFileName(): void
    {
        $cache = $this->makeCache($this->createMock(FilesystemOperator::class), cdnEnabled: false);
        $url = $cache->getMediaUrl(1, 'my photo & friends.jpg', 'sulu-100x100', 1, 0);

        self::assertStringContainsString('1-my%20photo%20%26%20friends.jpg', $url);
    }

    public function testAnalyzedMediaUrlParsesIdFormatAndFilename(): void
    {
        $cache = $this->makeCache($this->createMock(FilesystemOperator::class), cdnEnabled: false);
        $result = $cache->analyzedMediaUrl('/uploads/media/sulu-100x100/05/15-photo.jpg');

        self::assertSame(['id' => 15, 'format' => 'sulu-100x100', 'fileName' => 'photo.jpg'], $result);
    }

    public function testAnalyzedMediaUrlThrowsOnMalformedId(): void
    {
        $cache = $this->makeCache($this->createMock(FilesystemOperator::class), cdnEnabled: false);

        $this->expectException(ImageProxyInvalidUrl::class);
        $cache->analyzedMediaUrl('/uploads/media/sulu-100x100/05/abc-photo.jpg');
    }

    public function testExistsDelegatesToFlysystem(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())
            ->method('fileExists')
            ->with('media-formats/sulu-100x100/05/15-photo.jpg')
            ->willReturn(true);

        $cache = $this->makeCache($filesystem, cdnEnabled: false);
        self::assertTrue($cache->exists(15, 'photo.jpg', 'sulu-100x100'));
    }

    public function testSegmentingMatchesSulusFormat(): void
    {
        // segments = 10 → segment width 2, id 15 % 10 = 5 → "05"
        // segments = 100 → segment width 3, id 15 % 100 = 15 → "015"
        $tenSegments = $this->makeCache($this->createMock(FilesystemOperator::class), cdnEnabled: false, segments: 10);
        self::assertSame('/uploads/media/sulu-100x100/05/15-photo.jpg?v=1-0', $tenSegments->getMediaUrl(15, 'photo.jpg', 'sulu-100x100', 1, 0));

        $hundredSegments = $this->makeCache($this->createMock(FilesystemOperator::class), cdnEnabled: false, segments: 100);
        self::assertSame('/uploads/media/sulu-100x100/015/15-photo.jpg?v=1-0', $hundredSegments->getMediaUrl(15, 'photo.jpg', 'sulu-100x100', 1, 0));
    }

    private function makeCache(
        FilesystemOperator $filesystem,
        string $cdnBaseUrl = '',
        bool $cdnEnabled = true,
        bool $versionQuery = true,
        int $segments = 10,
        string $objectKeyPrefix = '',
        bool $presignEnabled = false,
        int $presignTtl = 3600,
        ?string $visibility = null,
    ): FlysystemFormatCache {
        return new FlysystemFormatCache(
            $filesystem,
            'media-formats',
            $cdnBaseUrl,
            '/uploads/media/{slug}',
            $segments,
            $cdnEnabled,
            $versionQuery,
            $objectKeyPrefix,
            $presignEnabled,
            $presignTtl,
            $visibility,
        );
    }
}
