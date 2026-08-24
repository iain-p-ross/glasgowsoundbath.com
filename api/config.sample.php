<?php
/* Copy to config.php and fill in. config.php is git-ignored and excluded from
   the FTP deploy, so it must be uploaded to the server by hand — and a deploy
   will never overwrite or delete it.

   token   Private token: eventbrite.com -> Account Settings -> Developer Links -> API Keys
   org_id  NOT the id in the organiser page URL. Find it with:
           curl -s -H "Authorization: Bearer YOUR_TOKEN" \
                https://www.eventbriteapi.com/v3/users/me/organizations/

   probe_token  OPTIONAL. Gates api/logprobe.php, a read-only diagnostic that
                reports whether the raw server access logs are readable from
                PHP. Leave it unset and that endpoint refuses to run. Delete
                both the key and logprobe.php once the question is answered.
*/
return [
    'token'  => 'REPLACE_ME',
    'org_id' => 'REPLACE_ME',
];
