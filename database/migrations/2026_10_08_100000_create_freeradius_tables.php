<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables the isp FreeRADIUS virtual server reads and writes. Structure matches the box (dump of
 * 2026-10-08); nas gets the app's extra columns. Change these only together with isp and docs/changelog.md.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['radcheck' => '==', 'radreply' => '='] as $name => $defaultOp) {
            Schema::create($name, function (Blueprint $table) use ($name, $defaultOp) {
                $table->increments('id');
                $table->string('username', 64);
                $table->string('attribute', 64)->default('');
                $table->char('op', 2)->default($defaultOp);
                $table->string('value', 253)->default('');
                $table->index('username');
                $table->index(['username', 'attribute'], "idx_{$name}_user_attr");
            });
        }

        foreach (['radgroupcheck' => '==', 'radgroupreply' => '='] as $name => $defaultOp) {
            Schema::create($name, function (Blueprint $table) use ($defaultOp) {
                $table->increments('id');
                $table->string('groupname', 64)->default('');
                $table->string('attribute', 64)->default('');
                $table->char('op', 2)->default($defaultOp);
                $table->string('value', 253)->default('');
                $table->index('groupname');
            });
        }

        Schema::create('radusergroup', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username', 64)->default('');
            $table->string('groupname', 64)->default('');
            $table->integer('priority')->default(1);
            $table->index('username');
        });

        Schema::create('radacct', function (Blueprint $table) {
            $table->bigIncrements('radacctid');
            $table->string('acctsessionid', 64)->default('');
            $table->string('acctuniqueid', 32)->default('')->unique();
            $table->string('username', 64)->default('');
            $table->string('realm', 64)->nullable()->default('');
            $table->string('nasipaddress', 15)->default('');
            $table->string('nasportid', 32)->nullable();
            $table->string('nasporttype', 32)->nullable();
            $table->dateTime('acctstarttime')->nullable();
            $table->dateTime('acctupdatetime')->nullable();
            $table->dateTime('acctstoptime')->nullable();
            $table->integer('acctinterval')->nullable();
            $table->unsignedInteger('acctsessiontime')->nullable();
            $table->string('acctauthentic', 32)->nullable();
            $table->string('connectinfo_start', 128)->nullable();
            $table->string('connectinfo_stop', 128)->nullable();
            $table->bigInteger('acctinputoctets')->nullable();
            $table->bigInteger('acctoutputoctets')->nullable();
            $table->string('calledstationid', 50)->default('');
            $table->string('callingstationid', 50)->default('');
            $table->string('acctterminatecause', 32)->default('');
            $table->string('servicetype', 32)->nullable();
            $table->string('framedprotocol', 32)->nullable();
            $table->string('framedipaddress', 15)->default('');
            $table->string('framedipv6address', 45)->default('');
            $table->string('framedipv6prefix', 45)->default('');
            $table->string('framedinterfaceid', 44)->default('');
            $table->string('delegatedipv6prefix', 45)->default('');
            $table->string('class', 64)->nullable();

            foreach (['username', 'framedipaddress', 'framedipv6address', 'framedipv6prefix', 'framedinterfaceid',
                'delegatedipv6prefix', 'acctsessionid', 'acctsessiontime', 'acctstarttime', 'acctinterval',
                'acctstoptime', 'nasipaddress', 'class'] as $column) {
                $table->index($column);
            }
        });

        Schema::create('radpostauth', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('username', 64)->default('');
            $table->string('pass', 64)->default('');
            $table->string('reply', 256)->default('');
            $table->string('reply_msg', 256)->nullable();
            $table->timestamp('authdate')->useCurrent()->useCurrentOnUpdate();
            $table->string('nasipaddress', 100)->nullable();
            $table->text('mac')->nullable();
            $table->index('username');
        });

        Schema::create('nas', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nasname', 128)->unique();
            $table->string('shortname', 32)->nullable();
            $table->string('type', 30)->nullable()->default('other');
            $table->integer('ports')->nullable();
            $table->string('secret', 60)->default('secret');
            $table->string('server', 64)->nullable();
            $table->string('community', 50)->nullable();
            $table->string('description', 200)->nullable()->default('RADIUS Client');
            $table->string('routeros_version', 20)->nullable();
            $table->boolean('require_message_auth')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nasreload', function (Blueprint $table) {
            $table->string('nasipaddress', 15)->primary();
            $table->dateTime('reloadtime');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->tinyInteger('expire_type')->default(0);
            $table->string('disabled_users_pool', 64)->default('');
            $table->smallInteger('reject_requests')->default(30);
            $table->unsignedSmallInteger('reject_session_timeout')->nullable()->default(7200);
            $table->mediumInteger('default_session_timeout')->nullable()->default(64800);
            $table->integer('count_and_reject')->nullable()->default(0);
            $table->integer('reject_packet_interval')->nullable()->default(30);
        });

        Schema::create('account_macs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('username', 100);
            $table->char('mac_norm', 12);
            $table->dateTime('learned_at')->useCurrent();
            $table->dateTime('last_seen')->nullable();
            $table->unique('mac_norm', 'uq_mac_norm');
            $table->unique(['username', 'mac_norm'], 'uq_user_mac');
            $table->index('username', 'idx_username');
        });

        Schema::create('reject_counters', function (Blueprint $table) {
            $table->string('username', 64);
            $table->char('mac', 12)->default('');
            $table->unsignedInteger('counter')->default(0);
            $table->dateTime('last_update');
            $table->primary(['username', 'mac']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['reject_counters', 'account_macs', 'settings', 'nasreload', 'nas', 'radpostauth', 'radacct',
            'radusergroup', 'radgroupreply', 'radgroupcheck', 'radreply', 'radcheck'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
