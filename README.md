# Housekeeping by Leuchtfeuer
This plugin provides a Mautic Housekeeping Command for database cleanup purposes.

## Command
Command to delete lead_event_log table entries, campaign_lead_event_log table entries, email_stats table entries where the referenced email entry is currently not published and email_stats_devices table entries.

Important: If referenced email is ever switched back to published, the contacts will get the email again.

```
bin/console leuchtfeuer:housekeeping
```
By default, entries older than 365 days are deleted from the CampaignLeadEventLog, LeadEventLog, EmailStats (only email_stats entries that referenced emails entry is currently not published) and EmailStatsDevices tables.

### Parameter
```
-d  | --days-old                | Specifies the minimum age of the entries to be deleted. Default: 365 days
-r  | --dry-run                 | Execute as dry run. No entries will be deleted
-i  | --cmp-id                  | Delete only data for a specific campaign ID from campaign_lead_event_log
-c  | --campaign-lead           | Only entries from the campaign_lead_event_log table will be deleted
-m  | --email-stats             | Only entries from the email_stats table where the referenced email entry is currently not published and from the email_stats_devices table will be deleted.
-t  | --email-stats-tokens      | Only set tokens fields in Email Stats Records to NULL instead of deleting the whole record
-l  | --lead                    | Only entries from the lead_event_log table will be deleted.
-p  | --page-hits               | Only entries from the page_hits table will be deleted.
```

### Installation
- Plugin must be saved under plugins/LeuchtfeuerHousekeepingBundle/
- Afterwards, the cache must be cleared.

### Compatiblity
- Plugin is for Mautic 5 and also backwards compatible for Mautic 4.

### Notice
- Every last entry from the campaign_lead_event_log per campaign will be kept. This is due to contacts restarting campaigns if there is no last step preserved in the log.

### Deleting huge ammounts of data
- It might happen that the plugin fails to delete if the data is too large to handle. In that case: Create the following bash script and iterate through the deletion day by day:
```
#!/bin/sh
DOCROOT="path/to/mautic"
TIME="/usr/bin/time"
OUT="$DOCROOT/../log/housekeeping-loop.date +%Y%m%d_%H%M%S"
START=put_start_day_here #(for example 200)
END=put_end_day_here  #(for example 100)
OP="put_your_operator_here" #(for example "-m" for emails / "-p" for page_hits | needs to be in "")

seq $START -1 $END |while read i ; do
echo "********** $i **********" >> $OUT
$TIME -ao $OUT sudo -u www-data php $DOCROOT/bin/console leu:hou $OP -d $i 2>&1 >>$OUT
sleep 2
done
```

### Author
Leuchtfeuer Digital Marketing GmbH

mautic@Leuchtfeuer.com
