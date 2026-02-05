<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog as CampaignLeadEventLog;
use Mautic\CoreBundle\Entity\IpAddress;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat as EmailStat;
use Mautic\EmailBundle\Entity\StatDevice;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\PageBundle\Entity\Hit;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\Plugin;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\HousekeepingLeuchtfeuerIntegration;

final class FixtureHelper
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function enablePlugin(): void
    {
        $plugin = new Plugin();
        $plugin->setName('Housekeeping by Leuchtfeuer');
        $plugin->setBundle('LeuchtfeuerHousekeepingBundle');
        $this->em->persist($plugin);

        $integration = new Integration();
        $integration->setPlugin($plugin);
        $integration->setIsPublished(true);
        $integration->setName(HousekeepingLeuchtfeuerIntegration::INTEGRATION_NAME);
        $this->em->persist($integration);
        $this->em->flush();
    }

    public function createCampaign(string $name): Campaign
    {
        $campaign = new Campaign();
        $campaign->setName($name);
        $campaign->setIsPublished(true);
        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    public function createCampaignEvent(
        Campaign $campaign,
        string $name = 'Test Event',
        string $type = 'lead.leadlist',
        string $eventType = 'action',
        ?Event $parent = null,
    ): Event {
        $event = new Event();
        $event->setCampaign($campaign);
        $event->setName($name);
        $event->setType($type);
        $event->setEventType($eventType);
        $event->setTriggerMode('immediate');
        if (null !== $parent) {
            $event->setParent($parent);
            $event->setDecisionPath('yes');
        }
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    public function createContact(string $email): Lead
    {
        $contact = new Lead();
        $contact->setEmail($email);
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    public function createCampaignLeadEventLog(
        Lead $lead,
        Campaign $campaign,
        Event $event,
        \DateTime $dateTriggered,
        int $rotation = 1,
    ): CampaignLeadEventLog {
        $log = new CampaignLeadEventLog();
        $log->setLead($lead);
        $log->setCampaign($campaign);
        $log->setEvent($event);
        $log->setDateTriggered($dateTriggered);
        $log->setRotation($rotation);
        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    public function createLeadEventLog(Lead $lead, \DateTime $dateAdded): LeadEventLog
    {
        $eventLog = new LeadEventLog();
        $eventLog->setLead($lead);
        $eventLog->setBundle('campaign');
        $eventLog->setObject('action');
        $eventLog->setAction('triggered');
        $eventLog->setObjectId(1);
        $eventLog->setDateAdded($dateAdded);
        $this->em->persist($eventLog);
        $this->em->flush();

        return $eventLog;
    }

    public function createEmail(string $name, bool $isPublished = true, ?\DateTime $publishDown = null): Email
    {
        $email = new Email();
        $email->setName($name);
        $email->setSubject($name);
        $email->setEmailType('template');
        $email->setIsPublished($isPublished);
        if (null !== $publishDown) {
            $email->setPublishDown($publishDown);
        }
        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }

    /**
     * @param array<string, mixed>|null $tokens
     */
    public function createEmailStat(
        Lead $lead,
        ?Email $email,
        \DateTime $dateSent,
        ?array $tokens = null,
    ): EmailStat {
        $stat = new EmailStat();
        $stat->setLead($lead);
        if (null !== $email) {
            $stat->setEmail($email);
        }
        $stat->setEmailAddress($lead->getEmail());
        $stat->setDateSent($dateSent);
        $stat->setTrackingHash(bin2hex(random_bytes(16)));
        if (null !== $tokens) {
            $stat->setTokens($tokens);
        }
        $this->em->persist($stat);
        $this->em->flush();

        return $stat;
    }

    public function createEmailStatDevice(EmailStat $stat, \DateTime $dateOpened): StatDevice
    {
        $ipAddress = $this->createIpAddress();

        $statDevice = new StatDevice();
        $statDevice->setStat($stat);
        $statDevice->setIpAddress($ipAddress);
        $statDevice->setDateOpened($dateOpened);
        $this->em->persist($statDevice);
        $this->em->flush();

        return $statDevice;
    }

    public function createPageHit(Lead $lead, \DateTime $dateHit): Hit
    {
        $ipAddress = $this->createIpAddress();

        $hit = new Hit();
        $hit->setLead($lead);
        $hit->setIpAddress($ipAddress);
        $hit->setDateHit($dateHit);
        $hit->setCode(200);
        $hit->setUrl('https://example.com/test-page');
        $hit->setTrackingId(bin2hex(random_bytes(16)));
        $this->em->persist($hit);
        $this->em->flush();

        return $hit;
    }

    public function daysAgo(int $days): \DateTime
    {
        return (new \DateTime())->modify("-{$days} days");
    }

    private function createIpAddress(string $ip = '127.0.0.1'): IpAddress
    {
        $ipAddress = new IpAddress($ip);
        $this->em->persist($ipAddress);
        $this->em->flush();

        return $ipAddress;
    }
}
