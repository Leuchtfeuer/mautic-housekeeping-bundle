<?php

namespace MauticPlugin\MauticHousekeepingBundle\Command;

use Doctrine\DBAL\DBALException;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EventLogCleanupCommand extends ContainerAwareCommand
{
    const DEFAULT_DAYS = 365;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('mautic:eventlog:delete')
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
                
                You can also otionally specify for which campaign  the entries should be purged:
                <info>php %command.full_name% --cmp-id=123</info> 
                
                Purge only Campaign Lead Event Log Records:
                <info>php %command.full_name% --campaign-lead </info> 
                
                Purge only Lead Event Log Records
                <info>php %command.full_name% --lead</info> 
                EOT
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        try {
            $daysOld = $input->getOption('days-old');
            $dryRun = $input->getOption('dry-run');
            $cl = $input->getOption('campaign-lead');
            $l = $input->getOption('lead');
            $cmpId = $input->getOption('cmp-id');

            $deletedRows = $this->deleteCmpLeadEventLogEntries($daysOld, $cmpId, $dryRun, $cl, $l);

            if ($dryRun) {
                if ((!$cl && !$l) || ($cl && $l)) {
                    $output->writeln(sprintf('<info>%s CampaignLeadEventLog and %s LeadEventLog Rows would have been deleted. This is a dry run.</info>', $deletedRows['clCount'], $deletedRows['lCount']));
                } elseif ($cl) {
                    $output->writeln(sprintf('<info>%s CampaignLeadEventLog Rows would have been deleted. This is a dry run.</info>', $deletedRows['clCount']));
                } else {
                    $output->writeln(sprintf('<info>%s LeadEventLog Rows would have been deleted. This is a dry run.</info>', $deletedRows['lCount']));
                }
            } else {
                if ((!$cl && !$l) || ($cl && $l)) {
                    $output->writeln(sprintf('<info>%s CampaignLeadEventLog and %s LeadEventLog Rows have been deleted.</info>', $deletedRows['clCount'], $deletedRows['lCount']));
                } elseif ($cl) {
                    $output->writeln(sprintf('<info>%s CampaignLeadEventLog Rows have been deleted.</info>', $deletedRows['clCount']));
                } else {
                    $output->writeln(sprintf('<info>%s LeadEventLog Rows have been deleted.</info>', $deletedRows['lCount']));
                }
            }

        } catch (DBALException $e) {

            $output->writeln(sprintf('<error>Deletion of CampaignLeadEventLog Rows failed because of database error: %s</error>', $e->getMessage()));

            return 1;
        }

        return 0;
    }


    public function deleteCmpLeadEventLogEntries($daysOld, $cmpId, $dryRun, $cl, $l): array
    {
        $prefix = MAUTIC_TABLE_PREFIX;
        $em = $this->getContainer()->get('doctrine')->getManager();

        $lCount = 0;
        $clCount = 0;

        $params = [
            ':daysOld' => (int)$daysOld,
            ':cmpId' => (int)$cmpId
        ];
        $types = [
            ':daysold' => \PDO::PARAM_INT,
            ':cmpId' => \PDO::PARAM_INT,
        ];


        if ($dryRun) { //Only Select-Query in Dry-Run --> Return Number of Selected Rows


            if ($cmpId === 'none') {
                if ((!$cl && !$l) || ($cl && $l)) {
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $clCount = $stmt->rowCount();
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $lCount = $stmt->rowCount();
                } elseif ($cl) {
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $clCount = $stmt->rowCount();
                } else {
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $lCount = $stmt->rowCount();
                }

            } else {
                if ((!$cl && !$l) || ($cl && $l)) {
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND campaign_id = :cmpId
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $clCount = $stmt->rowCount();
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $lCount = $stmt->rowCount();
                } elseif ($cl) {
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND campaign_id = :cmpId
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $clCount = $stmt->rowCount();
                } else {
                    $sql = <<<SQL
                        SELECT * FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $lCount = $stmt->rowCount();
                }
            }
        } else { // Execute without dryrun
            if ($cmpId === 'none') {
                if ((!$cl && !$l) || ($cl && $l)) {
                    $sql = <<<SQL
                        DELETE FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $clCount = $stmt->rowCount();
                    $sql = <<<SQL
                        DELETE FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $lCount = $stmt->rowCount();
                } elseif ($cl) {
                    $sql = <<<SQL
                        DELETE FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $clCount = $stmt->rowCount();
                } else {
                    $sql = <<<SQL
                        DELETE FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $lCount = $stmt->rowCount();
                }

            } else {
                if ((!$cl && !$l) || ($cl && $l)) {
                    $sql = <<<SQL
                        DELETE FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND campaign_id = :cmpId
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $clCount = $stmt->rowCount();
                    $sql = <<<SQL
                        DELETE FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $lCount = $stmt->rowCount();
                } elseif ($cl) {
                    $sql = <<<SQL
                        DELETE FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND campaign_id = :cmpId
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $clCount = $stmt->rowCount();
                } else {
                    $sql = <<<SQL
                        DELETE FROM {$prefix}lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) 
                        SQL;
                    $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
                    $lCount = $stmt->rowCount();
                }
            }
        }


        return array(
            'clCount' => $clCount,
            'lCount' => $lCount,
        );
    }


}

