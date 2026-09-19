<?php
namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\OfferWaitlistEntry;
use App\Services\NotificationService;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfferWaitlistController extends Controller
{
    public function join(Request $request, Offer $offer, WaitlistService $waitlists)
    {
        abort_unless($offer->active,404);
        abort_unless($request->user()->hasVerifiedEmail(),422,'Bitte bestätige zuerst deine E-Mail-Adresse.');
        abort_if($request->user()->effectiveOrderLimit()===0,422,'Neue Wartelistenpositionen sind aufgrund einer aktiven Zuverlässigkeitseinschränkung derzeit gesperrt.');
        abort_if($request->user()->isOfferBlocked($offer->id),422,'Dieses Angebot ist für dein Konto aufgrund einer aktiven Zuverlässigkeitseinschränkung ausgeschlossen.');
        abort_if($waitlists->availableDirectSlots($offer)>0,422,'Für dieses Angebot ist aktuell ein direkter Platz frei; eine Warteliste ist nicht erforderlich.');

        $data=$request->validate([
            'planned_start_date'=>[$offer->is_sock_wearing?'required':'nullable','date'],
        ]);

        $planned=null;
        if(!empty($data['planned_start_date'])){
            $planned=CarbonImmutable::parse($data['planned_start_date'],'Europe/Berlin')->startOfDay();
            abort_if($planned->lt(CarbonImmutable::today('Europe/Berlin')),422,'Der geplante Start darf nicht in der Vergangenheit liegen.');
            $waitlists->assertSockWaitlistCompatibility($request->user(),$offer,$planned);
        }

        DB::transaction(function() use($request,$offer,$planned){
            $entry=OfferWaitlistEntry::where('offer_id',$offer->id)
                ->where('user_id',$request->user()->id)
                ->lockForUpdate()
                ->first();

            if($entry && in_array($entry->status,['waiting','reserved'],true)){
                abort(422,'Du stehst bereits auf dieser Warteliste.');
            }

            if(!$entry){
                OfferWaitlistEntry::create([
                    'offer_id'=>$offer->id,
                    'user_id'=>$request->user()->id,
                    'status'=>'waiting',
                    'planned_start_date'=>$planned?->toDateString(),
                ]);
            } else {
                $entry->update([
                    'status'=>'waiting',
                    'reserved_at'=>null,
                    'reservation_expires_at'=>null,
                    'planned_start_date'=>$planned?->toDateString(),
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);
            }
        });

        return back()->with('success','Du wurdest am Ende der Warteliste eingetragen.');
    }

    public function leave(Request $request, Offer $offer, WaitlistService $waitlists, NotificationService $notifications)
    {
        $entry=OfferWaitlistEntry::where('offer_id',$offer->id)
            ->where('user_id',$request->user()->id)
            ->whereIn('status',['waiting','reserved'])
            ->firstOrFail();

        $wasReserved=$entry->status==='reserved';
        $entry->update([
            'status'=>'removed',
            'reserved_at'=>null,
            'reservation_expires_at'=>null,
        ]);

        if($wasReserved) $waitlists->allocateNext($offer,$notifications);

        return back()->with('success','Du wurdest von der Warteliste entfernt.');
    }
}
