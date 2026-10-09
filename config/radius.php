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

];
