<?php

declare(strict_types=1);

namespace MauticPlugin\MauticHousekeepingBundle\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Output\OutputInterface;

class EventLogCleanup
{
    private const PREFIX = '%PREFIX%';

    private Connection $connection;
    private string $dbPrefix;

    public const CAMPAIGN_LEAD_EVENTS = 'campaign_lead_event_log';
    public const LEAD_EVENTS          = 'lead_event_log';
    public const EMAIL_STATS          = 'email_stats';

    /**
     * @var array<string, string>
     */
    private array $queries = [
        self::CAMPAIGN_LEAD_EVENTS => self::PREFIX.'campaign_lead_event_log WHERE ('.self::PREFIX.'campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM '.self::PREFIX.'campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND '.self::PREFIX.'campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
        self::LEAD_EVENTS          => self::PREFIX.'lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
        self::EMAIL_STATS          => self::PREFIX.'email_stats WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
    ];

    private string $dryRunMessage = ' rows would have been deleted. This is a dry run.';
    private string $runMessage    = ' rows have been deleted.';

    public function __construct(Connection $connection, ?string $dbPrefix)
    {
        $this->connection = $connection;
        $this->dbPrefix   = $dbPrefix ?? '';
    }

    /**
     * @param non-empty-array<string, bool> $operations
     */
    public function deleteEventLogEntries(int $daysOld, ?int $campaignId, bool $dryRun, array $operations, OutputInterface $output): string
    {
        $params = [
            ':daysOld' => $daysOld,
        ];
        $types = [
            ':daysold' => \PDO::PARAM_INT,
        ];

        if (null !== $campaignId && $operations[self::CAMPAIGN_LEAD_EVENTS]) {
            $params[':cmpId'] = $campaignId;
            $types[':cmpId']  = \PDO::PARAM_INT;
            $this->queries[self::CAMPAIGN_LEAD_EVENTS] .= ' AND campaign_id = :cmpId';
        }

        $result = [
            self::CAMPAIGN_LEAD_EVENTS => 0,
            self::LEAD_EVENTS          => 0,
            self::EMAIL_STATS          => 0,
        ];

        $this->connection->beginTransaction();

        if ($dryRun) {
            foreach ($operations as $operation => $enabled) {
                if (false === $enabled) {
                    continue;
                }

                $sql                     = 'SELECT * FROM '.str_replace(self::PREFIX, $this->dbPrefix, $this->queries[$operation]);
                $statement               = $this->connection->executeQuery($sql, $params, $types);
                $result[$operation]      = $statement->rowCount();

                if ($output->isVerbose()) {
                    $output->writeln($sql);
                }
            }
        } else {
            foreach ($operations as $operation => $enabled) {
                if (false === $enabled) {
                    continue;
                }

                $sql                     = 'DELETE FROM '.str_replace(self::PREFIX, $this->dbPrefix, $this->queries[$operation]);
                $statement               = $this->connection->executeQuery($sql, $params, $types);
                $result[$operation]      = $statement->rowCount();

                if ($output->isVerbose()) {
                    $output->writeln($sql);
                }
            }
        }

        try {
            $this->connection->commit();
        } catch (\Throwable $throwable) {
            $this->connection->rollBack();
            throw $throwable;
        }

        $message       = '';
        $lastOperation = array_key_last($operations);
        foreach ($operations as $operation => $enabled) {
            if (false === $enabled) {
                continue;
            }

            if ('' !== $message) {
                if ($lastOperation === $operation) {
                    $message .= ' and ';
                } else {
                    $message .= ', ';
                }
            }

            $message .= $result[$operation].' '.$operation;
        }

        $message .= $dryRun ? $this->dryRunMessage : $this->runMessage;

        return $message;
    }
}
