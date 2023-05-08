<?php

declare(strict_types=1);

namespace MauticPlugin\MauticHousekeepingBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Result;
use Generator;
use MauticPlugin\MauticHousekeepingBundle\Service\EventLogCleanup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

class EventLogCleanupTest extends TestCase
{
    /**
     * @dataProvider runProvider
     */
    public function testDeleteEventLogEntries(array $operations, array $queries, array $countRows, string $message, bool $dryRun, ?int $campaignId): void
    {
        $statements = [];
        foreach ($countRows as $countRow) {
            $statement = $this->createMock(Result::class);
            $statement->expects(self::once())
                ->method('rowCount')
                ->willReturn($countRow);

            $statements[] = $statement;
        }

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(count($queries)))
            ->method('executeQuery')
            ->withConsecutive(...$queries)
            ->willReturnOnConsecutiveCalls(...$statements);

        $loggedQueries = [];
        foreach ($queries as $query) {
            $loggedQueries[] = [$query[0]];
        }

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::exactly(count($queries)))
            ->method('isVerbose')
            ->willReturn(true);
        $output->expects(self::exactly(count($queries)))
            ->method('writeln')
            ->withConsecutive(...$loggedQueries);

        $eventLogCleanup = new EventLogCleanup($connection, 'prefix_table_');
        self::assertSame($message, $eventLogCleanup->deleteEventLogEntries(4, $campaignId, $dryRun, $operations, $output));
    }

    public function runProvider(): Generator
    {
        $daysOld = 4;

        // dry run all tables
        yield 0 => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'SELECT * FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT * FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT * FROM prefix_table_email_stats_devices LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = prefix_table_email_stats_devices.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT * FROM prefix_table_email_stats LEFT JOIN emails ON prefix_table_email_stats.email_id = emails.id WHERE is_published = 0 OR prefix_table_email_stats.email_id IS NULL AND date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [3, 14, 55, 57],
            '3 lead_event_log, 14 campaign_lead_event_log, 55 email_stats_devices and 57 email_stats rows would have been deleted. This is a dry run.',
            true,
            null,
        ];

        // dry run email_stats and see email_stats_devices is also cleared
        yield 1 => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'SELECT * FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [31],
            '31 lead_event_log rows would have been deleted. This is a dry run.',
            true,
            null,
        ];

        // dry run single table
        yield 2 => [
            [
                EventLogCleanup::LEAD_EVENTS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'SELECT * FROM prefix_table_email_stats_devices LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = prefix_table_email_stats_devices.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT * FROM prefix_table_email_stats LEFT JOIN emails ON prefix_table_email_stats.email_id = emails.id WHERE is_published = 0 OR prefix_table_email_stats.email_id IS NULL AND date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [42, 67],
            '42 email_stats_devices and 67 email_stats rows would have been deleted. This is a dry run.',
            true,
            null,
        ];

        // dry run all tables with campaignId
        yield 3 => [
            [
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'SELECT * FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT * FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    [':daysOld' => $daysOld, ':cmpId' => 12235],
                    [':daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT * FROM prefix_table_email_stats_devices LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = prefix_table_email_stats_devices.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT * FROM prefix_table_email_stats LEFT JOIN emails ON prefix_table_email_stats.email_id = emails.id WHERE is_published = 0 OR prefix_table_email_stats.email_id IS NULL AND date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [34, 41, 3, 87],
            '34 lead_event_log, 41 campaign_lead_event_log, 3 email_stats_devices and 87 email_stats rows would have been deleted. This is a dry run.',
            true,
            12235,
        ];

        // dry run two tables with campaignId
        yield 4 => [
            [
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'SELECT * FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    [':daysOld' => $daysOld, ':cmpId' => 65487],
                    [':daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT * FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [61, 22],
            '61 campaign_lead_event_log, 22 lead_event_log rows would have been deleted. This is a dry run.',
            true,
            65487,
        ];

        // real run all tables
        yield 5 => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'DELETE prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_campaign_lead_event_log FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_email_stats_devices FROM prefix_table_email_stats_devices LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = prefix_table_email_stats_devices.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_email_stats FROM prefix_table_email_stats LEFT JOIN emails ON prefix_table_email_stats.email_id = emails.id WHERE is_published = 0 OR prefix_table_email_stats.email_id IS NULL AND date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [3, 14, 55, 41],
            '3 lead_event_log, 14 campaign_lead_event_log, 55 email_stats_devices and 41 email_stats rows have been deleted.',
            false,
            null,
        ];

        // real run single table
        yield 6 => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'DELETE prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [42],
            '42 lead_event_log rows have been deleted.',
            false,
            null,
        ];

        // real run email_stats table to see if email_stats_devices is also cleared
        yield 7 => [
            [
                EventLogCleanup::LEAD_EVENTS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'DELETE prefix_table_email_stats_devices FROM prefix_table_email_stats_devices LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = prefix_table_email_stats_devices.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_email_stats FROM prefix_table_email_stats LEFT JOIN emails ON prefix_table_email_stats.email_id = emails.id WHERE is_published = 0 OR prefix_table_email_stats.email_id IS NULL AND date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [42, 12],
            '42 email_stats_devices and 12 email_stats rows have been deleted.',
            false,
            null,
        ];

        // real run all tables with campaignId
        yield 8 => [
            [
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'DELETE prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_campaign_lead_event_log FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    [':daysOld' => $daysOld, ':cmpId' => 12235],
                    [':daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_email_stats_devices FROM prefix_table_email_stats_devices LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = prefix_table_email_stats_devices.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_email_stats FROM prefix_table_email_stats LEFT JOIN emails ON prefix_table_email_stats.email_id = emails.id WHERE is_published = 0 OR prefix_table_email_stats.email_id IS NULL AND date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [34, 41, 3, 6],
            '34 lead_event_log, 41 campaign_lead_event_log, 3 email_stats_devices and 6 email_stats rows have been deleted.',
            false,
            12235,
        ];

        // real run two tables with campaignId
        yield 9 => [
            [
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'DELETE prefix_table_campaign_lead_event_log FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    [':daysOld' => $daysOld, ':cmpId' => 65487],
                    [':daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [61, 22],
            '61 campaign_lead_event_log, 22 lead_event_log rows have been deleted.',
            false,
            65487,
        ];
    }
}
