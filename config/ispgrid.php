<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Installed version
    |--------------------------------------------------------------------------
    |
    | The installed release tag (the installer writes ISPGRID_VERSION). "dev" on a development checkout: every
    | add-on counts as compatible there.
    |
    */

    'version' => env('ISPGRID_VERSION', 'dev'),

    /*
    |--------------------------------------------------------------------------
    | Add-ons
    |--------------------------------------------------------------------------
    |
    | Load installed add-ons. The core's own tests turn this off (phpunit.xml), so an add-on in a developer's
    | vendor folder never changes their results.
    |
    */

    'addons' => env('ISPGRID_ADDONS', 'on') !== 'off',

    /*
    |--------------------------------------------------------------------------
    | Usage database
    |--------------------------------------------------------------------------
    |
    | Usage per customer per day lives in its own database on the same server, filled by a trigger on radacct.
    | FreeRADIUS reads it by this name (sites-available/isp): change it only in tests (phpunit.xml).
    |
    */

    'usage_database' => env('USAGE_DATABASE', 'ispgrid_usage'),

];
