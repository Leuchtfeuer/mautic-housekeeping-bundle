<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\EventListener;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Event\EmailStatEvent;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\EventListener\EmailStatTokensSubscriber;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Config;
use PHPUnit\Framework\TestCase;

final class EmailStatTokensSubscriberTest extends TestCase
{
    public function testSubscribesToTheStatPreSaveEvent(): void
    {
        $this->assertArrayHasKey(EmailEvents::ON_EMAIL_STAT_PRE_SAVE, EmailStatTokensSubscriber::getSubscribedEvents());
    }

    public function testEmptiesTokensWhenTheOptionIsOnAndThePluginIsPublished(): void
    {
        $firstStat  = $this->createStat();
        $secondStat = $this->createStat();

        $this->createSubscriber(true, true)->onEmailStatPreSave(new EmailStatEvent([$firstStat, $secondStat]));

        $this->assertSame([], $firstStat->getTokens());
        $this->assertSame([], $secondStat->getTokens());
    }

    public function testKeepsTokensWhenThePluginIsNotPublished(): void
    {
        $stat = $this->createStat();

        $this->createSubscriber(true, false)->onEmailStatPreSave(new EmailStatEvent([$stat]));

        $this->assertSame(['{subject}' => 'Hello'], $stat->getTokens());
    }

    public function testKeepsTokensAndSkipsThePluginCheckWhenTheOptionIsOff(): void
    {
        $stat = $this->createStat();

        $coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $coreParametersHelper->method('get')
            ->with(EmailStatTokensSubscriber::PARAMETER)
            ->willReturn(false);

        $config = $this->createMock(Config::class);
        $config->expects($this->never())
            ->method('isPublished');

        (new EmailStatTokensSubscriber($coreParametersHelper, $config))->onEmailStatPreSave(new EmailStatEvent([$stat]));

        $this->assertSame(['{subject}' => 'Hello'], $stat->getTokens());
    }

    public function testChecksThePluginOnlyOnce(): void
    {
        $coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $coreParametersHelper->expects($this->once())
            ->method('get')
            ->willReturn(true);

        $config = $this->createMock(Config::class);
        $config->expects($this->once())
            ->method('isPublished')
            ->willReturn(true);

        $subscriber = new EmailStatTokensSubscriber($coreParametersHelper, $config);
        $subscriber->onEmailStatPreSave(new EmailStatEvent([$this->createStat()]));
        $subscriber->onEmailStatPreSave(new EmailStatEvent([$this->createStat()]));
    }

    private function createSubscriber(bool $isOptionOn, bool $isPublished): EmailStatTokensSubscriber
    {
        $coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $coreParametersHelper->method('get')
            ->with(EmailStatTokensSubscriber::PARAMETER)
            ->willReturn($isOptionOn);

        $config = $this->createMock(Config::class);
        $config->method('isPublished')
            ->willReturn($isPublished);

        return new EmailStatTokensSubscriber($coreParametersHelper, $config);
    }

    private function createStat(): Stat
    {
        $stat = new Stat();
        $stat->setTokens(['{subject}' => 'Hello']);

        return $stat;
    }
}
