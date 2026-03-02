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
    public const AGGREGATE_REDIRECTS  = 'aggregate_redirects';

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
                    $sql           = 'UPDATE LOW_PRIORITY '.str_replace(self::PREFIX, $this->dbPrefix, $queryTemplate);
                } else {
                    $sql = 'DELETE LOW_PRIORITY '.$this->dbPrefix.$operation.' FROM '.str_replace(self::PREFIX, $this->dbPrefix, $queryTemplate);
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
     * @param array<string, int> $result
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

    public function aggregateRedirects(bool $dryRun, OutputInterface $output): string
    {
        if (!$this->config->isPublished()) {
            return 'Housekeeping by Leuchtfeuer is currently not enabled. To use it, please enable the plugin in your Mautic plugin management.';
        }

        $p = $this->dbPrefix;

        // Phase A: Find duplicate groups (scoped per url + channel + channel_id)
        $findDuplicatesSql = 'SELECT pr.url, cut.channel, cut.channel_id, '
            .'MIN(pr.id) as winner_id, '
            .'GROUP_CONCAT(pr.id ORDER BY pr.id) as all_ids, '
            .'COUNT(*) as duplicate_count '
            .'FROM '.$p.'page_redirects pr '
            .'INNER JOIN '.$p.'channel_url_trackables cut ON cut.redirect_id = pr.id '
            .'GROUP BY pr.url, cut.channel, cut.channel_id '
            .'HAVING COUNT(*) > 1';

        if ($output->isVerbose()) {
            $output->writeln($findDuplicatesSql);
        }

        $duplicateGroups = $this->connection->executeQuery($findDuplicatesSql)->fetchAllAssociative();

        $totalGroups = count($duplicateGroups);
        $totalDuplicateRedirects = 0;
        foreach ($duplicateGroups as $group) {
            $allIds = array_map('intval', explode(',', (string) $group['all_ids']));
            $totalDuplicateRedirects += count($allIds) - 1;
        }

        // Phase B: Count orphan hits (page_hits referencing redirects without trackable entries)
        $orphanCountSql = 'SELECT COUNT(*) FROM '.$p.'page_hits ph '
            .'INNER JOIN '.$p.'page_redirects orphan ON ph.redirect_id = orphan.id '
            .'LEFT JOIN '.$p.'channel_url_trackables cut ON cut.redirect_id = orphan.id '
            .'INNER JOIN '.$p.'page_redirects winner ON winner.url = orphan.url AND winner.id != orphan.id '
            .'INNER JOIN '.$p.'channel_url_trackables wcut ON wcut.redirect_id = winner.id '
            ."AND wcut.channel = 'email' AND wcut.channel_id = ph.email_id "
            .'WHERE cut.redirect_id IS NULL';

        if ($output->isVerbose()) {
            $output->writeln($orphanCountSql);
        }

        $orphanHitCount = (int) $this->connection->executeQuery($orphanCountSql)->fetchOne();

        // Dry run: report counts only
        if ($dryRun) {
            if (0 === $totalGroups && 0 === $orphanHitCount) {
                return 'No duplicate redirects or orphan hits found. This is a dry run.';
            }

            $parts = [];
            if ($totalGroups > 0) {
                $parts[] = $totalGroups.' duplicate redirect groups ('.$totalDuplicateRedirects.' duplicate entries to consolidate)';
            }
            if ($orphanHitCount > 0) {
                $parts[] = $orphanHitCount.' orphan hits to reassign';
            }

            return 'Found '.implode(' and ', $parts).'. This is a dry run.';
        }

        // Real run: nothing to do
        if (0 === $totalGroups && 0 === $orphanHitCount) {
            return 'No duplicate redirects or orphan hits found. Nothing to do.';
        }

        $this->connection->beginTransaction();

        try {
            $mergedGroups = 0;

            // Phase A: Merge each duplicate group
            foreach ($duplicateGroups as $group) {
                $winnerId = (int) $group['winner_id'];
                $allIds = array_map('intval', explode(',', (string) $group['all_ids']));
                $loserIds = array_values(array_filter($allIds, static fn (int $id): bool => $id !== $winnerId));
                $channel = (string) $group['channel'];
                $channelId = (int) $group['channel_id'];

                if (empty($loserIds)) {
                    continue;
                }

                // Step 1: Move page_hits from losers to winner
                $this->connection->executeQuery(
                    'UPDATE '.$p.'page_hits SET redirect_id = :winnerId WHERE redirect_id IN (:loserIds)',
                    ['winnerId' => $winnerId, 'loserIds' => $loserIds],
                    ['winnerId' => \PDO::PARAM_INT, 'loserIds' => Connection::PARAM_INT_ARRAY]
                );

                // Step 2: Sum trackable stats from losers
                $trackableStats = $this->connection->executeQuery(
                    'SELECT COALESCE(SUM(hits), 0) as total_hits, COALESCE(SUM(unique_hits), 0) as total_unique_hits '
                        .'FROM '.$p.'channel_url_trackables '
                        .'WHERE redirect_id IN (:loserIds) AND channel = :channel AND channel_id = :channelId',
                    ['loserIds' => $loserIds, 'channel' => $channel, 'channelId' => $channelId],
                    ['loserIds' => Connection::PARAM_INT_ARRAY, 'channel' => \PDO::PARAM_STR, 'channelId' => \PDO::PARAM_INT]
                )->fetchAssociative();

                // Add loser stats to winner's trackable entry
                $this->connection->executeQuery(
                    'UPDATE '.$p.'channel_url_trackables SET hits = hits + :addHits, unique_hits = unique_hits + :addUniqueHits '
                        .'WHERE redirect_id = :winnerId AND channel = :channel AND channel_id = :channelId',
                    [
                        'addHits'       => (int) $trackableStats['total_hits'],
                        'addUniqueHits' => (int) $trackableStats['total_unique_hits'],
                        'winnerId'      => $winnerId,
                        'channel'       => $channel,
                        'channelId'     => $channelId,
                    ],
                    [
                        'addHits'       => \PDO::PARAM_INT,
                        'addUniqueHits' => \PDO::PARAM_INT,
                        'winnerId'      => \PDO::PARAM_INT,
                        'channel'       => \PDO::PARAM_STR,
                        'channelId'     => \PDO::PARAM_INT,
                    ]
                );

                // Step 3: Delete loser trackable entries
                $this->connection->executeQuery(
                    'DELETE FROM '.$p.'channel_url_trackables WHERE redirect_id IN (:loserIds) AND channel = :channel AND channel_id = :channelId',
                    ['loserIds' => $loserIds, 'channel' => $channel, 'channelId' => $channelId],
                    ['loserIds' => Connection::PARAM_INT_ARRAY, 'channel' => \PDO::PARAM_STR, 'channelId' => \PDO::PARAM_INT]
                );

                // Step 4: Sum redirect stats from losers
                $redirectStats = $this->connection->executeQuery(
                    'SELECT COALESCE(SUM(hits), 0) as total_hits, COALESCE(SUM(unique_hits), 0) as total_unique_hits '
                        .'FROM '.$p.'page_redirects WHERE id IN (:loserIds)',
                    ['loserIds' => $loserIds],
                    ['loserIds' => Connection::PARAM_INT_ARRAY]
                )->fetchAssociative();

                // Add loser stats to winner's redirect entry
                $this->connection->executeQuery(
                    'UPDATE '.$p.'page_redirects SET hits = hits + :addHits, unique_hits = unique_hits + :addUniqueHits WHERE id = :winnerId',
                    [
                        'addHits'       => (int) $redirectStats['total_hits'],
                        'addUniqueHits' => (int) $redirectStats['total_unique_hits'],
                        'winnerId'      => $winnerId,
                    ],
                    [
                        'addHits'       => \PDO::PARAM_INT,
                        'addUniqueHits' => \PDO::PARAM_INT,
                        'winnerId'      => \PDO::PARAM_INT,
                    ]
                );

                // Step 5: Zero out loser redirect stats (cosmetic, redirects still work)
                $this->connection->executeQuery(
                    'UPDATE '.$p.'page_redirects SET hits = 0, unique_hits = 0 WHERE id IN (:loserIds)',
                    ['loserIds' => $loserIds],
                    ['loserIds' => Connection::PARAM_INT_ARRAY]
                );

                ++$mergedGroups;
            }

            // Phase B: Orphan hit cleanup
            $movedHits = 0;
            if ($orphanHitCount > 0) {
                // Collect orphan redirect IDs before reassignment (to zero out stale counters later)
                $orphanRedirectIds = $this->connection->executeQuery(
                    'SELECT DISTINCT orphan.id '
                        .'FROM '.$p.'page_hits ph '
                        .'INNER JOIN '.$p.'page_redirects orphan ON ph.redirect_id = orphan.id '
                        .'LEFT JOIN '.$p.'channel_url_trackables cut ON cut.redirect_id = orphan.id '
                        .'INNER JOIN '.$p.'page_redirects winner ON winner.url = orphan.url AND winner.id != orphan.id '
                        .'INNER JOIN '.$p.'channel_url_trackables wcut ON wcut.redirect_id = winner.id '
                        ."AND wcut.channel = 'email' AND wcut.channel_id = ph.email_id "
                        .'WHERE cut.redirect_id IS NULL'
                )->fetchFirstColumn();

                // Get orphan hit stats grouped by winner (before reassignment)
                $orphanStats = $this->connection->executeQuery(
                    'SELECT winner.id as winner_id, wcut.channel, wcut.channel_id, '
                        .'COUNT(*) as hit_count, COUNT(DISTINCT ph.lead_id) as unique_count '
                        .'FROM '.$p.'page_hits ph '
                        .'INNER JOIN '.$p.'page_redirects orphan ON ph.redirect_id = orphan.id '
                        .'LEFT JOIN '.$p.'channel_url_trackables cut ON cut.redirect_id = orphan.id '
                        .'INNER JOIN '.$p.'page_redirects winner ON winner.url = orphan.url AND winner.id != orphan.id '
                        .'INNER JOIN '.$p.'channel_url_trackables wcut ON wcut.redirect_id = winner.id '
                        ."AND wcut.channel = 'email' AND wcut.channel_id = ph.email_id "
                        .'WHERE cut.redirect_id IS NULL '
                        .'GROUP BY winner.id, wcut.channel, wcut.channel_id'
                )->fetchAllAssociative();

                // Reassign orphan hits to winners
                $result = $this->connection->executeQuery(
                    'UPDATE '.$p.'page_hits ph '
                        .'INNER JOIN '.$p.'page_redirects orphan ON ph.redirect_id = orphan.id '
                        .'LEFT JOIN '.$p.'channel_url_trackables cut ON cut.redirect_id = orphan.id '
                        .'INNER JOIN '.$p.'page_redirects winner ON winner.url = orphan.url AND winner.id != orphan.id '
                        .'INNER JOIN '.$p.'channel_url_trackables wcut ON wcut.redirect_id = winner.id '
                        ."AND wcut.channel = 'email' AND wcut.channel_id = ph.email_id "
                        .'SET ph.redirect_id = winner.id '
                        .'WHERE cut.redirect_id IS NULL'
                );
                $movedHits = $result->rowCount();

                // Update winner stats for moved orphan hits
                foreach ($orphanStats as $stat) {
                    $this->connection->executeQuery(
                        'UPDATE '.$p.'channel_url_trackables SET hits = hits + :addHits, unique_hits = unique_hits + :addUniqueHits '
                            .'WHERE redirect_id = :winnerId AND channel = :channel AND channel_id = :channelId',
                        [
                            'addHits'       => (int) $stat['hit_count'],
                            'addUniqueHits' => (int) $stat['unique_count'],
                            'winnerId'      => (int) $stat['winner_id'],
                            'channel'       => $stat['channel'],
                            'channelId'     => (int) $stat['channel_id'],
                        ],
                        [
                            'addHits'       => \PDO::PARAM_INT,
                            'addUniqueHits' => \PDO::PARAM_INT,
                            'winnerId'      => \PDO::PARAM_INT,
                            'channel'       => \PDO::PARAM_STR,
                            'channelId'     => \PDO::PARAM_INT,
                        ]
                    );

                    $this->connection->executeQuery(
                        'UPDATE '.$p.'page_redirects SET hits = hits + :addHits, unique_hits = unique_hits + :addUniqueHits WHERE id = :winnerId',
                        [
                            'addHits'       => (int) $stat['hit_count'],
                            'addUniqueHits' => (int) $stat['unique_count'],
                            'winnerId'      => (int) $stat['winner_id'],
                        ],
                        [
                            'addHits'       => \PDO::PARAM_INT,
                            'addUniqueHits' => \PDO::PARAM_INT,
                            'winnerId'      => \PDO::PARAM_INT,
                        ]
                    );
                }

                // Zero out stale counters on orphan redirects
                if (!empty($orphanRedirectIds)) {
                    $this->connection->executeQuery(
                        'UPDATE '.$p.'page_redirects SET hits = 0, unique_hits = 0 WHERE id IN (:ids)',
                        ['ids' => array_map('intval', $orphanRedirectIds)],
                        ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
                    );
                }
            }

            $this->connection->commit();
        } catch (\Throwable $throwable) {
            $this->connection->rollBack();
            throw $throwable;
        }

        $message = 'Aggregated '.$mergedGroups.' duplicate redirect groups ('.$totalDuplicateRedirects.' duplicate entries consolidated).';
        if ($movedHits > 0) {
            $message .= ' Reassigned '.$movedHits.' orphan hits.';
        }

        return $message;
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

    /**
     * @return array<string>
     */
    private function getAllTables(): array
    {
        $sql       = 'SHOW TABLES';
        $statement = $this->connection->executeQuery($sql);

        return $statement->fetchFirstColumn();
    }
}
