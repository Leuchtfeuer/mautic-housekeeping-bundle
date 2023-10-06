# Housekeeping by Leuchtfeuer
This plugin provides a Mautic Housekeeping Command for database cleanup purposes.

## Command
Command to delete lead_event_log table entries, campaign_lead_event_log table entries, email_stats table entries where the referenced email entry is currently not published or the "unpublished at (date/time)" for that email is expired and email_stats_devices table entries.

Important: If referenced email is ever switched back to published, the contacts will get the email again.

```
bin/console leuchtfeuer:housekeeping
```
By default, entries older than 365 days are deleted from the CampaignLeadEventLog, LeadEventLog, EmailStats (only email_stats entries that referenced emails entry is currently not published or the "unpublished at (date/time)" for that email is expired) and EmailStatsDevices tables.
By default, the command will delete the entries in batches of 100.000 entries per table (the command will run until all entries are deleted).

### Parameter
```
-d  | --days-old                | Specifies the minimum age of the entries to be deleted. Default: 365 days
-r  | --dry-run                 | Execute as dry run. No entries will be deleted
-i  | --cmp-id                  | Delete only data for a specific campaign ID from campaign_lead_event_log
-c  | --campaign-lead           | Only entries from the campaign_lead_event_log table will be deleted
-m  | --email-stats             | Only entries from the email_stats table where the referenced email entry is currently not published and from the email_stats_devices table will be deleted.
-t  | --email-stats-tokens      | Set only tokens fields in Email Stats Records to NULL. Important: This option can not be combined with any "-c", "-l" or "-m" flag in one command. And: If the option flag "-t" is not set, the NULL setting of tokens will not be done with the basis command, so if you just run mautic:leuchtfeuer:housekeeping without a flag.
-l  | --lead                    | Only entries from the lead_event_log table will be deleted.
-b  | --limit                   | Set the number of rows which will be deleted in one turn (the command will run until all entries are deleted). Default: 100.000
```

### Installation
- Plugin must be saved under plugins/LeuchtfeuerHousekeepingBundle/
- Afterwards, the cache must be cleared.

### Notice
- Every last entry from the campaign_lead_event_log per campaign will be kept. This is due to contacts restarting campaigns if there is no last step preserved in the log.

### Known issues
- A `--dry-run` might fail on databases with a huge amount of entries.

### Author
Leuchtfeuer Digital Marketing GmbH

mautic-plugins@Leuchtfeuer.com
