#Mautic Housekeeping Bundle
Dieses Bundle stellt einen Mautic Houseekping Command  zum säubern der Datenbank zur Verfügung

##Command

Befehl zum Löschen von CampaignLeadEventLog Tabellen Einträgen
```
bin/console mautic:housekeeping:cleanup
```
###Parameter
```
-d  | --days-old   | Gibt das mindest Alter der zu löschenden Einträge an. Default: 365 Tage
-r  | --dry-run    | Durchführung als Dry Run. Es werden keine Einträge gelöscht.
-c  | --cmp-id     | Nur Daten zu einer spezifischen Kampagnen Id löschen
```



