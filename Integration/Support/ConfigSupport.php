<?php

declare(strict_types=1);

namespace MauticPlugin\MauticHousekeepingBundle\Integration\Support;

use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use MauticPlugin\MauticHousekeepingBundle\Integration\LeuchtfeuerHousekeepingIntegration;

class ConfigSupport extends LeuchtfeuerHousekeepingIntegration implements ConfigFormInterface
{
    use DefaultConfigFormTrait;
}
