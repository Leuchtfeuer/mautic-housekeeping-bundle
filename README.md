# Mautic Housekeeping Bundle
Dieses Bundle stellt einen Mautic Houseekping Command  zum säubern der Datenbank zur Verfügung

## Command

Befehl zum Löschen von EventLog Tabellen Einträgen. 

```
bin/console mautic:housekeeping:cleanup
```
Es werden standardmäßig Einträge aus der CampaignLeadEventLog und der LeadEventLog Tabelle gelöscht, die älter als 365 sind.
### Parameter
```
-d  | --days-old      | Gibt das mindest Alter der zu löschenden Einträge an. Default: 365 Tage
-r  | --dry-run       | Durchführung als Dry Run. Es werden keine Einträge gelöscht.
-i  | --cmp-id        | Nur Daten zu einer spezifischen Kampagnen Id löschen
-c  | --campaign-lead | Es werden nur Einträge aus der CampaignLeadEventLog Tabelle gelöscht.
-l  | --lead          | Es werden nur Einträge aus der LeadEventLog Tabelle gelöscht.
```



