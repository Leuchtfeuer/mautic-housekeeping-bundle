<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Service\EventLogCleanup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

final class EventLogCleanupLeadObjectsTest extends TestCase
{
    /**
     * @dataProvider leadObjectsProvider
     */
    public function testLeadObjectsLimitTheLeadEventLogQuery(bool $dryRun, string $sql, string $resultMethod, string $message): void
    {
        $statement = $this->createMock(Result::class);
        $statement->expects($this->once())
            ->method($resultMethod)
            ->willReturn(5);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $sql,
                ['daysOld' => 4, 'leadObjects' => ['segment', 'import']],
                ['daysOld' => \PDO::PARAM_INT, 'leadObjects' => ArrayParameterType::STRING]
            )
            ->willReturn($statement);

        $eventLogCleanup = $this->createEventLogCleanup($connection);
        $eventLogCleanup->setLeadObjects(['segment', 'import']);

        $this->assertSame(
            $message,
            $eventLogCleanup->deleteEventLogEntries(4, null, $dryRun, [EventLogCleanup::LEAD_EVENTS => true], $this->createStub(OutputInterface::class))
        );
    }

    public static function leadObjectsProvider(): \Generator
    {
        yield 'dry run' => [
            true,
            'SELECT COUNT(1) as cnt FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_lead_event_log.object IN (:leadObjects)',
            'fetchOne',
            '5 lead_event_log rows would have been deleted. This is a dry run.',
        ];

        yield 'real run' => [
            false,
            'DELETE LOW_PRIORITY prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_lead_event_log.object IN (:leadObjects)',
            'rowCount',
            '5 lead_event_log rows have been deleted.',
        ];
    }

    public function testLeadObjectsDoNotAffectOtherTables(): void
    {
        $statement = $this->createMock(Result::class);
        $statement->method('rowCount')
            ->willReturn(1);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                'DELETE LOW_PRIORITY prefix_table_page_hits FROM prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                ['daysOld' => 4],
                ['daysOld' => \PDO::PARAM_INT]
            )
            ->willReturn($statement);

        $eventLogCleanup = $this->createEventLogCleanup($connection);
        $eventLogCleanup->setLeadObjects(['segment']);
        $eventLogCleanup->deleteEventLogEntries(4, null, false, [EventLogCleanup::PAGE_HITS => true], $this->createStub(OutputInterface::class));
    }

    private function createEventLogCleanup(Connection $connection): EventLogCleanup
    {
        $config = $this->createMock(Config::class);
        $config->method('isPublished')
            ->willReturn(true);

        return new EventLogCleanup($connection, 'prefix_table_', $config, $this->createStub(\Psr\Log\LoggerInterface::class));
    }
}
