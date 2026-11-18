<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Support\Setup\FirstRunSetup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('setup:server-info
    {--routers= : How routers reach this server: lan, wireguard, l2tp or public}
    {--radius-ip= : The address routers send RADIUS to}
    {--home= : Where the code is installed}
    {--pending : A fresh install: open the setup page after the first login}')]
#[Description('Save how routers reach this server for the setup page (the installer runs this)')]
class SetupServerInfo extends Command
{
    public function handle(): int
    {
        $routers = $this->input()->string('routers')->toString();
        $radiusIp = $this->input()->string('radius-ip')->toString();
        $home = $this->input()->string('home')->toString();

        if ($routers !== '' && ! in_array($routers, FirstRunSetup::ROUTER_MODES, true)) {
            $this->error('--routers must be one of: '.implode(', ', FirstRunSetup::ROUTER_MODES));

            return self::FAILURE;
        }

        if ($radiusIp !== '' && filter_var($radiusIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $this->error('--radius-ip must be an IPv4 address.');

            return self::FAILURE;
        }

        if ($home !== '' && preg_match('#^/[A-Za-z0-9/._-]+$#D', $home) !== 1) {
            $this->error('--home must be an absolute path.');

            return self::FAILURE;
        }

        foreach ([FirstRunSetup::ROUTERS => $routers, FirstRunSetup::RADIUS_IP => $radiusIp, FirstRunSetup::HOME => $home] as $key => $value) {
            if ($value !== '') {
                AppSetting::put($key, $value);
            }
        }

        if ($this->option('pending')) {
            AppSetting::put(FirstRunSetup::PENDING, '1');
        }

        return self::SUCCESS;
    }
}
