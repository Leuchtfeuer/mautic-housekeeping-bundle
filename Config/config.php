<?php

return [
    'name'        => 'Housekeeping by Leuchtfeuer',
    'description' => 'Database Cleanup Command to delete lead_event_log table entries, campaign_lead_event_log table entries, email_stats table entries where the referenced email entry is currently not published and email_stats_devices table entries.',
    'version'     => '1.4.0',
    'author'      => 'Leuchtfeuer Digital Marketing GmbH',
    'services'    => [
        'command' => [
            'mautic.leuchtfeuer.command.housekeeping' => [
                'class'     => \MauticPlugin\MauticHousekeepingBundle\Command\EventLogCleanupCommand::class,
                'tag'       => 'console.command',
                'arguments' => [
                    'mautic.leuchtfeuer.service.event_log_cleanup',
                ],
            ],
        ],
        'other'  => [
            'mautic.leuchtfeuer.service.event_log_cleanup' => [
                'class'     => \MauticPlugin\MauticHousekeepingBundle\Service\EventLogCleanup::class,
                'arguments' => ['database_connection', '%mautic.db_table_prefix%'],
            ],
        ],
    ],
];
