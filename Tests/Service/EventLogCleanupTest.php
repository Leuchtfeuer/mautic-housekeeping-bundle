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
     * @dataProvider dryRunProvider
     */
    public function testDeleteEventLogEntriesDryRun(array $operations, array $queries, array $countRows, string $message, ?int $campaignId): void
    {
        $statements = [];
        foreach ($countRows as $countRow) {
            $statement = $this->createMock(Result::class);
            $statement->expects(self::once())
                ->method('fetchOne')
                ->willReturn($countRow);

            $statements[] = $statement;
        }

        $selectDataIndex  = 0;
        $connection       = $this->createMock(Connection::class);
        $connection->expects(self::exactly(count($queries)))
            ->method('executeQuery')
            ->willReturnCallback(static function (string $sql, array $parameters, array $types) use ($statements, $queries, &$selectDataIndex): Result {
                $query = $queries[$selectDataIndex];
                self::assertIsArray($query);
                $statementResult = $statements[$selectDataIndex];

                self::assertSame($query[0], $sql);
                self::assertSame($query[1], $parameters);
                self::assertSame($query[2], $types);

                ++$selectDataIndex;

                return $statementResult;
            });

        $loggedQueries = [];
        foreach ($queries as $query) {
            $loggedQueries[] = $query[0];
        }

        $logIndex = 0;
        $output   = $this->createMock(OutputInterface::class);
        $output->expects(self::exactly(count($queries)))
            ->method('isVerbose')
            ->willReturn(true);
        $output->expects(self::exactly(count($queries)))
            ->method('writeln')
            ->willReturnCallback(static function (string $log) use ($loggedQueries, &$logIndex): void {
                $loggedQuery = $loggedQueries[$logIndex];
                self::assertSame($loggedQuery, $log);

                ++$logIndex;
            });

        $config = $this->createMock(Config::class);
        $config->method('isPublished')
            ->willReturn(true);

        $eventLogCleanup = new EventLogCleanup($connection, 'prefix_table_', $config);
        self::assertSame($message, $eventLogCleanup->deleteEventLogEntries(4, $campaignId, true, $operations, $output));
    }

    public function dryRunProvider(): Generator
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
                    'SELECT COUNT(1) as cnt FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats as operation_table WHERE operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats_devices as operation_table LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = operation_table.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats as operation_table LEFT JOIN prefix_table_emails ON operation_table.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR operation_table.email_id IS NULL) AND operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [3, 14, 23, 55, 57],
            '3 lead_event_log, 14 campaign_lead_event_log, 55 email_stats_devices and 57 email_stats rows would have been deleted. 23 email_stats_tokens will be set to NULL. This is a dry run.',
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
                    'SELECT COUNT(1) as cnt FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [31],
            '31 lead_event_log rows would have been deleted. This is a dry run.',
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
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats_devices as operation_table LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = operation_table.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats as operation_table LEFT JOIN prefix_table_emails ON operation_table.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR operation_table.email_id IS NULL) AND operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [42, 67],
            '42 email_stats_devices and 67 email_stats rows would have been deleted. This is a dry run.',
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
                    'SELECT COUNT(1) as cnt FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, ':cmpId' => 12235],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats as operation_table WHERE operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats_devices as operation_table LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = operation_table.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats as operation_table LEFT JOIN prefix_table_emails ON operation_table.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR operation_table.email_id IS NULL) AND operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [34, 41, 11, 3, 87],
            '34 lead_event_log, 41 campaign_lead_event_log, 3 email_stats_devices and 87 email_stats rows would have been deleted. 11 email_stats_tokens will be set to NULL. This is a dry run.',
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
                    'SELECT COUNT(1) as cnt FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, ':cmpId' => 65487],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [61, 22],
            '61 campaign_lead_event_log and 22 lead_event_log rows would have been deleted. This is a dry run.',
            65487,
        ];
    }

    /**
     * @dataProvider deleteRunProvider
     */
    public function testDeleteEventLogEntriesDelete(array $operations, array $selectQueries, array $countSelectRows, array $changeQueries, array $countChangeRows, string $message, ?int $campaignId): void
    {
        $selectStatements = [];
        foreach ($countSelectRows as $countRow) {
            $statement = $this->createMock(Result::class);
            $statement->expects(self::once())
                ->method('fetchOne')
                ->willReturn($countRow);

            $selectStatements[] = $statement;
        }

        $selectDataIndex = 0;
        $connection      = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('beginTransaction');
        $connection->expects(self::once())
            ->method('commit');
        $connection->expects(self::exactly(count($selectQueries)))
            ->method('executeQuery')
            ->willReturnCallback(static function (string $sql, array $parameters, array $types) use ($selectStatements, $selectQueries, &$selectDataIndex): Result {
                $query = $selectQueries[$selectDataIndex];
                self::assertIsArray($query);
                $statementResult = $selectStatements[$selectDataIndex];

                self::assertSame($query[0], $sql);
                self::assertSame($query[1], $parameters);
                self::assertSame($query[2], $types);

                ++$selectDataIndex;

                return $statementResult;
            });

        $changeDataIndex = 0;
        $connection->expects(self::exactly(count($changeQueries)))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters, array $types) use ($countChangeRows, $changeQueries, &$changeDataIndex): int {
                $query = $changeQueries[$changeDataIndex];
                self::assertIsArray($query);
                $statementResult = $countChangeRows[$changeDataIndex];

                self::assertSame($query[0], $sql);
                self::assertSame($query[1], $parameters);
                self::assertSame($query[2], $types);

                ++$changeDataIndex;

                return $statementResult;
            });

        $loggedQueries = [];
        foreach ($selectQueries as $query) {
            $loggedQueries[] = $query[0];
        }

        foreach ($changeQueries as $changeQueryIndex => $query) {
            array_splice($loggedQueries, $changeQueryIndex * 3 + 2, 0, $query[0]);
        }

        $logDataIndex = 0;
        $output       = $this->createMock(OutputInterface::class);
        $output->expects(self::exactly(count($loggedQueries)))
            ->method('isVerbose')
            ->willReturn(true);
        $output->expects(self::exactly(count($loggedQueries)))
            ->method('writeln')
            ->willReturnCallback(static function (string $log) use ($loggedQueries, &$logDataIndex): void {
                $loggedQuery = $loggedQueries[$logDataIndex];
                self::assertSame($loggedQuery, $log);

                ++$logDataIndex;
            });

        $config = $this->createMock(Config::class);
        $config->method('isPublished')
            ->willReturn(true);

        $eventLogCleanup = new EventLogCleanup($connection, 'prefix_table_', $config);
        self::assertSame($message, $eventLogCleanup->deleteEventLogEntries(4, $campaignId, false, $operations, $output));
    }

    public function deleteRunProvider(): Generator
    {
        $daysOld = 4;

        yield 'real run all tables' => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => true,
            ],
            [
                [
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_email_stats as operation_table WHERE operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_email_stats as operation_table WHERE operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_email_stats_devices as operation_table LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = operation_table.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_email_stats_devices as operation_table LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = operation_table.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_email_stats as operation_table LEFT JOIN prefix_table_emails ON operation_table.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR operation_table.email_id IS NULL) AND operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_email_stats as operation_table LEFT JOIN prefix_table_emails ON operation_table.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR operation_table.email_id IS NULL) AND operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [3, 6, 14, 18, 32, 37, 55, 59, 41, 44],
            [
                [
                    'DELETE operation_table FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, 'minId' => 3, 'maxId' => 6],
                    ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE operation_table FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, 'minId' => 14, 'maxId' => 18],
                    ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
                [
                    'UPDATE prefix_table_email_stats as operation_table SET tokens = NULL WHERE operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, 'minId' => 32, 'maxId' => 37],
                    ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE operation_table FROM prefix_table_email_stats_devices as operation_table LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = operation_table.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, 'minId' => 55, 'maxId' => 59],
                    ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE operation_table FROM prefix_table_email_stats as operation_table LEFT JOIN prefix_table_emails ON operation_table.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR operation_table.email_id IS NULL) AND operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, 'minId' => 41, 'maxId' => 44],
                    ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
            ],
            [3, 14, 32, 55, 41],
            '3 lead_event_log, 14 campaign_lead_event_log, 55 email_stats_devices and 41 email_stats rows have been deleted. 32 email_stats_tokens have been set to NULL.',
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
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [88, 300],
            [
                [
                    'DELETE operation_table FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, 'minId' => 88, 'maxId' => 300],
                    ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
            ],
            [42],
            '42 lead_event_log rows have been deleted.',
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
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_email_stats_devices as operation_table LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = operation_table.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_email_stats_devices as operation_table LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = operation_table.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_email_stats as operation_table LEFT JOIN prefix_table_emails ON operation_table.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR operation_table.email_id IS NULL) AND operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_email_stats as operation_table LEFT JOIN prefix_table_emails ON operation_table.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR operation_table.email_id IS NULL) AND operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [556, 900, 7010, 7456],
            [
                [
                    'DELETE operation_table FROM prefix_table_email_stats_devices as operation_table LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = operation_table.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, 'minId' => 556, 'maxId' => 900],
                    ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE operation_table FROM prefix_table_email_stats as operation_table LEFT JOIN prefix_table_emails ON operation_table.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR operation_table.email_id IS NULL) AND operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, 'minId' => 7010, 'maxId' => 7456],
                    ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
            ],
            [42, 12],
            '42 email_stats_devices and 12 email_stats rows have been deleted.',
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
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, ':cmpId' => 12235],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, ':cmpId' => 12235],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_email_stats as operation_table WHERE operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_email_stats as operation_table WHERE operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_email_stats_devices as operation_table LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = operation_table.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_email_stats_devices as operation_table LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = operation_table.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_email_stats as operation_table LEFT JOIN prefix_table_emails ON operation_table.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR operation_table.email_id IS NULL) AND operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_email_stats as operation_table LEFT JOIN prefix_table_emails ON operation_table.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR operation_table.email_id IS NULL) AND operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [12, 456, 5060, 5345, 742, 1012, 63, 456, 53, 109],
            [
                [
                    'DELETE operation_table FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, 'minId' => 12, 'maxId' => 456],
                    ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE operation_table FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, ':cmpId' => 12235, 'minId' => 5060, 'maxId' => 5345],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
                [
                    'UPDATE prefix_table_email_stats as operation_table SET tokens = NULL WHERE operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, 'minId' => 742, 'maxId' => 1012],
                    ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE operation_table FROM prefix_table_email_stats_devices as operation_table LEFT JOIN prefix_table_email_stats ON prefix_table_email_stats.id = operation_table.stat_id WHERE prefix_table_email_stats.id IS NULL OR prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, 'minId' => 63, 'maxId' => 456],
                    ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE operation_table FROM prefix_table_email_stats as operation_table LEFT JOIN prefix_table_emails ON operation_table.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR operation_table.email_id IS NULL) AND operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, 'minId' => 53, 'maxId' => 109],
                    ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
            ],
            [34, 41, 19, 3, 6],
            '34 lead_event_log, 41 campaign_lead_event_log, 3 email_stats_devices and 6 email_stats rows have been deleted. 19 email_stats_tokens have been set to NULL.',
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
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, ':cmpId' => 65487],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, ':cmpId' => 65487],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [9145, 9546, 801, 1253],
            [
                [
                    'DELETE operation_table FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, ':cmpId' => 65487, 'minId' => 9145, 'maxId' => 9546],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE operation_table FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND :minId <= operation_table.id AND operation_table.id <= :maxId',
                    ['daysOld' => $daysOld, 'minId' => 801, 'maxId' => 1253],
                    ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
                ],
            ],
            [61, 22],
            '61 campaign_lead_event_log and 22 lead_event_log rows have been deleted.',
            65487,
        ];

        $step                              = 5000;
        $queriesBatch                      = [];
        $queryDeleteCampaignLeadEventLog   = [
            'DELETE operation_table FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId AND :minId <= operation_table.id AND operation_table.id <= :maxId',
            ['daysOld' => $daysOld, ':cmpId' => 65487, 'minId' => 0, 'maxId' => 0],
            ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
        ];
        for ($index = 1; $index < 100002; $index += $step) {
            $queryDeleteCampaignLeadEventLog[1]['minId'] = $index;
            $queryDeleteCampaignLeadEventLog[1]['maxId'] = $index + $step;
            $queriesBatch[]                              = $queryDeleteCampaignLeadEventLog;
        }

        $queryDeleteLeadEventLog   = [
            'DELETE operation_table FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND :minId <= operation_table.id AND operation_table.id <= :maxId',
            ['daysOld' => $daysOld, 'minId' => 0, 'maxId' => 0],
            ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
        ];
        for ($index = 1000000; $index < 1400000; $index += $step) {
            $queryDeleteLeadEventLog[1]['minId'] = $index;
            $queryDeleteLeadEventLog[1]['maxId'] = $index + $step;
            $queriesBatch[]                      = $queryDeleteLeadEventLog;
        }
    }

    /**
     * @dataProvider batchingDeleteRunProvider
     */
    public function testDeleteEventLogEntriesBatchingDelete(array $operations, array $selectQueries, array $countSelectRows, array $changeQueries, array $countChangeRows, string $message, ?int $campaignId): void
    {
        $selectStatements = [];
        foreach ($countSelectRows as $countRow) {
            $statement = $this->createMock(Result::class);
            $statement->expects(self::once())
                ->method('fetchOne')
                ->willReturn($countRow);

            $selectStatements[] = $statement;
        }

        $selectDataIndex = 0;
        $connection      = $this->createMock(Connection::class);
        $connection->expects(self::exactly(63))
            ->method('beginTransaction');
        $connection->expects(self::exactly(63))
            ->method('commit');
        $connection->expects(self::exactly(count($selectQueries)))
            ->method('executeQuery')
            ->willReturnCallback(static function (string $sql, array $parameters, array $types) use ($selectStatements, $selectQueries, &$selectDataIndex): Result {
                $query = $selectQueries[$selectDataIndex];
                self::assertIsArray($query);
                $statementResult = $selectStatements[$selectDataIndex];

                self::assertSame($query[0], $sql);
                self::assertSame($query[1], $parameters);
                self::assertSame($query[2], $types);

                ++$selectDataIndex;

                return $statementResult;
            });

        $changeDataIndex = 0;
        $connection->expects(self::exactly(count($changeQueries)))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters, array $types) use ($countChangeRows, $changeQueries, &$changeDataIndex): int {
                $query = $changeQueries[$changeDataIndex];
                self::assertIsArray($query);
                $statementResult = $countChangeRows[$changeDataIndex];

                self::assertSame($query[0], $sql);
                self::assertSame($query[1], $parameters);
                self::assertSame($query[2], $types);

                ++$changeDataIndex;

                return $statementResult;
            });

        $output = $this->createMock(OutputInterface::class);

        $config = $this->createMock(Config::class);
        $config->method('isPublished')
            ->willReturn(true);

        $eventLogCleanup = new EventLogCleanup($connection, 'prefix_table_', $config);
        self::assertSame($message, $eventLogCleanup->deleteEventLogEntries(4, $campaignId, false, $operations, $output));
    }

    public function batchingDeleteRunProvider(): Generator
    {
        $daysOld = 4;

        $step                              = 5000;
        $queriesBatch                      = $rowsAffected                           = [];
        $queryDeleteCampaignLeadEventLog   = [
            'DELETE operation_table FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId AND :minId <= operation_table.id AND operation_table.id <= :maxId',
            ['daysOld' => $daysOld, ':cmpId' => 65487, 'minId' => 0, 'maxId' => 0],
            ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
        ];
        // 21 loops, so affected rows are 21 * 3 = 63
        for ($index = 1; $index <= 100002; $index += $step) {
            $queryDeleteCampaignLeadEventLog[1]['minId'] = $index;
            $queryDeleteCampaignLeadEventLog[1]['maxId'] = min($index + $step, 100002);
            $queriesBatch[]                              = $queryDeleteCampaignLeadEventLog;
            $rowsAffected[]                              = 3;
        }

        $queryDeleteLeadEventLog   = [
            'DELETE operation_table FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND :minId <= operation_table.id AND operation_table.id <= :maxId',
            ['daysOld' => $daysOld, 'minId' => 0, 'maxId' => 0],
            ['daysOld' => \PDO::PARAM_INT, 'minId' => \PDO::PARAM_INT, 'maxId' => \PDO::PARAM_INT],
        ];
        // 81 loops, so affected rows are 81
        for ($index = 1000000; $index <= 1400000; $index += $step) {
            $queryDeleteLeadEventLog[1]['minId'] = $index;
            $queryDeleteLeadEventLog[1]['maxId'] = min($index + $step, 1400000);
            $queriesBatch[]                      = $queryDeleteLeadEventLog;
            $rowsAffected[]                      = 1;
        }

        yield 'real run two tables with campaignId and batching' => [
            [
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, ':cmpId' => 65487],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, ':cmpId' => 65487],
                    ['daysOld' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MIN(operation_table.id) as minId FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT MAX(operation_table.id) as maxId FROM prefix_table_lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [1, 100002, 1000000, 1400000],
            $queriesBatch,
            $rowsAffected,
            '63 campaign_lead_event_log and 81 lead_event_log rows have been deleted.',
            65487,
        ];
    }
}
