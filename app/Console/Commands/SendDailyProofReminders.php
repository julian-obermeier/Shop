<?php
namespace App\Console\Commands;

use App\Models\OrderDay;
use App\Models\Setting;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendDailyProofReminders extends Command
{
    protected $signature='proofs:remind';
    protected $description='Send scheduled reminders for incomplete daily proof requirements';

    public function handle(NotificationService $notifications): int
    {
        if(!Setting::valueOf('proof_reminders_enabled',true)) return self::SUCCESS;

        $hour=(int)now()->format('G');
        if(!in_array($hour,[12,18,21,23],true)) return self::SUCCESS;

        $days=OrderDay::with(['order.user','proofs'])
            ->whereDate('date',today())
            ->whereHas('order',fn($q)=>$q->where('status','active'))
            ->get();

        foreach($days as $day){
            $count=$day->proofs->whereIn('review_status',['pending','accepted'])->count();
            $missing=max(0,$day->required_proofs-$count);
            if($missing===0) continue;

            $type='proof_reminder_'.$hour;
            $exists=UserNotification::where('user_id',$day->order->user_id)
                ->where('type',$type)
                ->whereDate('created_at',today())
                ->where('data->order_day_id',$day->id)
                ->exists();
            if($exists) continue;

            $notifications->send(
                $day->order->user,
                $type,
                'Tagesnachweis noch unvollständig',
                'Für Auftrag #'.$day->order->order_number.' fehlen heute noch '.$missing.' Nachweis(e).',
                route('orders.show',$day->order),
                ['order_day_id'=>$day->id,'missing'=>$missing]
            );
        }

        return self::SUCCESS;
    }
}
