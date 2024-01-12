<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Service;

use Doctrine\DBAL\Connection;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Config;
use Symfony\Component\Console\Output\OutputInterface;

class EventLogCleanup
{
    private const PREFIX = '%PREFIX%';

    /**
     * Constant used to indicate where the query can place "SET a = :a" when query is an update.
     */
    private const SET = '%SET% ';

    private Config $config;
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
    private array $queriesTemplate = [
        self::CAMPAIGN_LEAD_EVENTS => self::PREFIX.'campaign_lead_event_log as operation_table WHERE (operation_table.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM '.self::PREFIX.'campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND operation_table.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
        self::LEAD_EVENTS          => self::PREFIX.'lead_event_log as operation_table WHERE operation_table.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
        self::EMAIL_STATS          => self::PREFIX.'email_stats as operation_table LEFT JOIN '.self::PREFIX.'emails ON operation_table.email_id = '.self::PREFIX.'emails.id WHERE ('.self::PREFIX.'emails.is_published = 0 OR '.self::PREFIX.'emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR operation_table.email_id IS NULL) AND operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
        self::EMAIL_STATS_TOKENS   => self::PREFIX.'email_stats as operation_table '.self::SET.'WHERE operation_table.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
        self::EMAIL_STATS_DEVICES  => self::PREFIX.'email_stats_devices as operation_table LEFT JOIN '.self::PREFIX.'email_stats ON '.self::PREFIX.'email_stats.id = operation_table.stat_id WHERE '.self::PREFIX.'email_stats.id IS NULL OR '.self::PREFIX.'email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
    ];

    private array $update = [
        self::EMAIL_STATS_TOKENS => 'SET tokens = NULL ',
    ];

    private array $params = [
        self::CAMPAIGN_LEAD_EVENTS => [
            'daysOld' => self::DEFAULT_DAYS,
        ],
        self::LEAD_EVENTS          => [
            'daysOld' => self::DEFAULT_DAYS,
        ],
        self::EMAIL_STATS          => [
            'daysOld' => self::DEFAULT_DAYS,
        ],
        self::EMAIL_STATS_TOKENS   => [
            'daysOld' => self::DEFAULT_DAYS,
        ],
        self::EMAIL_STATS_DEVICES  => [
            'daysOld' => self::DEFAULT_DAYS,
        ],
    ];

    private array $types = [
        self::CAMPAIGN_LEAD_EVENTS => [
            'daysOld' => \PDO::PARAM_INT,
        ],
        self::LEAD_EVENTS          => [
            'daysOld' => \PDO::PARAM_INT,
        ],
        self::EMAIL_STATS          => [
            'daysOld' => \PDO::PARAM_INT,
        ],
        self::EMAIL_STATS_TOKENS   => [
            'daysOld' => \PDO::PARAM_INT,
        ],
        self::EMAIL_STATS_DEVICES  => [
            'daysOld' => \PDO::PARAM_INT,
        ],
    ];

    private string $dryRunMessage       = ' This is a dry run.';
    private string $dryRunDeleteMessage = ' rows would have been deleted.';
    private string $runDeleteMessage    = ' rows have been deleted.';
    private string $dryRunUpdateMessage = ' will be set to NULL.';
    private string $runUpdateMessage    = ' have been set to NULL.';

    public function __construct(Connection $connection, ?string $dbPrefix, Config $config)
    {
        $this->connection = $connection;
        $this->dbPrefix   = $dbPrefix ?? '';
        $this->config     = $config;
    }

    /**
     * @param non-empty-array<string, bool> $operations
     */
    public function deleteEventLogEntries(int $daysOld, ?int $campaignId, bool $dryRun, array $operations, OutputInterface $output): string
    {
        if (!$this->config->isPublished()) {
            return 'Housekeeping by Leuchtfeuer is currently not enabled. To use it, please enable the plugin in your Mautic plugin management.';
        }

        if (self::DEFAULT_DAYS !== $daysOld) {
            foreach ($this->params as $index => $item) {
                $this->params[$index]['daysOld'] = $daysOld;
            }
        }

        if (null !== $campaignId && $operations[self::CAMPAIGN_LEAD_EVENTS]) {
            $this->params[self::CAMPAIGN_LEAD_EVENTS][':cmpId'] = $campaignId;
            $this->types[self::CAMPAIGN_LEAD_EVENTS][':cmpId']  = \PDO::PARAM_INT;
            $this->queriesTemplate[self::CAMPAIGN_LEAD_EVENTS] .= ' AND campaign_id = :cmpId';
        }

        if (array_key_exists(self::EMAIL_STATS, $operations) && true === $operations[self::EMAIL_STATS]) {
            unset($operations[self::EMAIL_STATS]); // this is needed to get a proper order of executed queries.
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

                $result[$operation] = $this->getIntegerValue($operation, 'COUNT(1) as cnt', $output);
            }
        } else {
            foreach ($operations as $operation => $enabled) {
                if (false === $enabled) {
                    continue;
                }

                $result[$operation] = $this->applyChanges($operation, $output);
            }
        }

