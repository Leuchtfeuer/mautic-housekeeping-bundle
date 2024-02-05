<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

if (version_compare(MAUTIC_VERSION, '5', '<')) {
    return;
}

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $services->load('MauticPlugin\\LeuchtfeuerHousekeepingBundle\\', '../')
        ->exclude('../{'.implode(',', MauticCoreExtension::DEFAULT_EXCLUDES).'}');

    $services->get(MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\HousekeepingLeuchtfeuerIntegration::class)
        ->tag('mautic.integration')
        ->tag('mautic.basic_integration');
    $services->get(MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Support\ConfigSupport::class)
        ->tag('mautic.config_integration');

    $services->alias('mautic.integration.housekeepingleuchtfeuer', MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\HousekeepingLeuchtfeuerIntegration::class);

    $eventLogCleanup = $services->get(MauticPlugin\LeuchtfeuerHousekeepingBundle\Service\EventLogCleanup::class);
    $eventLogCleanup->arg('$dbPrefix', '%mautic.db_table_prefix%');
};
