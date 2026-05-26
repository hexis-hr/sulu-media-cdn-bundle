<?php

declare(strict_types=1);

use Hexis\SuluMediaCdnBundle\FormatManager\AsyncFormatManager;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('hexis_sulu_media_cdn.format_manager.async', AsyncFormatManager::class)
        ->decorate('sulu_media.format_manager')
        ->args([
            service('.inner'),
            service('hexis_sulu_media_cdn.format_cache.flysystem'),
            service('sulu.repository.media'),
            service('sulu_media.storage'),
            service('messenger.default_bus'),
            param('hexis_sulu_media_cdn.async_proxy.miss_strategy'),
            param('hexis_sulu_media_cdn.async_proxy.regenerate_on_miss'),
            service('logger')->nullOnInvalid(),
        ]);
};
