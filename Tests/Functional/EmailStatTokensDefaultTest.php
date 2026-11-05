<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Fixtures\FixtureHelper;

final class EmailStatTokensDefaultTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    public function testTokensAreStoredByDefault(): void
    {
        $fixtureHelper = new FixtureHelper($this->em);
        $fixtureHelper->enablePlugin();

        $contact = $fixtureHelper->createContact('tokens-default@test.com');
        $email   = $fixtureHelper->createEmail('Default Tokens Email');
        $stat    = $fixtureHelper->createEmailStat($contact, $email, new \DateTime(), ['{subject}' => 'Hello']);

        $model = static::getContainer()->get('mautic.email.model.email');
        \assert($model instanceof EmailModel);
        $model->saveEmailStat($stat);

        $this->em->clear();

        $stats = $this->em->getRepository(Stat::class)->findBy(['lead' => $contact->getId()]);
        $this->assertCount(1, $stats);
        $this->assertSame(['{subject}' => 'Hello'], $stats[0]->getTokens());
    }
}
