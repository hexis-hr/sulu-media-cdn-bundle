<?php

declare(strict_types=1);

namespace Hexis\SuluMediaCdnBundle;

use Hexis\SuluMediaCdnBundle\FormatCache\FlysystemFormatCache;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class HexisSuluMediaCdnBundle extends AbstractBundle
{
    protected string $extensionAlias = 'hexis_sulu_media_cdn';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('format_cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('flysystem_service')->defaultValue('default.storage')->end()
                        ->scalarNode('path_prefix')->defaultValue('media-formats')->end()
                        ->integerNode('segments')->defaultValue(10)->end()
                        ->enumNode('visibility')
                            ->values([null, 'public', 'private'])
                            ->defaultNull()
                            ->info('Flysystem visibility applied on save(). `public` sets ACL=public-read on S3 (only on buckets with ACLs enabled; fails on BucketOwnerEnforced buckets - use a bucket policy instead). `private` keeps the bucket default. null = no visibility config passed.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('cdn')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('base_url')->defaultValue('')->end()
                        ->booleanNode('version_query')->defaultTrue()->end()
                        ->scalarNode('object_key_prefix')
                            ->defaultValue('')
                            ->info('Prefix prepended on the storage backend. Set to the flysystem `prefix` option (e.g. "media") when the CDN points directly at the bucket so the URL matches the S3 object key. Leave empty when the CDN goes through the Symfony origin or rewrites paths.')
                        ->end()
                        ->arrayNode('presign')
                            ->addDefaultsIfNotSet()
                            ->info('Generate short-lived presigned URLs via FilesystemOperator::temporaryUrl(). Use when the bucket is private and not fronted by an authenticated CDN.')
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->integerNode('ttl')->defaultValue(3600)->min(60)->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('async_proxy')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->enumNode('miss_strategy')
                            ->values(['serve_original', 'on_the_fly', '404'])
                            ->defaultValue('serve_original')
                        ->end()
                        ->booleanNode('regenerate_on_miss')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('triggers')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('on_media_created')->defaultTrue()->end()
                        ->booleanNode('on_media_version_added')->defaultTrue()->end()
                        ->scalarNode('sync_admin_format')->defaultValue('sulu-240x')->end()
                        ->arrayNode('async_formats')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Whitelist of formats to generate async. Empty = all configured formats except sync_admin_format.')
                        ->end()
                        ->arrayNode('excluded_formats')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('queue')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('transport')->defaultValue('image_variations')->end()
                        ->booleanNode('deduplicate')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('command')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('default_batch_size')->defaultValue(100)->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('hexis_sulu_media_cdn.format_cache.enabled', $config['format_cache']['enabled']);
        $builder->setParameter('hexis_sulu_media_cdn.format_cache.flysystem_service', $config['format_cache']['flysystem_service']);
        $builder->setParameter('hexis_sulu_media_cdn.format_cache.path_prefix', $config['format_cache']['path_prefix']);
        $builder->setParameter('hexis_sulu_media_cdn.format_cache.segments', $config['format_cache']['segments']);
        $builder->setParameter('hexis_sulu_media_cdn.format_cache.visibility', $config['format_cache']['visibility']);

        $builder->setParameter('hexis_sulu_media_cdn.cdn.enabled', $config['cdn']['enabled']);
        $builder->setParameter('hexis_sulu_media_cdn.cdn.base_url', $config['cdn']['base_url']);
        $builder->setParameter('hexis_sulu_media_cdn.cdn.version_query', $config['cdn']['version_query']);
        $builder->setParameter('hexis_sulu_media_cdn.cdn.object_key_prefix', $config['cdn']['object_key_prefix']);
        $builder->setParameter('hexis_sulu_media_cdn.cdn.presign.enabled', $config['cdn']['presign']['enabled']);
        $builder->setParameter('hexis_sulu_media_cdn.cdn.presign.ttl', $config['cdn']['presign']['ttl']);

        $builder->setParameter('hexis_sulu_media_cdn.async_proxy.enabled', $config['async_proxy']['enabled']);
        $builder->setParameter('hexis_sulu_media_cdn.async_proxy.miss_strategy', $config['async_proxy']['miss_strategy']);
        $builder->setParameter('hexis_sulu_media_cdn.async_proxy.regenerate_on_miss', $config['async_proxy']['regenerate_on_miss']);

        $builder->setParameter('hexis_sulu_media_cdn.triggers.on_media_created', $config['triggers']['on_media_created']);
        $builder->setParameter('hexis_sulu_media_cdn.triggers.on_media_version_added', $config['triggers']['on_media_version_added']);
        $builder->setParameter('hexis_sulu_media_cdn.triggers.sync_admin_format', $config['triggers']['sync_admin_format']);
        $builder->setParameter('hexis_sulu_media_cdn.triggers.async_formats', $config['triggers']['async_formats']);
        $builder->setParameter('hexis_sulu_media_cdn.triggers.excluded_formats', $config['triggers']['excluded_formats']);

        $builder->setParameter('hexis_sulu_media_cdn.queue.transport', $config['queue']['transport']);
        $builder->setParameter('hexis_sulu_media_cdn.queue.deduplicate', $config['queue']['deduplicate']);

        $builder->setParameter('hexis_sulu_media_cdn.command.enabled', $config['command']['enabled']);
        $builder->setParameter('hexis_sulu_media_cdn.command.default_batch_size', $config['command']['default_batch_size']);

        if ($config['format_cache']['enabled']) {
            $this->registerFormatCache($config, $builder);
        }

        if ($config['async_proxy']['enabled']) {
            $container->import('../config/async_proxy.php');
        }

        if ($config['triggers']['on_media_created'] || $config['triggers']['on_media_version_added']) {
            $container->import('../config/triggers.php');
        }

        if ($config['command']['enabled']) {
            $container->import('../config/command.php');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerFormatCache(array $config, ContainerBuilder $builder): void
    {
        $args = [
            new Reference($config['format_cache']['flysystem_service']),
            $config['format_cache']['path_prefix'],
            $config['cdn']['base_url'],
            '%sulu_media.format_cache.media_proxy_path%',
            (int) $config['format_cache']['segments'],
            (bool) $config['cdn']['enabled'],
            (bool) $config['cdn']['version_query'],
            $config['cdn']['object_key_prefix'],
            (bool) $config['cdn']['presign']['enabled'],
            (int) $config['cdn']['presign']['ttl'],
            $config['format_cache']['visibility'],
            new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE),
        ];

        $definition = new Definition(FlysystemFormatCache::class, $args);
        $definition->setPublic(true);

        // Public-facing flysystem-aware service, also reused by AsyncFormatManager
        // (Phase 3) for direct existence checks and stream reads.
        $builder->setDefinition('hexis_sulu_media_cdn.format_cache.flysystem', $definition);

        // Replace Sulu's default LocalFormatCache: FormatManager wires this id.
        $sulu = new Definition(FlysystemFormatCache::class, $args);
        $sulu->setPublic(true);
        $sulu->addTag('sulu_media.format_cache', ['alias' => 'flysystem']);
        $builder->setDefinition('sulu_media.format_cache', $sulu);
    }
}
