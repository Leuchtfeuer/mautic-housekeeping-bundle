<?php

declare(strict_types=1);

namespace MauticPlugin\MauticHousekeepingBundle\Command;

use MauticPlugin\MauticHousekeepingBundle\Service\EventLogCleanup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class EventLogCleanupCommand extends Command
{
    protected static $defaultName = 'mautic:leuchtfeuer:housekeeping';

    private const DEFAULT_DAYS = 365;

    private EventLogCleanup $eventLogCleanup;

    public function __construct(EventLogCleanup $eventLogCleanup)
    {
        $this->eventLogCleanup = $eventLogCleanup;

        parent::__construct(null);
    }

    protected function configure(): void
    {
        $this->setName(self::$defaultName)
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
                    new InputOption('email-stats', 'm', InputOption::VALUE_NONE, 'Purge only Email Stats Records + Email Stats Devices'),
                    new InputOption('email-stats-tokens', 't', InputOption::VALUE_NONE, 'Set tokens field in email_stats to NULL. Important: This one will not be executed, if the option flag -t (or email-stats-tokens) is not set in the command. And: This option can not be combined with any -c, -l or -m in one command at the moment.'),
                    new InputOption('cmp-id', 'i', InputOption::VALUE_OPTIONAL, 'Delete only campaign_lead_eventLog for a specific CampaignID', 'none'),
                ]
            )
            ->setHelp(
                <<<'EOT'
                The <info>%command.name%</info> command is used to clean up the campaign_lead_event_log, lead_event_log, email_stats and email_stats_devices table or if the option flag -t is set, just set the content of the field tokens in email_stats to NULL.

                <info>php %command.full_name%</info>
                
                Specify the number of days old data should be before purging:
                <info>php %command.full_name% --days-old=365</info>
                
                You can also optionally specify a dry run without deleting any records:
                <info>php %command.full_name% --days-old=365 --dry-run</info>
                
                You can also optionally specify for which campaign the entries should be purged from campaign_lead_event_log:
                <info>php %command.full_name% --cmp-id=123</info> 
                
                Purge only campaign_lead_event_log records:
                <info>php %command.full_name% --campaign-lead </info> 
                
                Purge only lead_event_log records
                <info>php %command.full_name% --lead</info> 
                
                Purge only email_stats and email_stats_devices records:
                <info>php %command.full_name% --email-stats</info>
                
                Set tokens field in email_stats to NULL:
                <info>php %command.full_name% --email-stats-tokens</info> 
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $daysOld                              = (int) $input->getOption('days-old');
        $dryRun                               = $input->getOption('dry-run');
        $campaignId                           = 'none' === $input->getOption('cmp-id') ? null : (int) $input->getOption('cmp-id');
        $operations                           = [
            EventLogCleanup::CAMPAIGN_LEAD_EVENTS => $input->getOption('campaign-lead'),
            EventLogCleanup::LEAD_EVENTS          => $input->getOption('lead'),
            EventLogCleanup::EMAIL_STATS          => $input->getOption('email-stats'),
            EventLogCleanup::EMAIL_STATS_TOKENS   => $input->getOption('email-stats-tokens')
        ];

        if (0 === array_sum($operations)) {
            $operations = array_combine(array_keys($operations), array_fill(0, count($operations), true));
            array_pop($operations);
            $operations["email_stats_tokens"] = false;
        }

        try {
            $message = $this->eventLogCleanup->deleteEventLogEntries(
                $daysOld,
                $campaignId,
                $dryRun,
                $operations,
                $output
            );
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>Deletion of Log Rows failed because of database error: %s</error>', $e->getMessage()));

            return 1;
        }

        $output->writeln('<info>'.$message.'<info>');

        return 0;
    }
}