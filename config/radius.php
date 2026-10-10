<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FreeRADIUS restart
    |--------------------------------------------------------------------------
    |
    | FreeRADIUS loads the routers (nas) and its settings row only at start; a reload does nothing. The app
    | runs this command after saving either. The installer adds a sudoers rule for exactly this command.
    | Empty means never restart (development): staff are told to restart FreeRADIUS by hand.
    |
    */

    'restart_command' => env('RADIUS_RESTART_COMMAND', 'sudo systemctl restart freeradius'),

    /*
    |--------------------------------------------------------------------------
    | Disconnect-Request (RFC 5176)
    |--------------------------------------------------------------------------
    |
    | After a change FreeRADIUS reads, the app sends a Disconnect-Request to the router (UDP 3799) with radclient
    | (package freeradius-utils), so an online customer gets the change now instead of at the next login.
    |
    */

    'radclient' => env('RADIUS_RADCLIENT', 'radclient'),

];
