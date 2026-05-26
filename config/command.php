<?php

declare(strict_types=1);

use Hexis\SuluMediaCdnBundle\Command\RegenerateVariationsCommand;
use Hexis\SuluMediaCdnBundle\Generator\VariationGenerator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // VariationGenerator may already be defined by triggers.php; this is idempotent.
    $services->set(VariationGenerator::class)
        ->args([
            service('sulu_media.image.converter'),
            service('sulu_media.format_cache'),
            service('logger')->nullOnInvalid(),
        ]);

    $services->set(RegenerateVariationsCommand::class)
        ->args([
            service('sulu.repository.media'),
            service(VariationGenerator::class),
            service('sulu_media.format_cache'),
            service('messenger.default_bus'),
            param('sulu_media.image.formats'),
            param('hexis_sulu_media_cdn.command.default_batch_size'),
        ])
        ->tag('console.command');
};
