<?php

/*
|--------------------------------------------------------------------------
| Observability (Task03)
|--------------------------------------------------------------------------
| One place for "who may see monitoring data" and "which environments may
| run debugging tools". Dashboards (Telescope, Pulse, Horizon) expose SQL,
| request payloads, job arguments and stack traces: treat them as admin-only.
*/

return [

    /*
     * Emails allowed to open /telescope, /pulse and /horizon outside the
     * `local` environment. In `local` the packages allow everyone.
     * Comma-separated: OBSERVABILITY_ADMIN_EMAILS="a@x.az,b@x.az"
     */
    'admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OBSERVABILITY_ADMIN_EMAILS', '')),
    ))),

    /*
     * Environments in which Telescope is registered at all. Telescope records
     * every query/request/job into the database: invaluable while developing,
     * far too expensive (and too revealing) for production.
     */
    'telescope_environments' => ['local'],

];