        try {
            $this->connection->commit();
        } catch (\Throwable $throwable) {
            $this->connection->rollBack();
            throw $throwable;
        }

        $operationsResult = [
            'delete' => [],
            'update' => [],
        ];
        foreach ($operations as $operation => $enabled) {
            if (false === $enabled) {
                continue;
            }

            if (array_key_exists($operation, $this->update)) {
                $operationsResult['update'][$operation] = $result[$operation];
            } else {
                $operationsResult['delete'][$operation] = $result[$operation];
            }
        }

        $message = $this->generateMessage($operationsResult['delete'], $dryRun ? $this->dryRunDeleteMessage : $this->runDeleteMessage);
        if ('' !== $updateMessage = $this->generateMessage($operationsResult['update'], $dryRun ? $this->dryRunUpdateMessage : $this->runUpdateMessage)) {
            $message .= ' '.$updateMessage;
        }

        if ($dryRun) {
            $message .= $this->dryRunMessage;
        }

        return $message;
    }

    private function getIntegerValue(string $operation, string $select, OutputInterface $output): int
    {
        $queryTemplate = $this->queriesTemplate[$operation];
        $queryTemplate = str_replace(self::SET, '', $queryTemplate);

        $sql       = 'SELECT '.$select.' FROM '.str_replace(self::PREFIX, $this->dbPrefix, $queryTemplate);
        $statement = $this->connection->executeQuery($sql, $this->params[$operation], $this->types[$operation]);
        $result    = $statement->fetchOne();

        if ($output->isVerbose()) {
            $output->writeln($sql);
        }

        if (null !== $result && !is_numeric($result)) {
            throw new \RuntimeException('The query must return a numeric value. '.gettype($result).' returned.');
        }

        return (int) $result;
    }

    private function applyChanges(string $operation, OutputInterface $output): int
    {
        $step  = 5000;
        $minId = $this->getIntegerValue($operation, 'MIN(operation_table.id) as minId', $output);

        if (0 === $minId && $output->isVerbose()) {
            $output->writeln('<info>Seems like there are no queries to remove.</info>');

            return 0;
        }

        $maxId = $this->getIntegerValue($operation, 'MAX(operation_table.id) as maxId', $output);

        if ($minId > $maxId) {
            throw new \RuntimeException('Something is wrong. MinId is greater than MaxId.');
        }

        $queryTemplate = $this->queriesTemplate[$operation];
        if (array_key_exists($operation, $this->update)) {
            $queryTemplate = str_replace(self::SET, $this->update[$operation], $queryTemplate);
            $sql           = 'UPDATE '.str_replace(self::PREFIX, $this->dbPrefix, $queryTemplate);
        } else {
            $sql = 'DELETE operation_table FROM '.str_replace(self::PREFIX, $this->dbPrefix, $queryTemplate);
        }

        $parameters     = $this->params[$operation];
        $types          = $this->types[$operation];
        $types['minId'] = \PDO::PARAM_INT;
        $types['maxId'] = \PDO::PARAM_INT;

        $sql .= ' AND :minId <= operation_table.id AND operation_table.id <= :maxId';

        $affectedRows = 0;
        $processed    = 0;
        while ($minId <= $maxId) {
            $parameters['minId'] = $minId;
            $parameters['maxId'] = min($maxId, $minId + $step);

            $affectedRows += $this->connection->executeStatement($sql, $parameters, $types);

            if ($output->isVerbose()) {
                $output->writeln($sql);
            }

            $minId += $step;
            $processed += $step;

            if ($processed > 100000) {
                try {
                    $this->connection->commit();
                    $this->connection->beginTransaction();
                } catch (\Throwable $throwable) {
                    $this->connection->rollBack();
                    throw $throwable;
                }
            }
        }

        return $affectedRows;
    }

    /**
     * @param non-empty-array<string, int> $result
     */
    private function generateMessage(array $result, string $postfix): string
    {
        $message       = '';
        $lastOperation = array_key_last($result);
        foreach ($result as $operation => $resultCount) {
            if ('' !== $message) {
                if ($lastOperation === $operation) {
                    $message .= ' and ';
                } else {
                    $message .= ', ';
                }
            }

            $message .= $resultCount.' '.$operation;
        }

        if ('' === $message) {
            return '';
        }

        return $message.$postfix;
    }
}
