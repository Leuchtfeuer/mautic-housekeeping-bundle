<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\PageBundle\Entity\Hit;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Fixtures\FixtureHelper;

final class LeadObjectFilterCommandTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    private FixtureHelper $fixtureHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureHelper = new FixtureHelper($this->em);
    }

    public function testDeletesOnlyTheListedObject(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('lead-object@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        $this->fixtureHelper->createLeadEventLog($contact, $oldDate, 'lead', 'segment');
        $importLog = $this->fixtureHelper->createLeadEventLog($contact, $oldDate, 'lead', 'import');
        $recentLog = $this->fixtureHelper->createLeadEventLog($contact, $this->fixtureHelper->daysAgo(10), 'lead', 'segment');

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'    => 30,
            '--lead-object' => 'segment',
        ]);

        $this->em->clear();

        $logs = $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]);
        $this->assertCount(2, $logs);

        $logIds = array_map(static fn (LeadEventLog $log): int => (int) $log->getId(), $logs);
        $this->assertContains((int) $importLog->getId(), $logIds);
        $this->assertContains((int) $recentLog->getId(), $logIds);

        $this->assertStringContainsString('1 lead_event_log', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testAcceptsSeveralObjects(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('lead-objects@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        $this->fixtureHelper->createLeadEventLog($contact, $oldDate, 'lead', 'segment');
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate, 'lead', 'import');
        $keptLog = $this->fixtureHelper->createLeadEventLog($contact, $oldDate, 'campaign', 'action');

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'    => 30,
            '--lead-object' => 'segment, import',
        ]);

        $this->em->clear();

        $logs = $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]);
        $this->assertCount(1, $logs);
        $this->assertEquals($keptLog->getId(), $logs[0]->getId());
    }

    public function testImpliesOnlyTheLeadEventLog(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('lead-object-only@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        $this->fixtureHelper->createLeadEventLog($contact, $oldDate, 'lead', 'segment');
        $this->fixtureHelper->createPageHit($contact, $oldDate);

        $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'    => 30,
            '--lead-object' => 'segment',
        ]);

        $this->em->clear();

        $this->assertCount(0, $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]));
        $this->assertCount(1, $this->em->getRepository(Hit::class)->findBy(['lead' => $contact->getId()]));
    }

    public function testDryRunCountsOnlyTheListedObject(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('lead-object-dryrun@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        $this->fixtureHelper->createLeadEventLog($contact, $oldDate, 'lead', 'segment');
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate, 'lead', 'import');

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'    => 30,
            '--lead-object' => 'segment',
            '--dry-run'     => true,
        ]);

        $this->em->clear();

        $this->assertCount(2, $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]));
        $this->assertStringContainsString('1 lead_event_log', $commandTester->getDisplay());
        $this->assertStringContainsString('This is a dry run', $commandTester->getDisplay());
    }

    public function testEmptyValueStopsWithoutDeleting(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('lead-object-empty@test.com');
        $this->fixtureHelper->createLeadEventLog($contact, $this->fixtureHelper->daysAgo(400), 'lead', 'segment');

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'    => 30,
            '--lead-object' => ' , ',
        ]);

        $this->em->clear();

        $this->assertCount(1, $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]));
        $this->assertStringContainsString('--lead-object needs at least one object name', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }
}
