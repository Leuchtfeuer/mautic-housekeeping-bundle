<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Functional;

use Mautic\CampaignBundle\Entity\LeadEventLog as CampaignLeadEventLog;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Stat as EmailStat;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\PageBundle\Entity\Hit;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Fixtures\FixtureHelper;
use PHPUnit\Framework\Assert;

class EventLogCleanupCommandTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    private FixtureHelper $fixtureHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureHelper = new FixtureHelper($this->em);
    }

    // ========== Plugin State Tests ==========

    public function testCommandReturnsMessageWhenPluginDisabled(): void
    {
        // Plugin is not enabled by default
        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old' => 30,
        ]);

        $output = $commandTester->getDisplay();
        Assert::assertStringContainsString('Housekeeping by Leuchtfeuer is currently not enabled', $output);
    }

    // ========== Default Behavior Tests ==========

    public function testDefaultBehaviorCleansAllTablesExceptTokens(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact  = $this->fixtureHelper->createContact('default@test.com');
        $campaign = $this->fixtureHelper->createCampaign('Test Campaign');

        // Create realistic campaign structure: condition event followed by action event
        $conditionEvent = $this->fixtureHelper->createCampaignEvent(
            $campaign,
            'Has valid email address',
            'email.validate.address',
            'condition'
        );
        $actionEvent = $this->fixtureHelper->createCampaignEvent(
            $campaign,
            'Send email',
            'email.send',
            'action',
            $conditionEvent
        );

        // Create old records (400 days old)
        $oldDate = $this->fixtureHelper->daysAgo(400);

        // Create multiple campaign logs for multiple events (no rotation, realistic scenario)
        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $conditionEvent, $oldDate);
        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $actionEvent, $oldDate);
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate);
        $this->fixtureHelper->createPageHit($contact, $oldDate);

        // Create email with unpublished status
        $email = $this->fixtureHelper->createEmail('Old Email', false);
        $stat  = $this->fixtureHelper->createEmailStat($contact, $email, $oldDate, ['token1' => 'value1']);
        $this->fixtureHelper->createEmailStatDevice($stat, $oldDate);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping');

        $this->em->clear();

        // campaign_lead_event_log should have only 1 remaining (the MAX(id) per lead+campaign is preserved)
        Assert::assertCount(1, $this->em->getRepository(CampaignLeadEventLog::class)->findBy(['lead' => $contact->getId()]));
        Assert::assertCount(0, $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]));
        Assert::assertCount(0, $this->em->getRepository(Hit::class)->findBy(['lead' => $contact->getId()]));

        // Email stats should be deleted for unpublished emails
        $emailStats = $this->em->getRepository(EmailStat::class)->findBy(['lead' => $contact->getId()]);
        Assert::assertCount(0, $emailStats);

        // Tokens should NOT be affected (default behavior excludes tokens)
        $output = $commandTester->getDisplay();
        Assert::assertStringNotContainsString('email_stats_tokens', $output);
    }

    public function testDaysOldDefaultIs365(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('days-old@test.com');

        // Create record at 364 days (should NOT be deleted with default 365)
        $recentDate = $this->fixtureHelper->daysAgo(364);
        $this->fixtureHelper->createLeadEventLog($contact, $recentDate);

        // Create record at 366 days (should be deleted with default 365)
        $oldDate = $this->fixtureHelper->daysAgo(366);
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', ['-l' => true]);

        $this->em->clear();

        // Only one record should remain (the 364 day old one)
        $logs = $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]);
        Assert::assertCount(1, $logs);
    }

    // ========== Days-Old Parameter Tests ==========

    public function testDaysOldParameterAffectsThreshold(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('threshold@test.com');

        // Create record at 25 days (should NOT be deleted with 30 day threshold)
        $recentDate = $this->fixtureHelper->daysAgo(25);
        $this->fixtureHelper->createLeadEventLog($contact, $recentDate);

        // Create record at 35 days (should be deleted with 30 day threshold)
        $oldDate = $this->fixtureHelper->daysAgo(35);
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old' => 30,
            '-l'         => true,
        ]);

        $this->em->clear();

        // Only one record should remain (the 25 day old one)
        $logs = $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]);
        Assert::assertCount(1, $logs);
    }

    // ========== Dry-Run Tests ==========

    public function testDryRunDoesNotDeleteRecords(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('dryrun@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        $this->fixtureHelper->createLeadEventLog($contact, $oldDate);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old' => 30,
            '-l'         => true,
            '--dry-run'  => true,
        ]);

        $this->em->clear();

        // Record should still exist
        $logs = $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]);
        Assert::assertCount(1, $logs);
    }

    public function testDryRunShowsCorrectCount(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('dryrun-count@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        // Create 3 records
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate);
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate);
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old' => 30,
            '-l'         => true,
            '--dry-run'  => true,
        ]);

        $output = $commandTester->getDisplay();
        Assert::assertStringContainsString('3 lead_event_log', $output);
        Assert::assertStringContainsString('would have been deleted', $output);
        Assert::assertStringContainsString('This is a dry run', $output);
    }

    // ========== Individual Table Flag Tests ==========

    public function testCampaignLeadFlagOnlyAffectsCampaignLeadEventLog(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact  = $this->fixtureHelper->createContact('campaign-only@test.com');
        $campaign = $this->fixtureHelper->createCampaign('Campaign Flag Test');
        $event    = $this->fixtureHelper->createCampaignEvent($campaign);
        $oldDate  = $this->fixtureHelper->daysAgo(400);

        // Create multiple campaign logs (the query preserves MAX(id) per lead+campaign)
        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $event, $oldDate, 1);
        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $event, $oldDate, 2);
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'      => 30,
            '--campaign-lead' => true,
        ]);

        $this->em->clear();

        // campaign_lead_event_log should have 1 remaining (the MAX(id) is preserved)
        Assert::assertCount(1, $this->em->getRepository(CampaignLeadEventLog::class)->findBy(['lead' => $contact->getId()]));

        // lead_event_log should still have record
        Assert::assertCount(1, $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]));
    }

    public function testLeadFlagOnlyAffectsLeadEventLog(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact  = $this->fixtureHelper->createContact('lead-only@test.com');
        $campaign = $this->fixtureHelper->createCampaign('Lead Flag Test');
        $event    = $this->fixtureHelper->createCampaignEvent($campaign);
        $oldDate  = $this->fixtureHelper->daysAgo(400);

        // Create records in both tables
        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $event, $oldDate);
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old' => 30,
            '--lead'     => true,
        ]);

        $this->em->clear();

        // lead_event_log should be empty
        Assert::assertCount(0, $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]));

        // campaign_lead_event_log should still have record
        Assert::assertCount(1, $this->em->getRepository(CampaignLeadEventLog::class)->findBy(['lead' => $contact->getId()]));
    }

    public function testEmailStatsFlagCleansEmailStatsAndDevices(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('email-stats@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        // Create unpublished email with stats and devices
        $email = $this->fixtureHelper->createEmail('Unpublished Email', false);
        $stat  = $this->fixtureHelper->createEmailStat($contact, $email, $oldDate);
        $this->fixtureHelper->createEmailStatDevice($stat, $oldDate);

        // Also create lead_event_log (should NOT be deleted)
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'    => 30,
            '--email-stats' => true,
        ]);

        $this->em->clear();

        // Email stats should be deleted
        Assert::assertCount(0, $this->em->getRepository(EmailStat::class)->findBy(['lead' => $contact->getId()]));

        // Email stat devices should also be deleted
        $output = $commandTester->getDisplay();
        Assert::assertStringContainsString('email_stats_devices', $output);

        // lead_event_log should still exist
        Assert::assertCount(1, $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]));
    }

    public function testPageHitsFlagOnlyAffectsPageHits(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('page-hits@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        // Create page hit and lead_event_log
        $this->fixtureHelper->createPageHit($contact, $oldDate);
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'   => 30,
            '--page-hits'  => true,
        ]);

        $this->em->clear();

        // Page hits should be deleted
        Assert::assertCount(0, $this->em->getRepository(Hit::class)->findBy(['lead' => $contact->getId()]));

        // lead_event_log should still exist
        Assert::assertCount(1, $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]));
    }

    public function testEmailStatsTokensFlagOnlySetsTokensToNull(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('tokens@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        // Create email stat with tokens
        $email = $this->fixtureHelper->createEmail('Tokens Test Email', true);
        $stat  = $this->fixtureHelper->createEmailStat($contact, $email, $oldDate, ['token1' => 'value1', 'token2' => 'value2']);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'           => 30,
            '--email-stats-tokens' => true,
        ]);

        $this->em->clear();

        // Email stat should still exist but tokens should be null
        $stats = $this->em->getRepository(EmailStat::class)->findBy(['lead' => $contact->getId()]);
        Assert::assertCount(1, $stats);
        Assert::assertNull($stats[0]->getTokens());

        $output = $commandTester->getDisplay();
        Assert::assertStringContainsString('email_stats_tokens', $output);
        Assert::assertStringContainsString('set to NULL', $output);
    }

    // ========== Flag Combination Tests ==========

    public function testTokensFlagCannotCombineWithCampaignLead(): void
    {
        $this->fixtureHelper->enablePlugin();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--email-stats-tokens' => true,
            '--campaign-lead'      => true,
        ]);

        $output = $commandTester->getDisplay();
        // Note: the error message uses curly quotes (")
        Assert::assertStringContainsString('combination of', $output);
        Assert::assertStringContainsString('flag', $output);
        Assert::assertStringContainsString('not supported', $output);
        Assert::assertEquals(1, $commandTester->getStatusCode());
    }

    public function testTokensFlagCannotCombineWithLead(): void
    {
        $this->fixtureHelper->enablePlugin();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--email-stats-tokens' => true,
            '--lead'               => true,
        ]);

        $output = $commandTester->getDisplay();
        // Note: the error message uses curly quotes (")
        Assert::assertStringContainsString('combination of', $output);
        Assert::assertStringContainsString('flag', $output);
        Assert::assertStringContainsString('not supported', $output);
        Assert::assertEquals(1, $commandTester->getStatusCode());
    }

    public function testTokensFlagCannotCombineWithEmailStats(): void
    {
        $this->fixtureHelper->enablePlugin();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--email-stats-tokens' => true,
            '--email-stats'        => true,
        ]);

        $output = $commandTester->getDisplay();
        // Note: the error message uses curly quotes (")
        Assert::assertStringContainsString('combination of', $output);
        Assert::assertStringContainsString('flag', $output);
        Assert::assertStringContainsString('not supported', $output);
        Assert::assertEquals(1, $commandTester->getStatusCode());
    }

    public function testTokensFlagCanCombineWithDryRun(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('tokens-dryrun@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);
        $email   = $this->fixtureHelper->createEmail('Tokens Dry Run Email', true);
        $this->fixtureHelper->createEmailStat($contact, $email, $oldDate, ['token1' => 'value1']);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'           => 30,
            '--email-stats-tokens' => true,
            '--dry-run'            => true,
        ]);

        $this->em->clear();

        // Token should NOT be nullified (dry run)
        $stats = $this->em->getRepository(EmailStat::class)->findBy(['lead' => $contact->getId()]);
        Assert::assertCount(1, $stats);
        Assert::assertNotNull($stats[0]->getTokens());

        $output = $commandTester->getDisplay();
        Assert::assertStringContainsString('will be set to NULL', $output);
        Assert::assertStringContainsString('This is a dry run', $output);
        Assert::assertEquals(0, $commandTester->getStatusCode());
    }

    public function testMultipleTableFlagsCanCombine(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact  = $this->fixtureHelper->createContact('multi-flags@test.com');
        $campaign = $this->fixtureHelper->createCampaign('Multi Flag Test');
        $event    = $this->fixtureHelper->createCampaignEvent($campaign);
        $oldDate  = $this->fixtureHelper->daysAgo(400);

        // Create multiple campaign logs (the query preserves MAX(id) per lead+campaign)
        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $event, $oldDate, 1);
        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $event, $oldDate, 2);
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate);
        $this->fixtureHelper->createPageHit($contact, $oldDate);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'      => 30,
            '--campaign-lead' => true,
            '--lead'          => true,
        ]);

        $this->em->clear();

        // campaign_lead_event_log should have 1 remaining (the MAX(id) is preserved)
        Assert::assertCount(1, $this->em->getRepository(CampaignLeadEventLog::class)->findBy(['lead' => $contact->getId()]));
        // lead_event_log should be empty
        Assert::assertCount(0, $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]));

        // Page hits should still exist (not selected)
        Assert::assertCount(1, $this->em->getRepository(Hit::class)->findBy(['lead' => $contact->getId()]));
    }

    // ========== Campaign ID Filter Tests ==========

    public function testCampaignIdFilterOnlyAffectsSpecificCampaign(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact   = $this->fixtureHelper->createContact('cmp-filter@test.com');
        $campaign1 = $this->fixtureHelper->createCampaign('Campaign 1');
        $campaign2 = $this->fixtureHelper->createCampaign('Campaign 2');
        $event1    = $this->fixtureHelper->createCampaignEvent($campaign1);
        $event2    = $this->fixtureHelper->createCampaignEvent($campaign2);
        $oldDate   = $this->fixtureHelper->daysAgo(400);

        // Create multiple logs for campaign 1 (the query preserves MAX(id) per lead+campaign)
        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign1, $event1, $oldDate, 1);
        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign1, $event1, $oldDate, 2);
        // Create log for campaign 2
        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign2, $event2, $oldDate);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old' => 30,
            '--cmp-id'   => $campaign1->getId(),
        ]);

        $this->em->clear();

        // Campaign 1 should have 1 remaining (the MAX(id) is preserved)
        Assert::assertCount(1, $this->em->getRepository(CampaignLeadEventLog::class)->findBy(['campaign' => $campaign1->getId()]));

        // Campaign 2 logs should remain unchanged
        Assert::assertCount(1, $this->em->getRepository(CampaignLeadEventLog::class)->findBy(['campaign' => $campaign2->getId()]));
    }

    public function testCampaignIdImpliesCampaignLeadFlag(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact  = $this->fixtureHelper->createContact('cmp-implies@test.com');
        $campaign = $this->fixtureHelper->createCampaign('Implied Campaign');
        $event    = $this->fixtureHelper->createCampaignEvent($campaign);
        $oldDate  = $this->fixtureHelper->daysAgo(400);

        // Create multiple campaign lead event logs (the query preserves MAX(id) per lead+campaign)
        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $event, $oldDate, 1);
        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $event, $oldDate, 2);
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate);

        // Use --cmp-id without --campaign-lead
        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old' => 30,
            '--cmp-id'   => $campaign->getId(),
        ]);

        $this->em->clear();

        // Campaign lead event log should have 1 remaining (the MAX(id) is preserved, --cmp-id implies --campaign-lead)
        Assert::assertCount(1, $this->em->getRepository(CampaignLeadEventLog::class)->findBy(['lead' => $contact->getId()]));

        // Regular lead event log should remain (only campaign_lead was implied)
        Assert::assertCount(1, $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]));
    }

    // ========== Preservation Logic Tests ==========

    public function testPreservesLastEventPerCampaignLeadCombinationWithRotations(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact  = $this->fixtureHelper->createContact('preserve-rotation@test.com');
        $campaign = $this->fixtureHelper->createCampaign('Preserve Campaign Rotation');
        $event    = $this->fixtureHelper->createCampaignEvent($campaign);

        // Create multiple old logs for the same lead+campaign combination with different rotations
        $oldDate1 = $this->fixtureHelper->daysAgo(400);
        $oldDate2 = $this->fixtureHelper->daysAgo(401);
        $oldDate3 = $this->fixtureHelper->daysAgo(402);

        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $event, $oldDate3, 1);
        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $event, $oldDate2, 2);
        $log3 = $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $event, $oldDate1, 3);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'      => 30,
            '--campaign-lead' => true,
        ]);

        $this->em->clear();

        // Only the latest log (highest ID) should remain
        $logs = $this->em->getRepository(CampaignLeadEventLog::class)->findBy(['lead' => $contact->getId()]);
        Assert::assertCount(1, $logs);
        Assert::assertEquals($log3->getId(), $logs[0]->getId());
    }

    public function testPreservesLastEventLogWithMultipleEventsNoRotation(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact  = $this->fixtureHelper->createContact('preserve-multi-events@test.com');
        $campaign = $this->fixtureHelper->createCampaign('Multi Event Campaign');

        // Create realistic campaign structure: condition event followed by action event
        $conditionEvent = $this->fixtureHelper->createCampaignEvent(
            $campaign,
            'Has valid email address',
            'email.validate.address',
            'condition'
        );
        $actionEvent = $this->fixtureHelper->createCampaignEvent(
            $campaign,
            'Send email',
            'email.send',
            'action',
            $conditionEvent
        );

        // Create old logs - both events triggered for the contact (rotation=1, no restart)
        $oldDate = $this->fixtureHelper->daysAgo(400);

        $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $conditionEvent, $oldDate);
        $lastLog = $this->fixtureHelper->createCampaignLeadEventLog($contact, $campaign, $actionEvent, $oldDate);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'      => 30,
            '--campaign-lead' => true,
        ]);

        $this->em->clear();

        // Only the last log (highest ID) should remain per lead+campaign
        $logs = $this->em->getRepository(CampaignLeadEventLog::class)->findBy(['lead' => $contact->getId()]);
        Assert::assertCount(1, $logs);
        Assert::assertEquals($lastLog->getId(), $logs[0]->getId());
    }

    public function testPreservesLastEventLogForMultipleContactsWithMultipleEvents(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact1 = $this->fixtureHelper->createContact('multi-contact1@test.com');
        $contact2 = $this->fixtureHelper->createContact('multi-contact2@test.com');
        $campaign = $this->fixtureHelper->createCampaign('Multi Contact Campaign');

        // Create realistic campaign structure: condition event followed by action event
        $conditionEvent = $this->fixtureHelper->createCampaignEvent(
            $campaign,
            'Has valid email address',
            'email.validate.address',
            'condition'
        );
        $actionEvent = $this->fixtureHelper->createCampaignEvent(
            $campaign,
            'Send email',
            'email.send',
            'action',
            $conditionEvent
        );

        $oldDate = $this->fixtureHelper->daysAgo(400);

        // Contact 1: both events triggered
        $this->fixtureHelper->createCampaignLeadEventLog($contact1, $campaign, $conditionEvent, $oldDate);
        $lastLogContact1 = $this->fixtureHelper->createCampaignLeadEventLog($contact1, $campaign, $actionEvent, $oldDate);

        // Contact 2: both events triggered
        $this->fixtureHelper->createCampaignLeadEventLog($contact2, $campaign, $conditionEvent, $oldDate);
        $lastLogContact2 = $this->fixtureHelper->createCampaignLeadEventLog($contact2, $campaign, $actionEvent, $oldDate);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'      => 30,
            '--campaign-lead' => true,
        ]);

        $this->em->clear();

        // Each contact should have only their last log remaining
        $logsContact1 = $this->em->getRepository(CampaignLeadEventLog::class)->findBy(['lead' => $contact1->getId()]);
        Assert::assertCount(1, $logsContact1);
        Assert::assertEquals($lastLogContact1->getId(), $logsContact1[0]->getId());

        $logsContact2 = $this->em->getRepository(CampaignLeadEventLog::class)->findBy(['lead' => $contact2->getId()]);
        Assert::assertCount(1, $logsContact2);
        Assert::assertEquals($lastLogContact2->getId(), $logsContact2[0]->getId());
    }

    // ========== Email Stats Rules Tests ==========

    public function testOnlyDeletesEmailStatsForUnpublishedEmails(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('email-rules@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        // Create published email stat
        $publishedEmail = $this->fixtureHelper->createEmail('Published Email', true);
        $this->fixtureHelper->createEmailStat($contact, $publishedEmail, $oldDate);

        // Create unpublished email stat
        $unpublishedEmail = $this->fixtureHelper->createEmail('Unpublished Email', false);
        $this->fixtureHelper->createEmailStat($contact, $unpublishedEmail, $oldDate);

        // Create email with publish_down in the past
        $expiredEmail = $this->fixtureHelper->createEmail('Expired Email', true, $this->fixtureHelper->daysAgo(500));
        $this->fixtureHelper->createEmailStat($contact, $expiredEmail, $oldDate);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'    => 30,
            '--email-stats' => true,
        ]);

        $this->em->clear();

        // Only published email stat should remain
        $stats = $this->em->getRepository(EmailStat::class)->findBy(['lead' => $contact->getId()]);
        Assert::assertCount(1, $stats);
        Assert::assertEquals($publishedEmail->getId(), $stats[0]->getEmail()->getId());
    }

    public function testDeletesEmailStatsWithNullEmailId(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('orphan@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        // Create email stat with null email (orphaned record)
        $this->fixtureHelper->createEmailStat($contact, null, $oldDate);

        // Create email stat with valid email
        $email = $this->fixtureHelper->createEmail('Valid Email', true);
        $this->fixtureHelper->createEmailStat($contact, $email, $oldDate);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'    => 30,
            '--email-stats' => true,
        ]);

        $this->em->clear();

        // Orphaned stat should be deleted, published email stat should remain
        $stats = $this->em->getRepository(EmailStat::class)->findBy(['lead' => $contact->getId()]);
        Assert::assertCount(1, $stats);
        Assert::assertNotNull($stats[0]->getEmail());
    }

    // ========== Edge Case Tests ==========

    public function testNoRecordsToDelete(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact    = $this->fixtureHelper->createContact('recent@test.com');
        $recentDate = $this->fixtureHelper->daysAgo(10);

        // Create recent records (within threshold)
        $this->fixtureHelper->createLeadEventLog($contact, $recentDate);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old' => 30,
            '-l'         => true,
        ]);

        $this->em->clear();

        // Record should still exist
        Assert::assertCount(1, $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]));

        // Command should complete successfully
        Assert::assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        Assert::assertStringContainsString('0 lead_event_log', $output);
    }
}
