<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature='push:vapid-keys';
    protected $description='Generate VAPID keys for browser push notifications';

    public function handle(): int
    {
        $keys=VAPID::createVapidKeys();

        $this->line('PUSH_VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('PUSH_VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('PUSH_VAPID_SUBJECT='.config('app.url'));

        return self::SUCCESS;
    }
}
