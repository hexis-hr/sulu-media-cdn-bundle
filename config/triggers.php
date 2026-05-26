<?php

declare(strict_types=1);

use Hexis\SuluMediaCdnBundle\EventSubscriber\MediaUploadSubscriber;
use Hexis\SuluMediaCdnBundle\Generator\VariationGenerator;
use Hexis\SuluMediaCdnBundle\MessageHandler\GenerateVariationsHandler;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(VariationGenerator::class)
        ->args([
            service('sulu_media.image.converter'),
            service('sulu_media.format_cache'),
            service('logger')->nullOnInvalid(),
        ]);

    $services->set(GenerateVariationsHandler::class)
        ->args([
            service('sulu.repository.media'),
            service(VariationGenerator::class),
            param('sulu_media.image.formats'),
            param('hexis_sulu_media_cdn.triggers.sync_admin_format'),
            param('hexis_sulu_media_cdn.triggers.excluded_formats'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('messenger.message_handler');

    $services->set(MediaUploadSubscriber::class)
        ->args([
            service(VariationGenerator::class),
            service('messenger.default_bus'),
            param('hexis_sulu_media_cdn.triggers.on_media_created'),
            param('hexis_sulu_media_cdn.triggers.on_media_version_added'),
            param('hexis_sulu_media_cdn.triggers.sync_admin_format'),
            param('hexis_sulu_media_cdn.triggers.async_formats'),
            param('hexis_sulu_media_cdn.triggers.excluded_formats'),
            param('hexis_sulu_media_cdn.triggers.async_originals_to_target'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('kernel.event_subscriber');
};
