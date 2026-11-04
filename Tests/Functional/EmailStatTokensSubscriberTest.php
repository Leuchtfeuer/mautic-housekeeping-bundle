<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\EventListener\EmailStatTokensSubscriber;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Fixtures\FixtureHelper;

final class EmailStatTokensSubscriberTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    private FixtureHelper $fixtureHelper;

    protected function setUp(): void
    {
        $this->configParams[EmailStatTokensSubscriber::PARAMETER] = true;

        parent::setUp();

        $this->fixtureHelper = new FixtureHelper($this->em);
    }

    public function testNewStatIsSavedWithoutTokens(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('tokens-new@test.com');
        $email   = $this->fixtureHelper->createEmail('New Stat Email');

        $stat = new Stat();
        $stat->setLead($contact);
        $stat->setEmail($email);
        $stat->setEmailAddress((string) $contact->getEmail());
        $stat->setDateSent(new \DateTime());
        $stat->setTrackingHash(bin2hex(random_bytes(16)));
        $stat->setTokens(['{subject}' => 'Hello']);

        $this->getEmailModel()->saveEmailStat($stat);

        $this->em->clear();

        $stats = $this->em->getRepository(Stat::class)->findBy(['lead' => $contact->getId()]);
        $this->assertCount(1, $stats);
        $this->assertSame([], $stats[0]->getTokens());
    }

    public function testExistingStatLosesItsTokensWhenSavedAgain(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('tokens-existing@test.com');
        $email   = $this->fixtureHelper->createEmail('Existing Stat Email');
        $stat    = $this->fixtureHelper->createEmailStat($contact, $email, new \DateTime(), ['{subject}' => 'Hello']);

        $this->getEmailModel()->saveEmailStat($stat);

        $this->em->clear();

        $stats = $this->em->getRepository(Stat::class)->findBy(['lead' => $contact->getId()]);
        $this->assertCount(1, $stats);
        $this->assertSame([], $stats[0]->getTokens());
    }

    public function testTokensAreKeptWhenThePluginIsNotPublished(): void
    {
        $contact = $this->fixtureHelper->createContact('tokens-unpublished@test.com');
        $email   = $this->fixtureHelper->createEmail('Unpublished Plugin Email');
        $stat    = $this->fixtureHelper->createEmailStat($contact, $email, new \DateTime(), ['{subject}' => 'Hello']);

        $this->getEmailModel()->saveEmailStat($stat);

        $this->em->clear();

        $stats = $this->em->getRepository(Stat::class)->findBy(['lead' => $contact->getId()]);
        $this->assertCount(1, $stats);
        $this->assertSame(['{subject}' => 'Hello'], $stats[0]->getTokens());
    }

    private function getEmailModel(): EmailModel
    {
        $model = static::getContainer()->get('mautic.email.model.email');
        \assert($model instanceof EmailModel);

        return $model;
    }
}
