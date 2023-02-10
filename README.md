# Mautic Housekeeping Bundle
This bundle provides a Mautic Housekeeping Command for database cleanup purposes.

## Command

Command to delete lead_event_log, campaign_lead_event_log, email_stats and email_stats_devices table entries or just set the content of field tokens in email_stats to NULL if the option flag -t is set. 

```
bin/console mautic:leuchtfeuer:housekeeping
```
By default, entries older than 365 days are deleted from the CampaignLeadEventLog, LeadEventLog, EmailStats and EmailStatsDevices tables.
### Parameter
```
-d  | --days-old                | Specifies the minimum age of the entries to be deleted. Default: 365 days
-r  | --dry-run                 | Execute as dry run. No entries will be deleted
-i  | --cmp-id                  | Delete only data for a specific campaign ID from campaign_lead_event_log
-c  | --campaign-lead           | Only entries from the campaign_lead_event_log table will be deleted
-m  | --email-stats             | Only entries from the email_stats and email_stats_devices tables will be deleted.
-t  | --email-stats-tokens      | Set tokens field in email_stats to NULL. Important: This one will not be executed, if the option flag -t (or email-stats-tokens) is not set in the command. And: This option can not be combined with any -c, -l or -m in one command at the moment.
-l  | --lead                    | Only entries from the lead_event_log table will be deleted.
```


### Installation
- Plugin must be saved under plugins/MauticHouskeepingBundle/ 

- Afterwards, the cache must be cleared.


## Notice
- Every last entry from the campaign_lead_event_log per campaign will be kept. This is due to contacts restarting campaigns if there is no last step preserved in the log. 
