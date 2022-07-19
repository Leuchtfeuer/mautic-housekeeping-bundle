<?php

return [
    'name'        => 'Mautic Housekeeping Bundle',
    'description' => 'EventLog Cleanup Command',
    'version'     => '1.0',
    'author'      => 'Mautic',
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
