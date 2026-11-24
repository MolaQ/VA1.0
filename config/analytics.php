<?php

return [
    /*
    | First-party, cookie-less analytics. Set ANALYTICS_ENABLED=false to turn
    | tracking off entirely. Raw page views are deleted after `retention_days`.
    */
    'enabled' => (bool) env('ANALYTICS_ENABLED', true),

    'retention_days' => (int) env('ANALYTICS_RETENTION_DAYS', 400),
];
