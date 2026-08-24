<?php
/* Copy to config.php and fill in. config.php is git-ignored and excluded from
   the FTP deploy, so it must be uploaded to the server by hand — and a deploy
   will never overwrite or delete it.

   token       Private token: eventbrite.com -> Account Settings ->
               Developer Links -> API Keys
   org_id      NOT the id in the organiser page URL. Find it with:
                 curl -s -H "Authorization: Bearer YOUR_TOKEN" \
                      https://www.eventbriteapi.com/v3/users/me/organizations/
   logs_token  Gates api/logs.php, which turns the raw server access log into
               daily arrival and click aggregates. Any long random string.

   ⚠️ EVERY line inside the array needs a trailing comma. A missing one is a PHP
   parse error, and because api/events.php requires this file too, it takes the
   public events listing down with it. That has already happened once.
*/
return [
    'token'      => 'REPLACE_ME',
    'org_id'     => 'REPLACE_ME',
    'logs_token' => 'REPLACE_ME',
];
