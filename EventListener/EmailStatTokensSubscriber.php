<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\EventListener;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Event\EmailStatEvent;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Config;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class EmailStatTokensSubscriber implements EventSubscriberInterface
{
    public const PARAMETER = 'housekeeping_skip_email_stats_tokens';

    private ?bool $isEnabled = null;

    public function __construct(
        private readonly CoreParametersHelper $coreParametersHelper,
        private readonly Config $config,
    ) {
    }

    /**
     * Uses the event name instead of EmailEvents::ON_EMAIL_STAT_PRE_SAVE, which does not exist on Mautic 5.0.
     */
    public static function getSubscribedEvents(): array
    {
        return ['mautic.email.on_email_stat_pre_save' => 'onEmailStatPreSave'];
    }

    /**
     * Empties the tokens of every stat about to be saved, so they never reach email_stats.tokens.
     */
    public function onEmailStatPreSave(EmailStatEvent $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        foreach ($event->getStats() as $stat) {
            $stat->setTokens([]);
        }
    }

    /**
     * Worked out once per process, because this runs for every saved stat.
     */
    private function isEnabled(): bool
    {
        return $this->isEnabled ??= (bool) $this->coreParametersHelper->get(self::PARAMETER) && $this->config->isPublished();
    }
}
