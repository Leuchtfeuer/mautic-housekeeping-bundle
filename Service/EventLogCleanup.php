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

    public const DEFAULT_DAYS         = 365;
    public const CAMPAIGN_LEAD_EVENTS = 'campaign_lead_event_log';
    public const LEAD_EVENTS          = 'lead_event_log';
    public const EMAIL_STATS          = 'email_stats';
    public const EMAIL_STATS_TOKENS   = 'email_stats_tokens';
    private const EMAIL_STATS_DEVICES = 'email_stats_devices';

    /**
     * @var array<string, string>
     */
    private array $queries = [
        self::CAMPAIGN_LEAD_EVENTS => self::PREFIX.'campaign_lead_event_log WHERE ('.self::PREFIX.'campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM '.self::PREFIX.'campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND '.self::PREFIX.'campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
        self::LEAD_EVENTS          => self::PREFIX.'lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
        self::EMAIL_STATS          => self::PREFIX.'email_stats LEFT JOIN emails ON email_stats.email_id = emails.id WHERE is_published = 0 OR email_stats.email_id IS NULL AND date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
        self::EMAIL_STATS_TOKENS   => self::PREFIX.'email_stats SET tokens = NULL WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
        self::EMAIL_STATS_DEVICES  => self::PREFIX.'email_stats_devices LEFT JOIN '.self::PREFIX.'email_stats ON '.self::PREFIX.'email_stats.id = '.self::PREFIX.'email_stats_devices.stat_id WHERE '.self::PREFIX.'email_stats.id IS NULL OR '.self::PREFIX.'email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
    ];

    private array $params = [
        self::CAMPAIGN_LEAD_EVENTS => [
            ':daysOld' => self::DEFAULT_DAYS,
        ],
        self::LEAD_EVENTS          => [
            ':daysOld' => self::DEFAULT_DAYS,
        ],
        self::EMAIL_STATS          => [
            ':daysOld' => self::DEFAULT_DAYS,
        ],
        self::EMAIL_STATS_TOKENS   => [
            ':daysOld' => self::DEFAULT_DAYS,
        ],
        self::EMAIL_STATS_DEVICES  => [
            ':daysOld' => self::DEFAULT_DAYS,
        ],
    ];

    private array $types = [
        self::CAMPAIGN_LEAD_EVENTS => [
            ':daysOld' => \PDO::PARAM_INT,
        ],
        self::LEAD_EVENTS          => [
            ':daysOld' => \PDO::PARAM_INT,
        ],
        self::EMAIL_STATS          => [
            ':daysOld' => \PDO::PARAM_INT,
        ],
        self::EMAIL_STATS_TOKENS   => [
            ':daysOld' => \PDO::PARAM_INT,
        ],
        self::EMAIL_STATS_DEVICES  => [
            ':daysOld' => \PDO::PARAM_INT,
        ],
    ];

    private string $dryRunMessage = ' rows would have been deleted. This is a dry run.';
    private string $runMessage    = ' rows have been deleted.';

    private string $dryRunMessageTokens = ' will be set to NULL. This is a dry run.';
    private string $runMessageTokens    = ' have been set to NULL.';

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
        if (self::DEFAULT_DAYS !== $daysOld) {
            foreach ($this->params as $index => $item) {
                $this->params[$index][':daysOld'] = $daysOld;
            }
        }

        if (null !== $campaignId && $operations[self::CAMPAIGN_LEAD_EVENTS]) {
            $this->params[self::CAMPAIGN_LEAD_EVENTS][':cmpId'] = $campaignId;
            $this->types[self::CAMPAIGN_LEAD_EVENTS][':cmpId']  = \PDO::PARAM_INT;
            $this->queries[self::CAMPAIGN_LEAD_EVENTS] .= ' AND campaign_id = :cmpId';
        }

        if (array_key_exists(self::EMAIL_STATS, $operations) && true === $operations[self::EMAIL_STATS]) {
            unset($operations[self::EMAIL_STATS]);
            $operations[self::EMAIL_STATS_DEVICES] = true;
            $operations[self::EMAIL_STATS]         = true;
        }

        $result = [
            self::CAMPAIGN_LEAD_EVENTS => 0,
            self::LEAD_EVENTS          => 0,
            self::EMAIL_STATS          => 0,
            self::EMAIL_STATS_TOKENS   => 0,
            self::EMAIL_STATS_DEVICES  => 0,
        ];

        $this->connection->beginTransaction();

        if ($dryRun) {
            foreach ($operations as $operation => $enabled) {
                if (false === $enabled) {
                    continue;
                }

                if (true === $operations[self::EMAIL_STATS_TOKENS]) {
                    $sql = 'SELECT * FROM email_stats WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL';
                    $statement               = $this->connection->executeQuery($sql, $this->params[$operation], $this->types[$operation]);
                    $result[$operation]      = $statement->rowCount();
                } else {
                    $sql = 'SELECT * FROM ' . str_replace(self::PREFIX, $this->dbPrefix, $this->queries[$operation]);
                    $statement               = $this->connection->executeQuery($sql, $this->params[$operation], $this->types[$operation]);
                    $result[$operation]      = $statement->rowCount();
                }


                if ($output->isVerbose()) {
                    $output->writeln($sql);
                }
            }
        } else {
            foreach ($operations as $operation => $enabled) {
                if (false === $enabled) {
                    continue;
                }

                if (true === $operations[self::EMAIL_STATS_TOKENS]) {
                    $sql = 'UPDATE '.str_replace(self::PREFIX, $this->dbPrefix, $this->queries[$operation]);
                }
                else {
                    $sql = 'DELETE '.$this->dbPrefix.$operation.' FROM '.str_replace(self::PREFIX, $this->dbPrefix, $this->queries[$operation]);
                }

                $statement               = $this->connection->executeQuery($sql, $this->params[$operation], $this->types[$operation]);
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
        if (true === $operations[self::EMAIL_STATS_TOKENS]) {
            $message .= $dryRun ? $this->dryRunMessageTokens : $this->runMessageTokens;
        } else {
            $message .= $dryRun ? $this->dryRunMessage : $this->runMessage;
        }
        return $message;
    }
}
