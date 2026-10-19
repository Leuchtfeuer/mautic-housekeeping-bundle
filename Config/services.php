<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\HousekeepingLeuchtfeuerIntegration;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Support\ConfigSupport;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Service\EventLogCleanup;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = [
        'Service/EventLogCleanup.php',
    ];

    $services->load('MauticPlugin\\LeuchtfeuerHousekeepingBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->get(HousekeepingLeuchtfeuerIntegration::class)
        ->tag('mautic.integration')
        ->tag('mautic.basic_integration');
    $services->get(ConfigSupport::class)
        ->tag('mautic.config_integration');

    $services->alias('mautic.integration.housekeepingleuchtfeuer', HousekeepingLeuchtfeuerIntegration::class);

    $services->set(EventLogCleanup::class)
        ->autowire(false)
        ->args([
            service('doctrine.dbal.default_connection'),
            '%mautic.db_table_prefix%',
            service(Config::class),
            service('monolog.logger.mautic'),
        ]);
};
