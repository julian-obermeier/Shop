<?php
namespace App\Console\Commands;

use App\Models\Offer;
use App\Services\NotificationService;
use App\Services\WaitlistService;
use Illuminate\Console\Command;

class ProcessOfferWaitlists extends Command
{
    protected $signature='offers:process-waitlists';
    protected $description='Expire 24-hour reservations and allocate free offer capacity in FIFO order';

    public function handle(WaitlistService $waitlists, NotificationService $notifications): int
    {
        $waitlists->expireReservations($notifications);

        Offer::where('active',true)
            ->whereNotNull('capacity')
            ->orderBy('id')
            ->chunkById(100,function($offers) use($waitlists,$notifications){
                foreach($offers as $offer){
                    while($waitlists->availableSlots($offer)>0){
                        $allocated=$waitlists->allocateNext($offer,$notifications);
                        if(!$allocated) break;
                    }
                }
            });

        return self::SUCCESS;
    }
}
