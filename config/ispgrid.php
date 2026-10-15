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

];
