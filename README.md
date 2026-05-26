# hexis/sulu-media-cdn-bundle

[![Latest Version](https://img.shields.io/packagist/v/hexis/sulu-media-cdn-bundle.svg)](https://packagist.org/packages/hexis/sulu-media-cdn-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Source: <https://github.com/hexis-hr/sulu-media-cdn-bundle>

Pluggable Sulu media bundle that replaces Sulu's on-the-fly image variation generation with:

- async pre-generation via Symfony Messenger
- flysystem-backed variation storage (S3, local, anything `league/flysystem` adapts)
- CDN delivery with optional SigV4 presigned URLs (private buckets supported)
- self-healing fallback that streams the original on cache miss
- bulk (re)generate console command

Every behavior is independently toggleable. With all flags off, Sulu's native behavior is preserved.

## Why

Sulu's `FormatManager::returnImage()` decompresses originals into PHP-FPM memory on every cold-cache request to generate variations. Under load (e.g. an admin grid with 100+ uncached thumbnails) this OOM-kills the pod ([sulu/sulu#8735](https://github.com/sulu/sulu/issues/8735)). Variations also stay on the pod's local disk, so S3/CDN scale gains never reach the image hot path.

This bundle moves all conversion work to a Messenger worker, writes variations to an external storage backend, and serves them via CDN URLs — making the request hot path strictly a lookup-and-redirect.

## Requirements

- PHP 8.2+
- Symfony 7.3+
- Sulu 3.0+
- `league/flysystem` 3.10+ (for `FilesystemOperator::temporaryUrl()`)
- For S3 storage: `aws/aws-sdk-php` 3.x + `league/flysystem-aws-s3-v3` 3.10+ (already declared as direct dependencies)

## Installation

```bash
composer require hexis/sulu-media-cdn-bundle
```

Register the bundle:

```php
// config/bundles.php
return [
    // ...
    Hexis\SuluMediaCdnBundle\HexisSuluMediaCdnBundle::class => ['all' => true],
];
```

Add a Messenger transport for the async generation queue:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            image_variations:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%?queue_name=image_variations'
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2
                options:
                    auto_setup: false
                    table_name: messenger_image_variations

        routing:
            Hexis\SuluMediaCdnBundle\Message\GenerateVariationsMessage: image_variations
```

If you use the Doctrine messenger transport, create the queue table via a migration (sample for MySQL / MariaDB):

```sql
CREATE TABLE messenger_image_variations (
    id BIGINT AUTO_INCREMENT NOT NULL,
    body LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`,
    headers LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`,
    queue_name VARCHAR(190) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX IDX_MSG_IMG_VAR_QUEUE (queue_name, available_at, delivered_at, id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
```

Start the worker:

```bash
bin/console messenger:consume image_variations --memory-limit=128M
```

## Configuration

Defaults shown. The bundle works with `hexis_sulu_media_cdn: ~` (all defaults).

```yaml
hexis_sulu_media_cdn:
    # Where variations are stored and served from.
    format_cache:
        enabled: true                  # false = use Sulu's native LocalFormatCache untouched
        flysystem_service: 'default.storage'   # any flysystem.yaml storage id
        path_prefix: 'media-formats'   # path under the flysystem root
        segments: 10                   # bucketing - matches Sulu's segmenting
        visibility: null               # null | 'public' | 'private'
                                       # 'public' sets ACL=public-read on writes (only on
                                       # S3 buckets with ACLs enabled). For BucketOwnerEnforced
                                       # buckets, leave null and use a bucket policy instead.

    # How variation URLs are shaped.
    cdn:
        enabled: true                  # false = always return native proxy URLs
        base_url: ''                   # e.g. https://cdn.example.com - empty = relative proxy paths
        version_query: true            # append ?v={version}-{subVersion} cache-buster
        object_key_prefix: ''          # mirrors flysystem `prefix` so the URL matches the
                                       # actual object key when base_url points at the bucket
                                       # directly (e.g. 'media' if your S3 flysystem prefix is 'media')
        presign:
            enabled: false             # generate SigV4-signed S3 URLs
            ttl: 3600                  # signature validity in seconds (min 60)

    # What happens when a variation URL hits Symfony's origin.
    async_proxy:
        enabled: true                  # false = leave Sulu's FormatManager untouched
        miss_strategy: 'serve_original'   # 'serve_original' | 'on_the_fly' | '404'
        regenerate_on_miss: true       # dispatch a self-healing GenerateVariationsMessage

    # Auto-trigger generation on upload + new file versions.
    triggers:
        on_media_created: true
        on_media_version_added: true
        sync_admin_format: 'sulu-240x' # generated inside the upload request; null disables
        async_formats: []              # empty = all configured formats minus sync_admin_format
        excluded_formats: []           # blacklist applied to async_formats

    queue:
        transport: 'image_variations'  # Messenger transport name (must exist)
        deduplicate: true

    command:
        enabled: true
        default_batch_size: 100
```

Each `enabled` flag gates **service registration** in the container, not a runtime no-op. Disabling a feature genuinely removes the wiring.

## Architecture

```
Upload  ─►  MediaCreatedEvent / MediaVersionAddedEvent
              ├─ sync: generate sync_admin_format  ──►  flysystem (S3)
              └─ async: dispatch GenerateVariationsMessage
                          │
                          ▼
                   Messenger worker
                          │
                          ├─ for each configured format:
                          │     ImagineImageConverter::convert()
                          │     FormatCacheInterface::save()  ──►  flysystem (S3)

Render <img src="…">
         │
         ▼
FormatCacheInterface::getMediaUrl()
   ├─ presign enabled  ──►  Sulu proxy URL (signing deferred to request time)
   ├─ CDN enabled       ──►  {cdn.base_url}/{object_key_prefix}/{path_prefix}/...
   └─ otherwise          ──►  native Sulu proxy URL

CDN/origin GET
         │
         ▼
AsyncFormatManager::returnImage()  (decorates Sulu's FormatManager)
   ├─ variation exists?
   │     ├─ presign on    ──►  302 to freshly signed S3 URL
   │     ├─ absolute URL  ──►  302 to CDN URL
   │     └─ relative URL  ──►  stream variation from flysystem
   └─ miss?
         ├─ miss_strategy = serve_original  ──►  stream original + queue regen
         ├─ miss_strategy = on_the_fly      ──►  delegate to Sulu's FormatManager
         └─ miss_strategy = 404             ──►  return 404
```

## Common configurations

### Async generation + S3 + public CDN

```yaml
hexis_sulu_media_cdn:
    format_cache:
        flysystem_service: 'aws.storage'
    cdn:
        base_url: 'https://cdn.example.com'
        object_key_prefix: 'media'   # match the flysystem `prefix` option
```

Bucket policy makes `media-formats/*` publicly readable; HTML contains absolute CDN URLs; cache misses fall back to the Symfony origin.

### Async generation + S3 + private bucket via presigned URLs

```yaml
hexis_sulu_media_cdn:
    format_cache:
        flysystem_service: 'aws.storage'
    cdn:
        presign:
            enabled: true
            ttl: 3600
```

HTML contains Sulu proxy URLs; the origin presigns the redirect target at request time. Bucket stays private.

### Async generation only, no CDN

```yaml
hexis_sulu_media_cdn:
    cdn:
        enabled: false
```

Variations stored remotely but served via the Symfony origin (streamed from flysystem). Useful for headless API setups.

### Emergency kill-switch

```yaml
hexis_sulu_media_cdn:
    async_proxy:
        miss_strategy: 'on_the_fly'
```

Restores Sulu's native conversion path without touching code. Reapply `serve_original` once the issue is resolved.

### Local dev (originals on local disk, no CDN)

Just don't override defaults — `format_cache.flysystem_service: default.storage` writes variations to the same place Sulu writes originals.

## Console commands

### `hexis:media:regenerate-variations`

```bash
# regenerate one media synchronously
bin/console hexis:media:regenerate-variations --media=42 --sync

# regenerate one format for a collection
bin/console hexis:media:regenerate-variations --collection=7 --format=sulu-240x

# repeatable --format for a whitelist
bin/console hexis:media:regenerate-variations --format=sulu-100x100 --format=sulu-240x

# full repopulate (async; consume the queue afterwards)
bin/console hexis:media:regenerate-variations --purge-first
bin/console messenger:consume image_variations --memory-limit=128M

# scope batch size for the "all media" case
bin/console hexis:media:regenerate-variations --batch-size=50
```

Flags:
- `--media=<id,id,...>` — comma-separated media IDs
- `--collection=<id>` — scope to a Sulu collection
- `--format=<key>` — repeatable; format keys whitelist (default: all configured formats)
- `--sync` — run in-process instead of dispatching to Messenger
- `--purge-first` — delete existing variations before regenerating
- `--batch-size=<n>` — pagination size for the "all media" case (default `command.default_batch_size`)

## Sulu integration points

The bundle does not reimplement anything Sulu already provides:

| Concern | Sulu/Symfony class reused |
|---|---|
| Image conversion | `Sulu\Bundle\MediaBundle\Media\ImageConverter\ImageConverterInterface` |
| Format catalog | `%sulu_media.image.formats%` parameter (loaded from `image-formats.xml`) |
| Original file stream | `Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface` |
| Media + FileVersion lookup | `Sulu\Bundle\MediaBundle\Entity\MediaRepositoryInterface` |
| Upload trigger events | `MediaCreatedEvent`, `MediaVersionAddedEvent` (dispatched on the Symfony EventDispatcher by Sulu's ActivityBundle) |
| Format cache contract | `Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface` (implemented + aliased) |
| Format manager contract | `Sulu\Bundle\MediaBundle\Media\FormatManager\FormatManagerInterface` (decorated) |
| Image proxy route | `sulu_media.website.image.proxy` → `MediaStreamController::getImageAction` (untouched; consumes the decorated FormatManager) |

Media deletion is also handled transparently: Sulu's `MediaManager::delete()` already calls `FormatManagerInterface::purge()` per file version, which flows through `FlysystemFormatCache::purge()` and removes the variations from the storage backend. No additional subscriber is needed.

## Gotchas

### S3 bucket with `BucketOwnerEnforced` ownership + `visibility: 'public'`

Modern S3 buckets (default since April 2023) disable ACLs entirely. Setting `format_cache.visibility: 'public'` will cause uploads to fail with:

```
AccessControlListNotSupported: The bucket does not allow ACLs
```

Either switch the bucket's Object Ownership to "Bucket owner preferred", or — recommended — leave `visibility: null` and grant public read via a bucket policy on the variation prefix.

### Sulu admin appends `&inline=1` to thumbnail URLs

Sulu's `MediaFormats.js` and the `sulu_get_media_url` Twig helper blindly append `&inline=1` (or `?inline=1`) to the URL returned by `getMediaUrl()`. This is incompatible with SigV4 presigned URLs because AWS signatures cover **all** query parameters except `X-Amz-Signature`.

This bundle handles it transparently: when `cdn.presign.enabled = true`, `getMediaUrl()` returns the Sulu proxy URL (not the signed URL). Sulu's append is harmless because Symfony's origin ignores the `inline` param. The 302 target is signed at request time, after any third-party mangling. No action needed from you.

### CDN URL paths must match the actual S3 object key

When `cdn.base_url` points directly at an S3 bucket / access point (no path-rewriting CDN), the URL must align with the object key. Set `cdn.object_key_prefix` to match the flysystem adapter's `prefix` option (e.g. `'media'` when `aws.storage` has `prefix: 'media'`). If you put CloudFront in front with a path rewrite rule, leave it empty.

### Restoring from `SuluTrashBundle`

If the host project uses `SuluTrashBundle`, restoring a deleted media brings back the *original* file but not the variations (they were purged on delete). The first request to each variation URL will trigger the `serve_original` fallback + a self-healing regen — recoverable with one round of regeneration, no manual intervention needed.

### Presigning + CDN edge caching

Each call to `getMediaUrl()` (when presigning is on) returns a URL with a fresh `X-Amz-Date`. If you put a public CDN in front, every page render produces a new URL — the CDN treats each as a separate cache key. For presign + CDN, prefer CloudFront with OAC over public CDN + presigned URLs.

## Testing

Unit tests live in `tests/Unit/`. Run via PHPUnit:

```bash
vendor/bin/phpunit packages/hexis-sulu-media-cdn-bundle/tests
```

End-to-end testing requires a running Sulu stack + an S3-compatible flysystem backend. Verify:

1. Service wiring: `bin/console debug:container sulu_media.format_cache` reports `FlysystemFormatCache`; `sulu_media.format_manager` is decorated by `AsyncFormatManager`.
2. Upload a media; confirm `sync_admin_format` exists on the storage backend immediately, and a message lands on the `image_variations` transport.
3. Consume the queue; confirm every configured format is generated and persisted.
4. Delete one variation from the backend; request its URL; expect HTTP 200 with the original bytes streamed and a new regeneration message queued.
5. With `cdn.presign.enabled = true`: confirm `getMediaUrl()` returns the proxy URL, and that the proxy 302s to a freshly-signed URL that fetches HTTP 200 from S3.

## Contributing

Issues and pull requests welcome at <https://github.com/hexis-hr/sulu-media-cdn-bundle>.

## License

MIT - see [LICENSE](LICENSE).
