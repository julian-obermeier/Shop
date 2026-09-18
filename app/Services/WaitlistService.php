<?php
namespace App\Services;

use App\Models\Offer;
use App\Models\OfferWaitlistEntry;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WaitlistService
{
    public function availableSlots(Offer $offer): int
    {
        if(!$offer->capacity) return PHP_INT_MAX;

        $activeOrders=Order::where('offer_id',$offer->id)
            ->whereNotIn('status',['completed','cancelled','rejected','request_rejected','not_started'])
            ->count();

        $reservations=OfferWaitlistEntry::where('offer_id',$offer->id)
            ->where('status','reserved')
            ->where('reservation_expires_at','>',now())
            ->count();

        return max(0,(int)$offer->capacity-$activeOrders-$reservations);
    }

    public function allocateNext(Offer $offer, NotificationService $notifications): ?OfferWaitlistEntry
    {
        if(!$offer->active || $this->availableSlots($offer)<=0) return null;

        return DB::transaction(function() use($offer,$notifications){
            $lockedOffer=Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if(!$lockedOffer->active || $this->availableSlots($lockedOffer)<=0) return null;

            $entries=OfferWaitlistEntry::with('user')
                ->where('offer_id',$lockedOffer->id)
                ->where('status','waiting')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            foreach($entries as $entry){
                $user=$entry->user;
                if(!$user || $user->status!=='active') continue;
                if($user->effectiveOrderLimit()===0 || $user->isOfferBlocked($lockedOffer->id)) continue;

                $activeCount=Order::where('user_id',$user->id)
                    ->countsAgainstPersonalLimit()
                    ->count();

                if($activeCount >= $user->effectiveOrderLimit()) continue;

                if(Order::where('user_id',$user->id)
                    ->where('offer_id',$lockedOffer->id)
                    ->whereNotIn('status',['completed','cancelled','rejected','request_rejected','not_started'])
                    ->exists()){
                    continue;
                }

                if($lockedOffer->is_sock_wearing && $entry->planned_start_date){
                    $entry->planned_start_date=$this->nextSockDate($user,$lockedOffer,$entry->planned_start_date,$entry->id);
                }

                $entry->status='reserved';
                $entry->reserved_at=now();
                $entry->reservation_expires_at=now()->addHours(24);
                $entry->save();

                $notifications->send(
                    $user,
                    'waitlist_reserved',
                    'Platz auf der Warteliste verfügbar',
                    'Für „'.$lockedOffer->title.'“ ist jetzt exklusiv für dich ein Platz reserviert. Du hast 24 Stunden Zeit, eine Auftragsanfrage zu stellen.',
                    route('offers.show',$lockedOffer),
                    ['offer_id'=>$lockedOffer->id,'waitlist_entry_id'=>$entry->id]
                );

                return $entry;
            }

            return null;
        });
    }

    public function expireReservations(NotificationService $notifications): int
    {
        $count=0;

        OfferWaitlistEntry::with(['offer','user'])
            ->where('status','reserved')
            ->whereHas('offer',fn($q)=>$q->where('active',true))
            ->where('reservation_expires_at','<=',now())
            ->orderBy('id')
            ->chunkById(100,function($entries) use(&$count,$notifications){
                foreach($entries as $entry){
                    DB::transaction(function() use($entry,&$count,$notifications){
                        $fresh=OfferWaitlistEntry::whereKey($entry->id)->lockForUpdate()->first();
                        if(!$fresh || $fresh->status!=='reserved' || !$fresh->reservation_expires_at || $fresh->reservation_expires_at->isFuture()) return;

                        $fresh->update([
                            'status'=>'removed',
                            'reserved_at'=>null,
                            'reservation_expires_at'=>null,
                        ]);
                        $count++;

                        if($fresh->user){
                            $notifications->send(
                                $fresh->user,
                                'waitlist_expired',
                                'Wartelistenreservierung abgelaufen',
                                'Die 24-Stunden-Reservierung für „'.$fresh->offer?->title.'“ ist abgelaufen. Du wurdest von dieser Warteliste entfernt.',
                                $fresh->offer?route('offers.show',$fresh->offer):route('offers.index'),
                                ['offer_id'=>$fresh->offer_id]
                            );
                        }
                    });

                    if($entry->offer) $this->allocateNext($entry->offer,$notifications);
                }
            });

        return $count;
    }

    public function assertSockWaitlistCompatibility(User $user, Offer $offer, CarbonImmutable $planned, ?int $ignoreEntryId=null): void
    {
        if(!$offer->is_sock_wearing) return;

        $end=$planned->addDays((int)$offer->duration_days);

        $orders=Order::where('user_id',$user->id)
            ->whereIn('status',['precheck','precheck_resubmit','approved','waiting_start','awaiting_date_confirmation','active','paused'])
            ->whereNotNull('confirmed_start_date')
            ->get()
            ->filter(fn(Order $order)=>$order->isSockWearing());

        foreach($orders as $order){
            $otherStart=CarbonImmutable::parse($order->activation_date ?: $order->confirmed_start_date,'Europe/Berlin');
            $otherEnd=$order->end_date
                ? CarbonImmutable::parse($order->end_date,'Europe/Berlin')
                : $otherStart->addDays((int)data_get($order->offer_snapshot,'duration_days',1));

            abort_if($planned->lte($otherEnd) && $end->gte($otherStart),422,'Der geplante Zeitraum überschneidet sich mit einem bereits bestätigten Socken-Trageauftrag.');
        }

        $entries=OfferWaitlistEntry::with('offer')
            ->where('user_id',$user->id)
            ->whereIn('status',['waiting','reserved'])
            ->whereNotNull('planned_start_date')
            ->when($ignoreEntryId,fn($q)=>$q->where('id','!=',$ignoreEntryId))
            ->get()
            ->filter(fn($entry)=>$entry->offer?->is_sock_wearing);

        foreach($entries as $entry){
            $otherStart=CarbonImmutable::parse($entry->planned_start_date,'Europe/Berlin');
            $otherEnd=$otherStart->addDays((int)$entry->offer->duration_days);
            abort_if($planned->lte($otherEnd) && $end->gte($otherStart),422,'Der geplante Zeitraum überschneidet sich mit einer bestehenden Socken-Wartelistenplanung.');
        }
    }

    private function nextSockDate(User $user, Offer $offer, $plannedStart, int $ignoreEntryId): string
    {
        $candidate=CarbonImmutable::parse($plannedStart,'Europe/Berlin')->startOfDay();
        $duration=(int)$offer->duration_days;

        for($guard=0;$guard<3650;$guard++){
            $end=$candidate->addDays($duration);
            $conflictEnd=null;

            $orders=Order::where('user_id',$user->id)
                ->whereIn('status',['precheck','precheck_resubmit','approved','waiting_start','awaiting_date_confirmation','active','paused'])
                ->whereNotNull('confirmed_start_date')
                ->get()
                ->filter(fn(Order $order)=>$order->isSockWearing());

            foreach($orders as $order){
                $otherStart=CarbonImmutable::parse($order->activation_date ?: $order->confirmed_start_date,'Europe/Berlin');
                $otherEnd=$order->end_date
                    ? CarbonImmutable::parse($order->end_date,'Europe/Berlin')
                    : $otherStart->addDays((int)data_get($order->offer_snapshot,'duration_days',1));
                if($candidate->lte($otherEnd) && $end->gte($otherStart)){
                    $conflictEnd=!$conflictEnd || $otherEnd->gt($conflictEnd)?$otherEnd:$conflictEnd;
                }
            }

            $entries=OfferWaitlistEntry::with('offer')
                ->where('user_id',$user->id)
                ->where('id','!=',$ignoreEntryId)
                ->where('status','reserved')
                ->whereNotNull('planned_start_date')
                ->get()
                ->filter(fn($entry)=>$entry->offer?->is_sock_wearing);

            foreach($entries as $entry){
                $otherStart=CarbonImmutable::parse($entry->planned_start_date,'Europe/Berlin');
                $otherEnd=$otherStart->addDays((int)$entry->offer->duration_days);
                if($candidate->lte($otherEnd) && $end->gte($otherStart)){
                    $conflictEnd=!$conflictEnd || $otherEnd->gt($conflictEnd)?$otherEnd:$conflictEnd;
                }
            }

            if(!$conflictEnd) return $candidate->toDateString();
            $candidate=$conflictEnd->addDay();
        }

        abort(422,'Für die Wartelistenreservierung konnte kein konfliktfreier Sockenzeitraum ermittelt werden.');
    }
}
