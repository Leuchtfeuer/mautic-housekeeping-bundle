<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Command;

use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Service\EventLogCleanup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class EventLogCleanupCommand extends Command
{
    public const    EMAIL_STATS_TOKENS      = 'email_stats_tokens';

    private Config $config;
    private string $dryRunMessage = ' rows would have been deleted in this turn. This is a dry run.';
    private string $runMessage    = ' rows have been deleted in this turn.';
    private string $dryRunMessageTokens = ' will be set to NULL in this turn. This is a dry run.';
    private string $runMessageTokens    = ' have been set to NULL in this turn.';

    protected static $defaultName = 'leuchtfeuer:housekeeping';

    private const DEFAULT_DAYS = 365;

    private EventLogCleanup $eventLogCleanup;

    public function __construct(EventLogCleanup $eventLogCleanup, Config $config)
    {
        $this->eventLogCleanup = $eventLogCleanup;
        $this->config          = $config;

        parent::__construct(null);
    }

    protected function configure(): void
    {
        $this->setName(self::$defaultName)
            ->setDescription('Database Cleanup Command to delete lead_event_log table entries, campaign_lead_event_log table entries, email_stats table entries where the referenced email entry is currently not published and email_stats_devices table entries.')
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
                    new InputOption('email-stats', 'm', InputOption::VALUE_NONE, 'Purge only Email Stats Records where the referenced email entry is currently not published and purge Email Stats Devices. Important: If referenced email is ever switched back to published, the contacts will get the email again.'),
                    new InputOption('email-stats-tokens', 't', InputOption::VALUE_NONE, 'Set only tokens fields in Email Stats Records to NULL. Important: This option can not be combined with any "-c", "-l" or "-m" flag in one command. And: If the option flag "-t" is not set, the NULL setting of tokens will not be done with the basis command, so if you just run mautic:leuchtfeuer:housekeeping without a flag)'),
                    new InputOption('cmp-id', 'i', InputOption::VALUE_OPTIONAL, 'Delete only campaign_lead_eventLog for a specific CampaignID', 'none'),
                    new InputOption('limit', 'b', InputOption::VALUE_OPTIONAL, 'Set the number of rows which will be deleted in one turn (the command will run until all entries are deleted).', 100000)
                ]
            )
            ->setHelp(
                <<<'EOT'
                The <info>%command.name%</info> command is used to clean up the campaign_lead_event_log table, the lead_event_log table, the email_stats table (but only email_stats entries where the referenced email entry is currently not published) and the email_stats_devices table or just clean up the field tokens in email_stats if the option flag "-t" is set.

                <info>php %command.full_name%</info>

                Specify the number of days old data should be before purging:
                <info>php %command.full_name% --days-old=365</info>

                You can also optionally specify a dry run without deleting any records:
                <info>php %command.full_name% --days-old=365 --dry-run</info>

                You can also optionally specify for which campaign the entries should be purged from campaign_lead_event_log:
                <info>php %command.full_name% --cmp-id=123</info>

                Purge only campaign_lead_event_log records:
                <info>php %command.full_name% --campaign-lead </info>

                Purge only lead_event_log records:
                <info>php %command.full_name% --lead</info>

                Purge only email_stats where the referenced email entry is currently not published and email_stats_devices records [Important: If referenced email is ever switched back to published, the contacts will get the email again]:
                <info>php %command.full_name% --email-stats</info>

                Set tokens field in email_stats to NULL:
                <info>php %command.full_name% --email-stats-tokens</info>

                Set the number of rows which will be deleted in one turn:
                <info>php %command.full_name% --limit=100000</info>
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isPublished()) {
            $message = 'Housekeeping by Leuchtfeuer is currently not enabled. To use it, please enable the plugin in your Mautic plugin management.';
            $output->writeln('<info>'.$message.'<info>');
            return 1;
        }

        $daysOld                              = (int) $input->getOption('days-old');
        $limit                                = (int) $input->getOption('limit');
        $dryRun                               = $input->getOption('dry-run');
        $campaignId                           = 'none' === $input->getOption('cmp-id') ? null : (int) $input->getOption('cmp-id');
        $operations                           = [
            EventLogCleanup::CAMPAIGN_LEAD_EVENTS => $input->getOption('campaign-lead'),
            EventLogCleanup::LEAD_EVENTS          => $input->getOption('lead'),
            EventLogCleanup::EMAIL_STATS          => $input->getOption('email-stats'),
            EventLogCleanup::EMAIL_STATS_TOKENS   => $input->getOption('email-stats-tokens'),
        ];
        $finished = false;
        $message = 'Nothing to delete.';

        if (0 === array_sum($operations)) {
            $operations                                      = array_combine(array_keys($operations), array_fill(0, count($operations), true));
            $operations[EventLogCleanup::EMAIL_STATS_TOKENS] = false;
        }

        if ((true === $operations[EventLogCleanup::EMAIL_STATS_TOKENS]) && (((true === $operations[EventLogCleanup::EMAIL_STATS]) || (true === $operations[EventLogCleanup::CAMPAIGN_LEAD_EVENTS])) || (true === $operations[EventLogCleanup::LEAD_EVENTS]))) {
            $output->writeln('<error>The combination of “-t” flag with either “-m” flag or “-c” flag or “-l” flag is not supported/possible. You can only combine the "-t" flag with "-d" flag and/or "-r" flag.</error>');
            return 1;
        }

        try {
            $result = $this->eventLogCleanup->deleteEventLogEntries(
                $daysOld,
                $limit,
                $campaignId,
                $dryRun,
                $operations,
                $output
            );
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>Deletion of Log Rows failed because of database error: %s</error>', $e->getMessage()));
            return 1;
        }

        if (true === $dryRun) {
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
                $message .= $this->dryRunMessageTokens;
            } else {
                $message .= $this->dryRunMessage;
            }
            $output->writeln('<info>'.$message.'<info>');
            return 0;
        }

        while (0 < array_sum($result) && false === $dryRun) {
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
                $message .= $this->runMessageTokens;
            } else {
                $message .= $this->runMessage;
            }

            $output->writeln('<info>'.$message.'<info>');
            $result = $this->eventLogCleanup->deleteEventLogEntries($daysOld, $limit, $campaignId, false, $operations, $output);
            $finished = true;
        }

        if (true === $finished && false === $dryRun) {
            $message = 'Deletion complete.';
            $output->writeln('<info>'.$message.'<info>');
            return 0;
        }

        $output->writeln('<info>'.$message.'<info>');

        return 0;
    }
}
