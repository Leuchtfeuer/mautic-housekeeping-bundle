<?php

$configValue = [
    'name'        => 'Housekeeping by Leuchtfeuer',
    'description' => 'Database Cleanup Command to delete lead_event_log table entries, campaign_lead_event_log table entries, email_stats table entries where the referenced email entry is currently not published and email_stats_devices table entries.',
    'version'     => '4.0.1',
    'author'      => 'Leuchtfeuer Digital Marketing GmbH',
];

if (version_compare(MAUTIC_VERSION, '5', '>=')) {
    return $configValue;
}

$configValue['services'] = [
    'integrations' => [
        'mautic.integration.housekeepingleuchtfeuer' => [
            'class' => MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\HousekeepingLeuchtfeuerIntegration::class,
            'tags'  => [
                'mautic.integration',
                'mautic.basic_integration',
            ],
        ],
        'housekeepingleuchtfeuer.integration.configuration' => [
            'class'  => MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Support\ConfigSupport::class,
            'tags'   => [
                'mautic.config_integration',
            ],
        ],
    ],
    'command' => [
        'mautic.leuchtfeuer.command.housekeeping' => [
            'class'     => MauticPlugin\LeuchtfeuerHousekeepingBundle\Command\EventLogCleanupCommand::class,
            'tag'       => 'console.command',
            'arguments' => [
                'mautic.leuchtfeuer.service.event_log_cleanup',
            ],
        ],
    ],
    'models'  => [],
    'forms'   => [],
    'helpers' => [],
    'other'   => [
        'mautic.leuchtfeuer.service.event_log_cleanup' => [
            'class'     => MauticPlugin\LeuchtfeuerHousekeepingBundle\Service\EventLogCleanup::class,
            'arguments' => [
                'database_connection',
                '%mautic.db_table_prefix%',
                'mautic.housekeepingleuchtfeuer.config',
            ],
        ],
        'mautic.housekeepingleuchtfeuer.config' => [
            'class'     => MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Config::class,
            'arguments' => [
                'mautic.integrations.helper',
            ],
        ],
    ],
    'parameters' => [],
];

return $configValue;
