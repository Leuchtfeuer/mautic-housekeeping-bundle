# Mautic Housekeeping Bundle
This bundle provides a Mautic Houseekping Command for database cleanup purposes.

## Command

Command to delete EventLog table entries. 

```
bin/console mautic:eventlog:delete
```
By default, entries older than 365 days are deleted from the CampaignLeadEventLog and LeadEventLog tables.
### Parameter
```
-d  | --days-old      | Specifies the minimum age of the entries to be deleted. Default: 365 days
-r  | --dry-run       | Execute as dry run. No entries will be deleted
-i  | --cmp-id        | Delete only data for a specific campaign ID
-c  | --campaign-lead | Only entries from the CampaignLeadEventLog table will be deleted
-l  | --lead          | Only entries from the LeadEventLog table will be deleted.
```


### Installation
- Contents must be saved under plugins/MauticHouskeepingBundle/  as follows: 
```
PAGE_NAME/htdocs/plugins/MauticHousekeepingBundle/
- - - Command/
- - - - - EventLogCleanupCommand.php
- - - Config/
- - - - - config.php
- - - MauticHousekeepingBundle.php
```

- Afterwards, the cache should be cleared once.  The easiest way is to go to the /var/cache folder and delete its content. 
  Navigate to the Mautic root folder and run: 
```
rm -rf var/cache/*
```


