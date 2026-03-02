<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Service\EventLogCleanup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

class EventLogCleanupTest extends TestCase
{
    /**
     * @dataProvider runProvider
     *
     * @param non-empty-array<string, bool>                                         $operations
     * @param array<array{0: string, 1: array<string, int>, 2: array<string, int>}> $queries
     * @param array<int>                                                            $countRows
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
            ->willReturnCallback(static function (string $sql, array $params, array $types) use (&$queries, &$statements): Result {
                self::assertCount(count($queries), $statements);
                $query     = array_shift($queries);
                $statement = array_shift($statements);

                self::assertCount(3, $query);
                self::assertSame($sql, $query[0]);
                self::assertSame($params, $query[1]);
                self::assertSame($types, $query[2]);

                return $statement;
            });

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
            ->willReturnCallback(static function (string $loggedQuery) use (&$loggedQueries): void {
                $expected = array_shift($loggedQueries);
                self::assertCount(1, $expected);
                self::assertSame($loggedQuery, $expected[0]);
            });

        $config = $this->createMock(Config::class);
        $config->method('isPublished')
            ->willReturn(true);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $eventLogCleanup = new EventLogCleanup($connection, 'prefix_table_', $config, $logger);
        self::assertSame($message, $eventLogCleanup->deleteEventLogEntries(4, $campaignId, $dryRun, $operations, $output));
    }

    public function testAggregateRedirectsPluginNotPublished(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeQuery');

        $output = $this->createMock(OutputInterface::class);

        $config = $this->createMock(Config::class);
        $config->method('isPublished')->willReturn(false);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $eventLogCleanup = new EventLogCleanup($connection, 'prefix_table_', $config, $logger);
        self::assertSame(
            'Housekeeping by Leuchtfeuer is currently not enabled. To use it, please enable the plugin in your Mautic plugin management.',
            $eventLogCleanup->aggregateRedirects(true, $output)
        );
    }

    public function testAggregateRedirectsDryRunNoDuplicates(): void
    {
        $findDuplicatesResult = $this->createMock(Result::class);
        $findDuplicatesResult->expects(self::once())->method('fetchAllAssociative')->willReturn([]);

        $orphanCountResult = $this->createMock(Result::class);
        $orphanCountResult->expects(self::once())->method('fetchOne')->willReturn(0);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(static function () use (&$findDuplicatesResult, &$orphanCountResult): Result {
                if (null !== $findDuplicatesResult) {
                    $result = $findDuplicatesResult;
                    $findDuplicatesResult = null;

                    return $result;
                }

                return $orphanCountResult;
            });

        $output = $this->createMock(OutputInterface::class);
        $output->method('isVerbose')->willReturn(false);

        $config = $this->createMock(Config::class);
        $config->method('isPublished')->willReturn(true);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $eventLogCleanup = new EventLogCleanup($connection, 'prefix_table_', $config, $logger);
        self::assertSame(
            'No duplicate redirects or orphan hits found. This is a dry run.',
            $eventLogCleanup->aggregateRedirects(true, $output)
        );
    }

    public function testAggregateRedirectsDryRunWithDuplicates(): void
    {
        $findDuplicatesResult = $this->createMock(Result::class);
        $findDuplicatesResult->expects(self::once())->method('fetchAllAssociative')->willReturn([
            [
                'url'             => 'http://example.com',
                'channel'         => 'email',
                'channel_id'      => 5,
                'winner_id'       => 1,
                'all_ids'         => '1,2,3',
                'duplicate_count' => 3,
            ],
        ]);

        $orphanCountResult = $this->createMock(Result::class);
        $orphanCountResult->expects(self::once())->method('fetchOne')->willReturn(4);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(static function () use (&$findDuplicatesResult, &$orphanCountResult): Result {
                if (null !== $findDuplicatesResult) {
                    $result = $findDuplicatesResult;
                    $findDuplicatesResult = null;

                    return $result;
                }

                return $orphanCountResult;
            });

        $output = $this->createMock(OutputInterface::class);
        $output->method('isVerbose')->willReturn(false);

        $config = $this->createMock(Config::class);
        $config->method('isPublished')->willReturn(true);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $eventLogCleanup = new EventLogCleanup($connection, 'prefix_table_', $config, $logger);
        self::assertSame(
            'Found 1 duplicate redirect groups (2 duplicate entries to consolidate) and 4 orphan hits to reassign. This is a dry run.',
            $eventLogCleanup->aggregateRedirects(true, $output)
        );
    }

    public function testAggregateRedirectsDryRunWithDuplicatesNoOrphans(): void
    {
        $findDuplicatesResult = $this->createMock(Result::class);
        $findDuplicatesResult->expects(self::once())->method('fetchAllAssociative')->willReturn([
            [
                'url'             => 'http://example.com/page1',
                'channel'         => 'email',
                'channel_id'      => 10,
                'winner_id'       => 5,
                'all_ids'         => '5,15,25',
                'duplicate_count' => 3,
            ],
            [
                'url'             => 'http://example.com/page2',
                'channel'         => 'email',
                'channel_id'      => 10,
                'winner_id'       => 6,
                'all_ids'         => '6,16',
                'duplicate_count' => 2,
            ],
        ]);

        $orphanCountResult = $this->createMock(Result::class);
        $orphanCountResult->expects(self::once())->method('fetchOne')->willReturn(0);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(static function () use (&$findDuplicatesResult, &$orphanCountResult): Result {
                if (null !== $findDuplicatesResult) {
                    $result = $findDuplicatesResult;
                    $findDuplicatesResult = null;

                    return $result;
                }

                return $orphanCountResult;
            });

        $output = $this->createMock(OutputInterface::class);
        $output->method('isVerbose')->willReturn(false);

        $config = $this->createMock(Config::class);
        $config->method('isPublished')->willReturn(true);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $eventLogCleanup = new EventLogCleanup($connection, 'prefix_table_', $config, $logger);
        self::assertSame(
            'Found 2 duplicate redirect groups (3 duplicate entries to consolidate). This is a dry run.',
            $eventLogCleanup->aggregateRedirects(true, $output)
        );
    }

    public function testAggregateRedirectsRealRunSingleGroup(): void
    {
        $p = 'prefix_table_';

        // Expected queries in order
        $expectedQueries = [
            // Query 1: Find duplicates
            'SELECT pr.url, cut.channel, cut.channel_id, MIN(pr.id) as winner_id, GROUP_CONCAT(pr.id ORDER BY pr.id) as all_ids, COUNT(*) as duplicate_count FROM '.$p.'page_redirects pr INNER JOIN '.$p.'channel_url_trackables cut ON cut.redirect_id = pr.id GROUP BY pr.url, cut.channel, cut.channel_id HAVING COUNT(*) > 1',
            // Query 2: Count orphans
            'SELECT COUNT(*) FROM '.$p.'page_hits ph INNER JOIN '.$p.'page_redirects orphan ON ph.redirect_id = orphan.id LEFT JOIN '.$p.'channel_url_trackables cut ON cut.redirect_id = orphan.id INNER JOIN '.$p.'page_redirects winner ON winner.url = orphan.url AND winner.id != orphan.id INNER JOIN '.$p."channel_url_trackables wcut ON wcut.redirect_id = winner.id AND wcut.channel = 'email' AND wcut.channel_id = ph.email_id WHERE cut.redirect_id IS NULL",
            // Query 3: UPDATE page_hits (move hits to winner)
            'UPDATE '.$p.'page_hits SET redirect_id = :winnerId WHERE redirect_id IN (:loserIds)',
            // Query 4: SELECT trackable stats from losers
            'SELECT COALESCE(SUM(hits), 0) as total_hits, COALESCE(SUM(unique_hits), 0) as total_unique_hits FROM '.$p.'channel_url_trackables WHERE redirect_id IN (:loserIds) AND channel = :channel AND channel_id = :channelId',
            // Query 5: UPDATE trackable stats on winner
            'UPDATE '.$p.'channel_url_trackables SET hits = hits + :addHits, unique_hits = unique_hits + :addUniqueHits WHERE redirect_id = :winnerId AND channel = :channel AND channel_id = :channelId',
            // Query 6: DELETE loser trackable entries
            'DELETE FROM '.$p.'channel_url_trackables WHERE redirect_id IN (:loserIds) AND channel = :channel AND channel_id = :channelId',
            // Query 7: SELECT redirect stats from losers
            'SELECT COALESCE(SUM(hits), 0) as total_hits, COALESCE(SUM(unique_hits), 0) as total_unique_hits FROM '.$p.'page_redirects WHERE id IN (:loserIds)',
            // Query 8: UPDATE redirect stats on winner
            'UPDATE '.$p.'page_redirects SET hits = hits + :addHits, unique_hits = unique_hits + :addUniqueHits WHERE id = :winnerId',
            // Query 9: Zero out loser redirect stats
            'UPDATE '.$p.'page_redirects SET hits = 0, unique_hits = 0 WHERE id IN (:loserIds)',
        ];

        // Result mocks for each query
        $findDuplicatesResult = $this->createMock(Result::class);
        $findDuplicatesResult->expects(self::once())->method('fetchAllAssociative')->willReturn([
            [
                'url'             => 'http://test.com',
                'channel'         => 'email',
                'channel_id'      => 7,
                'winner_id'       => 10,
                'all_ids'         => '10,20',
                'duplicate_count' => 2,
            ],
        ]);

        $orphanCountResult = $this->createMock(Result::class);
        $orphanCountResult->expects(self::once())->method('fetchOne')->willReturn(0);

        $trackableStatsResult = $this->createMock(Result::class);
        $trackableStatsResult->expects(self::once())->method('fetchAssociative')->willReturn([
            'total_hits'        => 5,
            'total_unique_hits' => 3,
        ]);

        $redirectStatsResult = $this->createMock(Result::class);
        $redirectStatsResult->expects(self::once())->method('fetchAssociative')->willReturn([
            'total_hits'        => 8,
            'total_unique_hits' => 4,
        ]);

        $voidResult = $this->createMock(Result::class);

        $resultMap = [
            $findDuplicatesResult,
            $orphanCountResult,
            $voidResult, // UPDATE page_hits
            $trackableStatsResult,
            $voidResult, // UPDATE trackable stats
            $voidResult, // DELETE trackables
            $redirectStatsResult,
            $voidResult, // UPDATE redirect stats
            $voidResult, // Zero out losers
        ];

        $queryIndex = 0;
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(9))
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql) use (&$queryIndex, $expectedQueries, $resultMap): Result {
                self::assertSame($expectedQueries[$queryIndex], $sql, 'Query #'.($queryIndex + 1).' mismatch');
                $result = $resultMap[$queryIndex];
                ++$queryIndex;

                return $result;
            });
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');

        $output = $this->createMock(OutputInterface::class);
        $output->method('isVerbose')->willReturn(false);

        $config = $this->createMock(Config::class);
        $config->method('isPublished')->willReturn(true);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $eventLogCleanup = new EventLogCleanup($connection, $p, $config, $logger);
        self::assertSame(
            'Aggregated 1 duplicate redirect groups (1 duplicate entries consolidated).',
            $eventLogCleanup->aggregateRedirects(false, $output)
        );
    }

    public function testAggregateRedirectsRealRunNothingToDo(): void
    {
        $findDuplicatesResult = $this->createMock(Result::class);
        $findDuplicatesResult->expects(self::once())->method('fetchAllAssociative')->willReturn([]);

        $orphanCountResult = $this->createMock(Result::class);
        $orphanCountResult->expects(self::once())->method('fetchOne')->willReturn(0);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('executeQuery')
            ->willReturnCallback(static function () use (&$findDuplicatesResult, &$orphanCountResult): Result {
                if (null !== $findDuplicatesResult) {
                    $result = $findDuplicatesResult;
                    $findDuplicatesResult = null;

                    return $result;
                }

                return $orphanCountResult;
            });
        $connection->expects(self::never())->method('beginTransaction');

        $output = $this->createMock(OutputInterface::class);
        $output->method('isVerbose')->willReturn(false);

        $config = $this->createMock(Config::class);
        $config->method('isPublished')->willReturn(true);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $eventLogCleanup = new EventLogCleanup($connection, 'prefix_table_', $config, $logger);
        self::assertSame(
            'No duplicate redirects or orphan hits found. Nothing to do.',
            $eventLogCleanup->aggregateRedirects(false, $output)
        );
    }

    public static function runProvider(): \Generator
    {
        $daysOld = 4;

        yield 'dry run all tables' => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => true,
                EventLogCleanup::PAGE_HITS            => true,
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
                    'SELECT COUNT(1) as cnt FROM prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
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
            [3, 14, 23, 4, 55, 57],
            '3 lead_event_log, 14 campaign_lead_event_log, 4 page_hits, 55 email_stats_devices and 57 email_stats rows would have been deleted. 23 email_stats_tokens will be set to NULL. This is a dry run.',
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
                    ['daysOld' => $daysOld, 'cmpId' => 12235],
                    ['daysOld' => \PDO::PARAM_INT, 'cmpId' => \PDO::PARAM_INT],
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
                    ['daysOld' => $daysOld, 'cmpId' => 65487],
                    ['daysOld' => \PDO::PARAM_INT, 'cmpId' => \PDO::PARAM_INT],
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
                EventLogCleanup::PAGE_HITS            => true,
            ],
            [
                [
                    'DELETE LOW_PRIORITY prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_campaign_lead_event_log FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'UPDATE LOW_PRIORITY prefix_table_email_stats SET tokens = NULL WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_page_hits FROM prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_email_stats_devices FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_email_stats FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [3, 14, 32, 21, 55, 41],
            '3 lead_event_log, 14 campaign_lead_event_log, 21 page_hits, 55 email_stats_devices and 41 email_stats rows have been deleted. 32 email_stats_tokens have been set to NULL.',
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
                    'DELETE LOW_PRIORITY prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
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
                    'DELETE LOW_PRIORITY prefix_table_email_stats_devices FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_email_stats FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
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
                    'DELETE LOW_PRIORITY prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_campaign_lead_event_log FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, 'cmpId' => 12235],
                    ['daysOld' => \PDO::PARAM_INT, 'cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'UPDATE LOW_PRIORITY prefix_table_email_stats SET tokens = NULL WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_email_stats_devices FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_email_stats FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
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
                    'DELETE LOW_PRIORITY prefix_table_campaign_lead_event_log FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, 'cmpId' => 65487],
                    ['daysOld' => \PDO::PARAM_INT, 'cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
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
