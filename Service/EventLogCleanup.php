<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Service;

use Doctrine\DBAL\Connection;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Config;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EventLogCleanup
{
    private const PREFIX = '%PREFIX%';
    private string $dbPrefix;

    /**
     * Constant used to indicate where the query can place "SET a = :a" when query is an update.
     */
    private const SET = '%SET%';

    public const DEFAULT_DAYS         = 365;
    public const CAMPAIGN_LEAD_EVENTS = 'campaign_lead_event_log';
    public const LEAD_EVENTS          = 'lead_event_log';
    public const EMAIL_STATS          = 'email_stats';
    public const EMAIL_STATS_TOKENS   = 'email_stats_tokens';
    private const EMAIL_STATS_DEVICES = 'email_stats_devices';
    public const PAGE_HITS            = 'page_hits';

    /**
     * @var array<string, string>
     */
    private array $queriesTemplate = [
        self::CAMPAIGN_LEAD_EVENTS => self::PREFIX.'campaign_lead_event_log WHERE ('.self::PREFIX.'campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM '.self::PREFIX.'campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND '.self::PREFIX.'campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
        self::LEAD_EVENTS          => self::PREFIX.'lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
        self::EMAIL_STATS          => self::PREFIX.'email_stats LEFT JOIN '.self::PREFIX.'emails ON '.self::PREFIX.'email_stats.email_id = '.self::PREFIX.'emails.id WHERE ('.self::PREFIX.'emails.is_published = 0 OR '.self::PREFIX.'emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR '.self::PREFIX.'email_stats.email_id IS NULL) AND '.self::PREFIX.'email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
        self::EMAIL_STATS_TOKENS   => self::PREFIX.'email_stats '.self::SET.' WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
        self::EMAIL_STATS_DEVICES  => self::PREFIX.'email_stats_devices WHERE '.self::PREFIX.'email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
        self::PAGE_HITS            => self::PREFIX.'page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
    ];

    /**
     * @var array<string, string>
     */
    private array $update = [
        self::EMAIL_STATS_TOKENS => 'SET tokens = NULL',
    ];

    /**
     * @var array<string, array<string, int>>
     */
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
        self::PAGE_HITS            => [
            'daysOld' => self::DEFAULT_DAYS,
        ],
    ];

    /**
     * @var array<string, array<string, int>>
     */
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
        self::PAGE_HITS            => [
            'daysOld' => \PDO::PARAM_INT,
        ],
    ];

    private string $dryRunMessage       = ' This is a dry run.';
    private string $dryRunDeleteMessage = ' rows would have been deleted.';
    private string $runDeleteMessage    = ' rows have been deleted.';
    private string $dryRunUpdateMessage = ' will be set to NULL.';
    private string $runUpdateMessage    = ' have been set to NULL.';

    public function __construct(private Connection $connection, ?string $dbPrefix, private Config $config, private LoggerInterface $logger)
    {
        $this->dbPrefix = $dbPrefix ?? '';
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
            $this->params[self::CAMPAIGN_LEAD_EVENTS]['cmpId'] = $campaignId;
            $this->types[self::CAMPAIGN_LEAD_EVENTS]['cmpId']  = \PDO::PARAM_INT;
            $this->queriesTemplate[self::CAMPAIGN_LEAD_EVENTS] .= ' AND campaign_id = :cmpId';
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
            self::PAGE_HITS            => 0,
        ];

        $this->connection->beginTransaction();

        if ($dryRun) {
            foreach ($operations as $operation => $enabled) {
                if (false === $enabled) {
                    continue;
                }

                $queryTemplate = $this->queriesTemplate[$operation];
                $queryTemplate = str_replace(self::SET, '', $queryTemplate);

                $sql                = 'SELECT COUNT(1) as cnt FROM '.str_replace(self::PREFIX, $this->dbPrefix, $queryTemplate);
                $statement          = $this->connection->executeQuery($sql, $this->params[$operation], $this->types[$operation]);
                $result[$operation] = $statement->fetchOne();

                if ($output->isVerbose()) {
                    $output->writeln($sql);
                }
            }
        } else {
            foreach ($operations as $operation => $enabled) {
                if (false === $enabled) {
                    continue;
                }

                $queryTemplate = $this->queriesTemplate[$operation];
                if (array_key_exists($operation, $this->update)) {
                    $queryTemplate = str_replace(self::SET, $this->update[$operation], $queryTemplate);
                    $sql           = 'UPDATE '.str_replace(self::PREFIX, $this->dbPrefix, $queryTemplate);
                } else {
                    $sql = 'DELETE '.$this->dbPrefix.$operation.' FROM '.str_replace(self::PREFIX, $this->dbPrefix, $queryTemplate);
                }

                $statement          = $this->connection->executeQuery($sql, $this->params[$operation], $this->types[$operation]);
                $result[$operation] = $statement->rowCount();

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

    public function optimizeTables(OutputInterface $output): string
    {
        try {
            $tables = $this->getAllTables();

            if (empty($tables)) {
                return 'No tables found to optimize.';
            }

            $tableList = '`'.implode('`, `', $tables).'`';
            $sql       = "OPTIMIZE TABLE {$tableList}";

            if ($output->isVerbose()) {
                $output->writeln('Optimizing '.count($tables).' tables...');
                $output->writeln($sql);
            }

            $statement = $this->connection->executeQuery($sql);
            $results   = $statement->fetchAllAssociative();

            return 'All tables have been optimized.';
        } catch (\Throwable $e) {
            $errorMsg = 'Table optimization failed: '.$e->getMessage();
            $this->logger->error($errorMsg);
            throw $e;
        }
    }

    private function getAllTables(): array
    {
        $sql       = 'SHOW TABLES';
        $statement = $this->connection->executeQuery($sql);

        return $statement->fetchFirstColumn();
    }
}
