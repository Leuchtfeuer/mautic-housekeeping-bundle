<?php

namespace MauticPlugin\MauticHousekeepingBundle\Command;

use Doctrine\DBAL\DBALException;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CmpLeadEventLogCleanupCommand extends ContainerAwareCommand
{
    const DEFAULT_DAYS = 365;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('mautic:campaignleadeventlog:delete')
            ->setDescription('CampaignLeadEventLog Cleanup Command')
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
                    new InputOption('cmp-id', 'c', InputOption::VALUE_OPTIONAL, 'Delete only Log Data for specific CampaignID', 'none'),
                ]
            )
            ->setHelp(
                <<<'EOT'
                The <info>%command.name%</info> command is used to clean up the CampaignLeadEventLog table.

                <info>php %command.full_name%</info>
                
                Specify the number of days old data should be before purging.
                
                <info>php %command.full_name% --days-old=365</info>
                
                You can also optionally specify a dry run without deleting any records:
                
                <info>php %command.full_name% --days-old=365 --dry-run</info>

                EOT
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        try {
            $daysOld       = $input->getOption('days-old');
            $dryRun        = $input->getOption('dry-run');
            $cmpdId = $input->getOption('cmp-id');

            $deletedRows = $this->deleteCmpLeadEventLogEntries($daysOld,  $cmpdId, $dryRun);

            if ($dryRun) {
                $output->writeln(sprintf('<info>%s CampaignLeadEventLog Rows would have been deleted. This is a dry run.</info>', $deletedRows));
            } else {
                $output->writeln(sprintf('<info>%s CampaignLeadEventLog Rows have been deleted</info>', $deletedRows));
            }

        } catch (DBALException $e) {

            $output->writeln(sprintf('<error>Deletion of CampaignLeadEventLog Rows failed because of database error: %s</error>', $e->getMessage()));

            return 1;
        }

        return 0;
    }

    public function deleteCmpLeadEventLogEntries($daysOld, $cmpId, $dryRun): int
    {
        $prefix = MAUTIC_TABLE_PREFIX;
        $em = $this->getContainer()->get('doctrine')->getManager();

        if ($dryRun) { //Only Select in Dry-Run --> Return Number of Selected Rows
            if ($cmpId == 'none') {
                $sql = <<<SQL
            SELECT * FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)
SQL;
            } else {
                $sql = <<<SQL
            SELECT * FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)
            AND campaign_id = :cmpId
SQL;

            }
        } else { // Execute if Dry-Run Option i

            if ($cmpId == 'none') {
                $sql = <<<SQL
            DELETE FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)
SQL;
            } else {
                $sql = <<<SQL
            DELETE FROM {$prefix}campaign_lead_event_log WHERE date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)
            AND campaign_id = :cmpId
SQL;

            }
        }



        $params = [
            ':daysOld' => (int) $daysOld,
            ':cmpId'   => (int) $cmpId
        ];
        $types  = [
            ':daysold' => \PDO::PARAM_INT,
            ':cmpId' => \PDO::PARAM_INT,
        ];
        $stmt   = $em->getConnection()->executeQuery($sql, $params, $types);

        return $stmt->rowCount();
    }



}

