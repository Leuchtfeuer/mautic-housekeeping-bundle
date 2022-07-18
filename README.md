# Mautic Housekeeping Bundle
This bundle provides a Mautic Houseekping Command for database cleanup purposes.

## Command

Command to delete EventLog table entries. 

```
bin/console mautic:leuchtfeuer:housekeeping
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
- Plugin must be saved under plugins/MauticHouskeepingBundle/ 

- Afterwards, the cache must be cleared.  


