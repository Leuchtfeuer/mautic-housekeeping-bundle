<?php

return [
    'name'        => 'Housekeeping by Leuchtfeuer',
    'description' => 'Database Cleanup Command to delete lead_event_log table entries, campaign_lead_event_log table entries, email_stats table entries where the referenced email entry is currently not published and email_stats_devices table entries.',
    'version'     => '7.0.3',
    'author'      => 'Leuchtfeuer Digital Marketing GmbH',
];
