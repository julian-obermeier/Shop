<?php
namespace App\Console\Commands;

use App\Models\OrderDay;
use App\Models\Setting;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendDailyProofReminders extends Command
{
    protected $signature='proofs:remind';
    protected $description='Send proof-window reminders at window start, 30 minutes before end and 10 minutes before end';

    public function handle(NotificationService $notifications): int
    {
        if(!Setting::valueOf('proof_reminders_enabled',true)) return self::SUCCESS;

        $now=CarbonImmutable::now('Europe/Berlin');
        $candidateDates=[
            $now->toDateString(),
            $now->subDay()->toDateString(),
        ];

        $days=OrderDay::with(['order.user','proofs'])
            ->whereIn('date',$candidateDates)
            ->where('day_number','>',0)
            ->where('counts_toward_series',true)
            ->where('status','open')
            ->whereHas('order',fn($q)=>$q->where('status','active'))
            ->get();

        foreach($days as $day){
            if((int)$day->series_number!==(int)$day->order->series_number) continue;

            $windows=data_get($day->order->current_requirements ?: $day->order->offer_snapshot,'proof_requirements',[]);
            if(!is_array($windows)) continue;

            foreach($windows as $window){
                $required=(int)($window['required_images']??0);
                if($required<=0) continue;

                $key=(string)($window['key']??'default');
                $acceptedOrPending=$day->proofs
                    ->where('window_key',$key)
                    ->whereIn('review_status',['pending','accepted'])
                    ->count();
                if($acceptedOrPending >= $required) continue;

                $start=CarbonImmutable::parse($day->date->format('Y-m-d').' '.($window['start']??'00:00'),'Europe/Berlin');
                $end=CarbonImmutable::parse($day->date->format('Y-m-d').' '.($window['end']??'23:59'),'Europe/Berlin');
                if($end->lt($start)) $end=$end->addDay();

                $stages=[
                    'start'=>[$start,'Nachweisfenster geöffnet'],
                    'end30'=>[$end->subMinutes(30),'Noch 30 Minuten für den Nachweis'],
                    'end10'=>[$end->subMinutes(10),'Noch 10 Minuten für den Nachweis'],
                ];

                foreach($stages as $stage=>[$target,$title]){
                    if(!$this->isDue($now,$target)) continue;

                    $type='proof_window_'.$stage;
                    $exists=UserNotification::where('user_id',$day->order->user_id)
                        ->where('type',$type)
                        ->where('data->order_day_id',$day->id)
                        ->where('data->window_key',$key)
                        ->exists();
                    if($exists) continue;

                    $label=(string)($window['label']??$key);
                    $missing=max(0,$required-$acceptedOrPending);
                    $body=$stage==='start'
                        ? 'Das Nachweisfenster „'.$label.'“ für Auftrag #'.$day->order->order_number.' ist jetzt geöffnet. Benötigt: '.$missing.' Nachweis(e).'
                        : 'Für das Nachweisfenster „'.$label.'“ zu Auftrag #'.$day->order->order_number.' fehlen noch '.$missing.' Nachweis(e). Ende: '.$end->format('H:i').' Uhr.';

                    $notifications->send(
                        $day->order->user,
                        $type,
                        $title,
                        $body,
                        route('orders.show',$day->order),
                        [
                            'order_id'=>$day->order_id,
                            'order_day_id'=>$day->id,
                            'window_key'=>$key,
                            'stage'=>$stage,
                        ]
                    );
                }
            }
        }

        return self::SUCCESS;
    }

    private function isDue(CarbonImmutable $now, CarbonImmutable $target): bool
    {
        return $now->greaterThanOrEqualTo($target)
            && $now->lessThan($target->addMinutes(6));
    }
}
