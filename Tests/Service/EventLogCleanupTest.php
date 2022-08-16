<?php

declare(strict_types=1);

namespace MauticPlugin\MauticHousekeepingBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Result;
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

    public function runProvider(): array
    {
        $daysOld = 4;

        $calls   = [];

        // dry run all tables
        $calls[] = [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS          => true,
            ],
            [
                [
                    'SELECT * FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysold' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT * FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
                    [':daysOld' => $daysOld],
                    [':daysold' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT * FROM prefix_table_email_stats WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysold' => \PDO::PARAM_INT],
                ],
            ],
            [3, 14, 55],
            '3 lead_event_log, 14 campaign_lead_event_log and 55 email_stats rows would have been deleted. This is a dry run.',
            true,
            null,
        ];

        // dry run single table
        $calls[] = [
            [
                EventLogCleanup::LEAD_EVENTS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => true,
            ],
            [
                [
                    'SELECT * FROM prefix_table_email_stats WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysold' => \PDO::PARAM_INT],
                ],
            ],
            [42],
            '42 email_stats rows would have been deleted. This is a dry run.',
            true,
            null,
        ];

        // dry run all tables with campaignId
        $calls[] = [
            [
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
            ],
            [
                [
                    'SELECT * FROM prefix_table_email_stats WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld, ':cmpId' => 12235],
                    [':daysold' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT * FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld, ':cmpId' => 12235],
                    [':daysold' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT * FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    [':daysOld' => $daysOld, ':cmpId' => 12235],
                    [':daysold' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
            ],
            [34, 41, 3],
            '34 email_stats, 41 lead_event_log and 3 campaign_lead_event_log rows would have been deleted. This is a dry run.',
            true,
            12235,
        ];

        // dry run two tables with campaignId
        $calls[] = [
            [
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::LEAD_EVENTS          => true,
            ],
            [
                [
                    'SELECT * FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    [':daysOld' => $daysOld, ':cmpId' => 65487],
                    [':daysold' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT * FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld, ':cmpId' => 65487],
                    [':daysold' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
            ],
            [61, 22],
            '61 campaign_lead_event_log and 22 lead_event_log rows would have been deleted. This is a dry run.',
            true,
            65487,
        ];

        // real run all tables
        $calls[] = [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS          => true,
            ],
            [
                [
                    'DELETE FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysold' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
                    [':daysOld' => $daysOld],
                    [':daysold' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE FROM prefix_table_email_stats WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysold' => \PDO::PARAM_INT],
                ],
            ],
            [3, 14, 55],
            '3 lead_event_log, 14 campaign_lead_event_log and 55 email_stats rows have been deleted.',
            false,
            null,
        ];

        // real run single table
        $calls[] = [
            [
                EventLogCleanup::LEAD_EVENTS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => true,
            ],
            [
                [
                    'DELETE FROM prefix_table_email_stats WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld],
                    [':daysold' => \PDO::PARAM_INT],
                ],
            ],
            [42],
            '42 email_stats rows have been deleted.',
            false,
            null,
        ];

        // real run all tables with campaignId
        $calls[] = [
            [
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
            ],
            [
                [
                    'DELETE FROM prefix_table_email_stats WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld, ':cmpId' => 12235],
                    [':daysold' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld, ':cmpId' => 12235],
                    [':daysold' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    [':daysOld' => $daysOld, ':cmpId' => 12235],
                    [':daysold' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
            ],
            [34, 41, 3],
            '34 email_stats, 41 lead_event_log and 3 campaign_lead_event_log rows have been deleted.',
            false,
            12235,
        ];

        // real run two tables with campaignId
        $calls[] = [
            [
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::LEAD_EVENTS          => true,
            ],
            [
                [
                    'DELETE FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    [':daysOld' => $daysOld, ':cmpId' => 65487],
                    [':daysold' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    [':daysOld' => $daysOld, ':cmpId' => 65487],
                    [':daysold' => \PDO::PARAM_INT, ':cmpId' => \PDO::PARAM_INT],
                ],
            ],
            [61, 22],
            '61 campaign_lead_event_log and 22 lead_event_log rows have been deleted.',
            false,
            65487,
        ];

        return $calls;
    }
}
