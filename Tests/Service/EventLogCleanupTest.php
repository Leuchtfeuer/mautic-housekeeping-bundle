<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Result;
use Generator;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Service\EventLogCleanup;
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
                ->method($dryRun ? 'fetchOne' : 'rowCount')
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

        $config = $this->createMock(Config::class);
        $config->method('isPublished')
            ->willReturn(true);

        $eventLogCleanup = new EventLogCleanup($connection, 'prefix_table_', $config);
        self::assertSame($message, $eventLogCleanup->deleteEventLogEntries(4, $campaignId, $dryRun, $operations, $output));
    }

    public function runProvider(): Generator
    {
        $daysOld = 4;

        yield 'dry run all tables' => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => true,
            ],
            [
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats  WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [3, 14, 23, 55, 57],
            '3 lead_event_log, 14 campaign_lead_event_log, 55 email_stats_devices and 57 email_stats rows would have been deleted. 23 email_stats_tokens will be set to NULL. This is a dry run.',
            true,
            null,
        ];

        yield 'dry run email_stats and see email_stats_devices is also cleared' => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [31],
            '31 lead_event_log rows would have been deleted. This is a dry run.',
            true,
            null,
        ];

        yield 'dry run single table' => [
            [
                EventLogCleanup::LEAD_EVENTS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [42, 67],
            '42 email_stats_devices and 67 email_stats rows would have been deleted. This is a dry run.',
            true,
            null,
        ];

        yield 'dry run all tables with campaignId' => [
            [
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => true,
            ],
            [
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, ':cmpId' => 12235],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats  WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [34, 41, 11, 3, 87],
            '34 lead_event_log, 41 campaign_lead_event_log, 3 email_stats_devices and 87 email_stats rows would have been deleted. 11 email_stats_tokens will be set to NULL. This is a dry run.',
            true,
            12235,
        ];

        yield 'dry run two tables with campaignId' => [
            [
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, ':cmpId' => 65487],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [61, 22],
            '61 campaign_lead_event_log and 22 lead_event_log rows would have been deleted. This is a dry run.',
            true,
            65487,
        ];

        yield 'real run all tables' => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => true,
            ],
            [
                [
                    'DELETE prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_campaign_lead_event_log FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'UPDATE prefix_table_email_stats SET tokens = NULL WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_email_stats_devices FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_email_stats FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [3, 14, 32, 55, 41],
            '3 lead_event_log, 14 campaign_lead_event_log, 55 email_stats_devices and 41 email_stats rows have been deleted. 32 email_stats_tokens have been set to NULL.',
            false,
            null,
        ];

        yield 'real run single table' => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'DELETE prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [42],
            '42 lead_event_log rows have been deleted.',
            false,
            null,
        ];

        yield 'real run email_stats table to see if email_stats_devices is also cleared' => [
            [
                EventLogCleanup::LEAD_EVENTS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'DELETE prefix_table_email_stats_devices FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_email_stats FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [42, 12],
            '42 email_stats_devices and 12 email_stats rows have been deleted.',
            false,
            null,
        ];

        yield 'real run all tables with campaignId' => [
            [
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => true,
            ],
            [
                [
                    'DELETE prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_campaign_lead_event_log FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, ':cmpId' => 12235],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'UPDATE prefix_table_email_stats SET tokens = NULL WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_email_stats_devices FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_email_stats FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [34, 41, 19, 3, 6],
            '34 lead_event_log, 41 campaign_lead_event_log, 3 email_stats_devices and 6 email_stats rows have been deleted. 19 email_stats_tokens have been set to NULL.',
            false,
            12235,
        ];

        yield 'real run two tables with campaignId' => [
            [
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'DELETE prefix_table_campaign_lead_event_log FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, ':cmpId' => 65487],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [61, 22],
            '61 campaign_lead_event_log and 22 lead_event_log rows have been deleted.',
            false,
            65487,
        ];
    }
}
