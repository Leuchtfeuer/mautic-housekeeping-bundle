<?php

declare(strict_types=1);

namespace MauticPlugin\MauticHousekeepingBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EventLogCleanupCommand extends Command
{
    public const DEFAULT_DAYS = 365;

    private ManagerRegistry $doctrine;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;

        parent::__construct(null);
    }

    protected function configure(): void
    {
        $this->setName('mautic:leuchtfeuer:housekeeping')
            ->setDescription('EventLog Cleanup Command')
            ->setDefinition(
                [
                    new InputOption(
                        'days-old',
                        'd',
                        InputOption::VALUE_OPTIONAL,
                        'Purge records older than this number of days. Defaults to 365.',
                        self::DEFAULT_DAYS
                    ),
                    new InputOption('dry-run', 'r', InputOption::VALUE_NONE, 'Do a dry run without actually deleting anything.'),
                    new InputOption('campaign-lead', 'c', InputOption::VALUE_NONE, 'Purge only Campaign Lead Event Log Records'),
                    new InputOption('lead', 'l', InputOption::VALUE_NONE, 'Purge only Lead Event Log Records'),
                    new InputOption('cmp-id', 'i', InputOption::VALUE_OPTIONAL, 'Delete only Log Records for a specific CampaignID', 'none'),
                ]
            )
            ->setHelp(
                <<<'EOT'
                The <info>%command.name%</info> command is used to clean up the CampaignLeadEventLog and LeadEventLog table.

                <info>php %command.full_name%</info>
                
                Specify the number of days old data should be before purging:
                <info>php %command.full_name% --days-old=365</info>
                
                You can also optionally specify a dry run without deleting any records:
                <info>php %command.full_name% --days-old=365 --dry-run</info>
                
                You can also optionally specify for which campaign the entries should be purged:
                <info>php %command.full_name% --cmp-id=123</info> 
                
                Purge only Campaign Lead Event Log Records:
                <info>php %command.full_name% --campaign-lead </info> 
                
                Purge only Lead Event Log Records
                <info>php %command.full_name% --lead</info> 
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $daysOld                              = (int) $input->getOption('days-old');
        $dryRun                               = $input->hasOption('dry-run');
        $onlyCampaignLeadEventLogRecords      = $input->hasOption('campaign-lead');
        $onlyLeadEventRecords                 = $input->hasOption('lead');
        $campaignId                           = 'none' === $input->getOption('cmp-id') ? null : (int) $input->getOption('cmp-id');

        try {
            $deletedRows = $this->deleteCmpLeadEventLogEntries($daysOld, $campaignId, $dryRun, $onlyCampaignLeadEventLogRecords, $onlyLeadEventRecords);
        } catch (Exception $e) {
            $output->writeln(sprintf('<error>Deletion of CampaignLeadEventLog Rows failed because of database error: %s</error>', $e->getMessage()));

            return 1;
        }

        if ($dryRun) {
            if ((!$onlyCampaignLeadEventLogRecords && !$onlyLeadEventRecords) || ($onlyCampaignLeadEventLogRecords && $onlyLeadEventRecords)) {
                $output->writeln(sprintf('<info>%s CampaignLeadEventLog and %s LeadEventLog Rows would have been deleted. This is a dry run.</info>', $deletedRows['campaignLeadEventsCount'], $deletedRows['leadEventsCount']));
            } elseif ($onlyCampaignLeadEventLogRecords) {
                $output->writeln(sprintf('<info>%s CampaignLeadEventLog Rows would have been deleted. This is a dry run.</info>', $deletedRows['campaignLeadEventsCount']));
            } else {
                $output->writeln(sprintf('<info>%s LeadEventLog Rows would have been deleted. This is a dry run.</info>', $deletedRows['leadEventsCount']));
            }
        } else {
            if ((!$onlyCampaignLeadEventLogRecords && !$onlyLeadEventRecords) || ($onlyCampaignLeadEventLogRecords && $onlyLeadEventRecords)) {
                $output->writeln(sprintf('<info>%s CampaignLeadEventLog and %s LeadEventLog Rows have been deleted.</info>', $deletedRows['campaignLeadEventsCount'], $deletedRows['leadEventsCount']));
            } elseif ($onlyCampaignLeadEventLogRecords) {
                $output->writeln(sprintf('<info>%s CampaignLeadEventLog Rows have been deleted.</info>', $deletedRows['campaignLeadEventsCount']));
            } else {
                $output->writeln(sprintf('<info>%s LeadEventLog Rows have been deleted.</info>', $deletedRows['leadEventsCount']));
            }
        }

        return 0;
    }

    public function deleteCmpLeadEventLogEntries(int $daysOld, ?int $campaignId, bool $dryRun, bool $onlyCampaignLeadEventLogRecords, bool $onlyLeadEventRecords): array
    {
        $prefix = MAUTIC_TABLE_PREFIX;
        $em     = $this->doctrine->getManager();

        $leadEventsCount         = 0;
        $campaignLeadEventsCount = 0;

        $params = [
            ':daysOld' => (int) $daysOld,
            ':cmpId'   => (int) $campaignId,
        ];
        $types = [
            ':daysold' => \PDO::PARAM_INT,
            ':cmpId'   => \PDO::PARAM_INT,
        ];

        if ($dryRun) { //Only Select-Query in Dry-Run --> Return Number of Selected Rows
            if (null === $campaignId) {
                if ((!$onlyCampaignLeadEventLogRecords && !$onlyLeadEventRecords) || ($onlyCampaignLeadEventLogRecords && $onlyLeadEventRecords)) {
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt                    = $em->getConnection()->executeQuery($sql, $params, $types);
                    $campaignLeadEventsCount = $stmt->rowCount();
                    $sql                     = <<<SQL
                        SELECT * FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt            = $em->getConnection()->executeQuery($sql, $params, $types);
                    $leadEventsCount = $stmt->rowCount();
                } elseif ($onlyCampaignLeadEventLogRecords) {
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt                    = $em->getConnection()->executeQuery($sql, $params, $types);
                    $campaignLeadEventsCount = $stmt->rowCount();
                } else {
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt            = $em->getConnection()->executeQuery($sql, $params, $types);
                    $leadEventsCount = $stmt->rowCount();
                }
            } else {
                if ((!$onlyCampaignLeadEventLogRecords && !$onlyLeadEventRecords) || ($onlyCampaignLeadEventLogRecords && $onlyLeadEventRecords)) {
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND campaign_id = :cmpId
                        SQL;
                    $stmt                    = $em->getConnection()->executeQuery($sql, $params, $types);
                    $campaignLeadEventsCount = $stmt->rowCount();
                    $sql                     = <<<SQL
                        SELECT * FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt            = $em->getConnection()->executeQuery($sql, $params, $types);
                    $leadEventsCount = $stmt->rowCount();
                } elseif ($onlyCampaignLeadEventLogRecords) {
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND campaign_id = :cmpId
                        SQL;
                    $stmt                    = $em->getConnection()->executeQuery($sql, $params, $types);
                    $campaignLeadEventsCount = $stmt->rowCount();
                } else {
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt            = $em->getConnection()->executeQuery($sql, $params, $types);
                    $leadEventsCount = $stmt->rowCount();
                }
            }
        } else { // Execute without dryrun
            if (null === $campaignId) {
                if ((!$onlyCampaignLeadEventLogRecords && !$onlyLeadEventRecords) || ($onlyCampaignLeadEventLogRecords && $onlyLeadEventRecords)) {
                    $sql = <<<SQL
                        DELETE FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt                    = $em->getConnection()->executeQuery($sql, $params, $types);
                    $campaignLeadEventsCount = $stmt->rowCount();
                    $sql                     = <<<SQL
                        DELETE FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt            = $em->getConnection()->executeQuery($sql, $params, $types);
                    $leadEventsCount = $stmt->rowCount();
                } elseif ($onlyCampaignLeadEventLogRecords) {
                    $sql = <<<SQL
                        DELETE FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt                    = $em->getConnection()->executeQuery($sql, $params, $types);
                    $campaignLeadEventsCount = $stmt->rowCount();
                } else {
                    $sql = <<<SQL
                        DELETE FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt            = $em->getConnection()->executeQuery($sql, $params, $types);
                    $leadEventsCount = $stmt->rowCount();
                }
            } else {
                if ((!$onlyCampaignLeadEventLogRecords && !$onlyLeadEventRecords) || ($onlyCampaignLeadEventLogRecords && $onlyLeadEventRecords)) {
                    $sql = <<<SQL
                        DELETE FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND campaign_id = :cmpId
                        SQL;
                    $stmt                    = $em->getConnection()->executeQuery($sql, $params, $types);
                    $campaignLeadEventsCount = $stmt->rowCount();
                    $sql                     = <<<SQL
                        DELETE FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt            = $em->getConnection()->executeQuery($sql, $params, $types);
                    $leadEventsCount = $stmt->rowCount();
                } elseif ($onlyCampaignLeadEventLogRecords) {
                    $sql = <<<SQL
                        DELETE FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND campaign_id = :cmpId
                        SQL;
                    $stmt                    = $em->getConnection()->executeQuery($sql, $params, $types);
                    $campaignLeadEventsCount = $stmt->rowCount();
                } else {
                    $sql = <<<SQL
                        DELETE FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt            = $em->getConnection()->executeQuery($sql, $params, $types);
                    $leadEventsCount = $stmt->rowCount();
                }
            }
        }

        return [
            'campaignLeadEventsCount' => $campaignLeadEventsCount,
            'leadEventsCount'         => $leadEventsCount,
        ];
    }
}
