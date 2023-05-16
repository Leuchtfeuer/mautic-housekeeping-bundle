<?php

return [
    'name'        => 'Housekeeping by Leuchtfeuer',
    'description' => 'Database Cleanup Command to delete lead_event_log table entries, campaign_lead_event_log table entries, email_stats table entries where the referenced email entry is currently not published and email_stats_devices table entries.',
    'version'     => '1.4.3',
    'author'      => 'Leuchtfeuer Digital Marketing GmbH',
    'services'    => [
        'integrations' => [
            'mautic.integration.leuchtfeuerhousekeeping' => [
                'class' => \MauticPlugin\MauticHousekeepingBundle\Integration\LeuchtfeuerHousekeepingIntegration::class,
                'tags'  => [
                    'mautic.integration',
                    'mautic.basic_integration',
                ],
            ],
            'leuchtfeuerhousekeeping.integration.configuration' => [
                 'class' => \MauticPlugin\MauticHousekeepingBundle\Integration\Support\ConfigSupport::class,
                'tags'  => [
                    'mautic.config_integration',
                ],
            ],
        ],
        'command' => [
            'mautic.leuchtfeuer.command.housekeeping' => [
                'class'     => \MauticPlugin\MauticHousekeepingBundle\Command\EventLogCleanupCommand::class,
                'tag'       => 'console.command',
                'arguments' => [
                    'mautic.leuchtfeuer.service.event_log_cleanup',
                ],
            ],
        ],
        'models'       => [],
        'forms'        => [],
        'helpers'      => [],
        'other'  => [
            'mautic.leuchtfeuer.service.event_log_cleanup' => [
                'class'     => \MauticPlugin\MauticHousekeepingBundle\Service\EventLogCleanup::class,
                'arguments' => ['database_connection',
                                '%mautic.db_table_prefix%',
                                'mautic.leuchtfeuerhousekeeping.config'],
            ],
            'mautic.leuchtfeuerhousekeeping.config' => [
                'class'     => \MauticPlugin\MauticHousekeepingBundle\Integration\Config::class,
                'arguments' => [
                    'mautic.integrations.helper',
                ],
            ],
        ],
        'parameters' => [],
    ],
];
