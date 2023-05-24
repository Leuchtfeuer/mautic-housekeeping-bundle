<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Support;

use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\HousekeepingLeuchtfeuerIntegration;

class ConfigSupport extends HousekeepingLeuchtfeuerIntegration implements ConfigFormInterface
{
    use DefaultConfigFormTrait;
}
