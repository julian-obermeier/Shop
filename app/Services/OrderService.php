<?php
namespace App\Services;

use App\Models\Offer;
use App\Models\OfferWaitlistEntry;
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(private OfferPricingService $pricing, private ReliabilityService $reliability, private NotificationService $notifications) {}

    public function create(User $user, Offer $offer, array $optionIds, array $fieldValues, string $proposedStartDate): Order
    {
        return DB::transaction(function () use ($user,$offer,$optionIds,$fieldValues,$proposedStartDate) {
            $lockedOffer=Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedOffer->active,404);

            $reservation=OfferWaitlistEntry::where('offer_id',$lockedOffer->id)
                ->where('user_id',$user->id)
                ->where('status','reserved')
                ->where('reservation_expires_at','>',now())
                ->lockForUpdate()
                ->first();

            if($reservation?->planned_start_date){
                $proposedStartDate=$reservation->planned_start_date->toDateString();
            }

            if($lockedOffer->capacity){
                $used=Order::where('offer_id',$lockedOffer->id)
                    ->whereNotIn('status',['completed','cancelled','rejected','request_rejected','not_started'])
                    ->lockForUpdate()
                    ->count();

                $reservedByOthers=OfferWaitlistEntry::where('offer_id',$lockedOffer->id)
                    ->where('status','reserved')
                    ->where('reservation_expires_at','>',now())
                    ->when($reservation,fn($q)=>$q->where('id','!=',$reservation->id))
                    ->lockForUpdate()
                    ->count();

                abort_if(
                    $used+$reservedByOthers >= (int)$lockedOffer->capacity,
                    422,
                    'Dieses Angebot ist derzeit vollständig belegt oder für eine Person auf der Warteliste reserviert.'
                );
            }

            abort_if(
                Order::where('user_id',$user->id)
                    ->where('offer_id',$lockedOffer->id)
                    ->whereNotIn('status',['completed','cancelled','rejected','request_rejected','not_started'])
                    ->exists(),
                422,
                'Dasselbe Angebot kann nur einmal gleichzeitig offen oder aktiv sein.'
            );

            $start=CarbonImmutable::parse($proposedStartDate,'Europe/Berlin')->startOfDay();
            abort_if($start->lt(CarbonImmutable::today('Europe/Berlin')),422,'Das vorgeschlagene Startdatum darf nicht in der Vergangenheit liegen.');

            $requiredIds=$lockedOffer->options()->where('active',true)->where('required',true)->pluck('id')->all();
            $optionIds=array_values(array_unique(array_map('intval',array_merge($optionIds,$requiredIds))));
            $options=$lockedOffer->options()->where('active',true)->whereIn('id',$optionIds)->get();
            abort_if(count($optionIds)!==$options->count(),422,'Ungültige Zusatzoption.');

            $calc=$this->pricing->calculate($lockedOffer,$options);
            $fields=$lockedOffer->fields()->where('active',true)->get();
            $normalizedFields=$this->normalizeFieldValues($fields,$fieldValues);

            $proofRequirements=$lockedOffer->proof_requirements;
            if(!is_array($proofRequirements) || !$proofRequirements){
                $proofRequirements=[[
                    'key'=>'default',
                    'label'=>'Tagesnachweis',
                    'start'=>'00:00',
                    'end'=>'23:59',
                    'required_images'=>(int)$lockedOffer->proofs_per_day,
                    'text_required'=>false,
                    'face_required'=>false,
                ]];
            }

            foreach($options as $option){
                $extraProofs=(int)$option->extra_proofs_per_day;
                if($extraProofs<=0) continue;

                $proofRequirements[]=[
                    'key'=>'extra_option_'.$option->id,
                    'label'=>'Extra-Nachweis: '.$option->name,
                    'start'=>'00:00',
                    'end'=>'23:59',
                    'required_images'=>$extraProofs,
                    'text_required'=>false,
                    'face_required'=>false,
                    'offer_option_id'=>$option->id,
                ];
            }

            $snapshot=[
                'offer_id'=>$lockedOffer->id,
                'title'=>$lockedOffer->title,
                'category'=>$lockedOffer->category?->name,
                'description'=>$lockedOffer->description,
                'image_path'=>$lockedOffer->image_path,
                'base_compensation'=>(float)$lockedOffer->base_compensation,
                'duration_days'=>$calc['duration_days'],
                'proofs_per_day'=>$calc['proofs_per_day'],
                'minimum_minutes_per_day'=>$lockedOffer->minimum_minutes_per_day,
                'shipping_deadline_hours'=>24,
                'tracking_mode'=>$lockedOffer->tracking_mode ?: 'optional',
                'requires_precheck'=>$lockedOffer->requires_precheck,
                'is_sock_wearing'=>(bool)$lockedOffer->is_sock_wearing,
                'rules'=>$lockedOffer->rules,
                'proof_requirements'=>$proofRequirements,
                'inspection_config'=>$lockedOffer->inspection_config,
                'fields'=>$fields->map(fn($field)=>$field->toArray())->values()->all(),
            ];

            $order=Order::create([
                'order_number'=>'TMP-'.Str::uuid(),
                'user_id'=>$user->id,
                'offer_id'=>$lockedOffer->id,
                'status'=>'requested',
                'compensation_total'=>$calc['total'],
                'offer_snapshot'=>$snapshot,
                'current_requirements'=>$snapshot,
                'proposed_start_date'=>$start->toDateString(),
            ]);

            $order->update([
                'order_number'=>now('Europe/Berlin')->format('Y').str_pad((string)$order->id,7,'0',STR_PAD_LEFT),
            ]);

            foreach($options as $option){
                $order->options()->create([
                    'offer_option_id'=>$option->id,
                    'name'=>$option->name,
                    'price_delta'=>$option->price_delta,
                    'snapshot'=>$option->toArray(),
                ]);
            }

            foreach($normalizedFields as $entry) $order->fieldValues()->create($entry);

            $order->statusHistory()->create([
                'changed_by'=>$user->id,
                'to_status'=>'requested',
                'reason'=>'Auftragsanfrage mit Startwunsch '.$start->format('d.m.Y').' erstellt',
            ]);

            if($reservation){
                $reservation->update([
                    'status'=>'used',
                    'reservation_expires_at'=>null,
                ]);
            }

            return $order;
        });
    }

    public function approve(Order $order, User $admin, ?string $date=null): void
    {
        DB::transaction(function() use($order,$admin,$date){
            $order=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lockedUser=User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            $order->setRelation('user',$lockedUser);

            abort_unless(in_array($order->status,['requested','awaiting_date_confirmation'],true),422,'Dieser Antrag kann nicht bestätigt werden.');

            $start=CarbonImmutable::parse($date ?: $order->proposed_start_date,'Europe/Berlin')->startOfDay();
            abort_if($start->lt(CarbonImmutable::today('Europe/Berlin')),422,'Der bestätigte Start darf nicht in der Vergangenheit liegen.');

            $count=Order::where('user_id',$order->user_id)
                ->where('id','!=',$order->id)
                ->countsAgainstPersonalLimit()
                ->lockForUpdate()
                ->count();
            abort_if($count >= $order->user->effectiveOrderLimit(),422,'Das persönliche Auftragslimit der Anbieterin ist erreicht.');

            if($order->isSockWearing()) $this->assertSockSlotSchedule($order,$start);

            $from=$order->status;
            $to=data_get($order->offer_snapshot,'requires_precheck',false)?'precheck':'approved';
            $order->update([
                'status'=>$to,
                'confirmed_start_date'=>$start->toDateString(),
                'proposed_start_date'=>$start->toDateString(),
                'accepted_at'=>now(),
            ]);
            $order->statusHistory()->create([
                'changed_by'=>$admin->id,
                'from_status'=>$from,
                'to_status'=>$to,
                'reason'=>'Auftrag und Startdatum '.$start->format('d.m.Y').' bestätigt',
            ]);
        });
    }

    public function proposeAlternateDate(Order $order, User $admin, string $date): void
    {
        DB::transaction(function() use($order,$admin,$date){
            $order=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->status==='requested',422,'Ein neuer Terminvorschlag ist in diesem Status nicht möglich.');
            $start=CarbonImmutable::parse($date,'Europe/Berlin')->startOfDay();
            abort_if($start->lt(CarbonImmutable::today('Europe/Berlin')),422,'Der Terminvorschlag darf nicht in der Vergangenheit liegen.');

            $order->update([
                'status'=>'awaiting_date_confirmation',
                'proposed_start_date'=>$start->toDateString(),
            ]);
            $order->statusHistory()->create([
                'changed_by'=>$admin->id,
                'from_status'=>'requested',
                'to_status'=>'awaiting_date_confirmation',
                'reason'=>'Admin schlägt Startdatum '.$start->format('d.m.Y').' vor',
            ]);
        });
    }

    public function acceptAlternateDate(Order $order, User $user): void
    {
        abort_unless($order->user_id===$user->id,403);
        abort_unless($order->status==='awaiting_date_confirmation',422,'Es liegt kein offener Terminvorschlag vor.');

        $admin=User::where('role','admin')->where('status','active')->firstOrFail();
        $this->approve($order,$admin,(string)$order->proposed_start_date?->toDateString());
    }

    public function prepareStart(Order $order, User $user): OrderDay
    {
        return DB::transaction(function() use($order,$user){
            $order=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->user_id===$user->id,403);
            abort_unless(in_array($order->status,['approved','waiting_start'],true),422,'Der Auftrag kann aktuell nicht aktiviert werden.');
            abort_unless($order->confirmed_start_date,422,'Es fehlt ein bestätigtes Startdatum.');

            if($order->isSockWearing()){
                $pausedSockExists=Order::where('user_id',$order->user_id)
                    ->where('id','!=',$order->id)
                    ->where('status','paused')
                    ->lockForUpdate()
                    ->get()
                    ->contains(fn(Order $candidate)=>$candidate->isSockWearing());

                abort_if(
                    $pausedSockExists,
                    422,
                    'Ein pausierter Socken-Trageauftrag blockiert weiterhin den Socken-Trageplatz. Dieser Auftrag wird erst nach Fortsetzung bzw. Terminverschiebung startbar.'
                );
            }

            $today=CarbonImmutable::today('Europe/Berlin');
            abort_unless($order->confirmed_start_date->isSameDay($today),422,'Die Aktivierung ist ausschließlich am bestätigten Startdatum möglich.');

            if($order->status!=='waiting_start'){
                $order->update(['status'=>'waiting_start']);
                $order->statusHistory()->create([
                    'changed_by'=>$user->id,
                    'from_status'=>'approved',
                    'to_status'=>'waiting_start',
                    'reason'=>'Aktivierung bestätigt; Startfoto erforderlich',
                ]);
            }

            return $order->days()->firstOrCreate(
                ['series_number'=>$order->series_number,'day_number'=>0],
                [
                    'date'=>$today->toDateString(),
                    'required_proofs'=>1,
                    'status'=>'activation',
                    'counts_toward_series'=>true,
                ]
            );
        });
    }

    public function activateAfterStartProof(OrderDay $day): void
    {
        DB::transaction(function() use($day){
            $order=Order::whereKey($day->order_id)->lockForUpdate()->firstOrFail();
            abort_unless($day->day_number===0 && $day->series_number===$order->series_number,422,'Ungültiger Startnachweis.');
            abort_unless($order->status==='waiting_start',422,'Der Auftrag wartet nicht auf ein Startfoto.');

            $activation=CarbonImmutable::parse($day->date,'Europe/Berlin');
            $firstDay=$activation->addDay();
            $duration=(int)data_get($order->offer_snapshot,'duration_days',1);
            $required=$this->requiredProofCount($order);

            $order->update([
                'status'=>'active',
                'activation_date'=>$activation->toDateString(),
                'start_date'=>$firstDay->toDateString(),
                'end_date'=>$firstDay->addDays($duration-1)->toDateString(),
            ]);

            for($i=1;$i<=$duration;$i++){
                $order->days()->firstOrCreate(
                    ['series_number'=>$order->series_number,'day_number'=>$i],
                    [
                        'date'=>$firstDay->addDays($i-1)->toDateString(),
                        'required_proofs'=>$required,
                        'status'=>'open',
                        'counts_toward_series'=>true,
                    ]
                );
            }

            $order->statusHistory()->create([
                'changed_by'=>$order->user_id,
                'from_status'=>'waiting_start',
                'to_status'=>'active',
                'reason'=>'Startfoto eingereicht; Tag 1 beginnt am '.$firstDay->format('d.m.Y'),
            ]);
        });
    }

    public function completeExecution(Order $order, User $user): void
    {
        DB::transaction(function() use($order,$user){
            $order=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->user_id===$user->id,403);
            abort_unless($order->status==='active',422,'Der Auftrag ist nicht in der aktiven Erfüllungsphase.');

            abort_unless(
                $order->executionProofsAccepted(),
                422,
                'Startfoto und alle erforderlichen gültigen Tragetage müssen vollständig akzeptiert sein, bevor die Erfüllungsphase abgeschlossen werden kann.'
            );

            $order->update([
                'status'=>'waiting_shipping',
                'execution_completed_at'=>now(),
                'shipping_due_at'=>now()->addHours(24),
            ]);
            $order->statusHistory()->create([
                'changed_by'=>$user->id,
                'from_status'=>'active',
                'to_status'=>'waiting_shipping',
                'reason'=>'Erfüllungsphase vollständig abgeschlossen',
            ]);
        });
    }

    public function voluntaryAbort(Order $order, User $user, string $reason): void
    {
        DB::transaction(function() use($order,$user,$reason){
            $order=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->user_id===$user->id,403);
            abort_unless(in_array($order->status,['precheck','precheck_resubmit','approved','waiting_start','active','paused'],true),422,'Dieser Auftrag kann nicht mehr freiwillig abgebrochen werden.');

            $from=$order->status;
            $order->update([
                'status'=>'cancelled',
                'final_compensation'=>0,
                'reliability_issue_count'=>DB::raw('reliability_issue_count + 1'),
                'last_reliability_issue'=>'Freiwilliger Abbruch: '.$reason,
                'completed_at'=>now(),
            ]);
            $order->statusHistory()->create([
                'changed_by'=>$user->id,
                'from_status'=>$from,
                'to_status'=>'cancelled',
                'reason'=>'Freiwilliger Abbruch durch Anbieterin: '.$reason,
            ]);
            $this->reliability->recordViolation($user,$order->fresh(),'voluntary_abort','Freiwilliger Abbruch: '.$reason);
        });
    }

    public function invalidateDay(OrderDay $day, string $reason): void
    {
        DB::transaction(function() use($day,$reason){
            $day=OrderDay::with('order.user')->whereKey($day->id)->lockForUpdate()->firstOrFail();
            $order=Order::whereKey($day->order_id)->lockForUpdate()->firstOrFail();
            if(!$day->counts_toward_series || $day->status==='invalid') return;

            $day->update([
                'status'=>'invalid',
                'counts_toward_series'=>false,
                'invalid_reason'=>$reason,
            ]);

            $order->update([
                'reliability_issue_count'=>DB::raw('reliability_issue_count + 1'),
                'last_reliability_issue'=>$reason,
            ]);
            $this->reliability->recordViolation($order->user,$order,'invalid_day',$reason,['order_day_id'=>$day->id]);

            if($day->day_number===0){
                $this->restartAfterInvalidStart($order);
                return;
            }

            if((int)$order->series_interruptions===0){
                $order->update(['series_interruptions'=>1]);
                $this->appendReplacementDay($order);
            } else {
                $this->restartSeries($order,$day);
            }
        });
    }

    private function appendReplacementDay(Order $order): void
    {
        $last=$order->days()
            ->where('series_number',$order->series_number)
            ->reorder('date','desc')
            ->orderByDesc('day_number')
            ->first();

        $nextDate=CarbonImmutable::parse($last?->date ?: $order->end_date,'Europe/Berlin')->addDay();
        $nextNumber=((int)$order->days()->where('series_number',$order->series_number)->max('day_number'))+1;

        $order->days()->create([
            'series_number'=>$order->series_number,
            'day_number'=>$nextNumber,
            'date'=>$nextDate->toDateString(),
            'required_proofs'=>$this->requiredProofCount($order),
            'status'=>'open',
            'counts_toward_series'=>true,
        ]);
        $order->update(['end_date'=>$nextDate->toDateString()]);
        $order->statusHistory()->create([
            'changed_by'=>null,
            'from_status'=>'active',
            'to_status'=>'active',
            'reason'=>'Ungültiger Tag wurde am Serienende ersetzt',
        ]);

        $this->shiftSockFollowers($order);
    }

    private function restartSeries(Order $order, OrderDay $failedDay): void
    {
        $oldSeries=(int)$order->series_number;
        $newSeries=$oldSeries+1;
        $duration=(int)data_get($order->offer_snapshot,'duration_days',1);
        $start=CarbonImmutable::parse($failedDay->date,'Europe/Berlin')->addDay();

        $order->days()->where('series_number',$oldSeries)->update(['counts_toward_series'=>false]);
        $order->update([
            'series_number'=>$newSeries,
            'series_interruptions'=>0,
            'start_date'=>$start->toDateString(),
            'end_date'=>$start->addDays($duration-1)->toDateString(),
        ]);

        for($i=1;$i<=$duration;$i++){
            $order->days()->create([
                'series_number'=>$newSeries,
                'day_number'=>$i,
                'date'=>$start->addDays($i-1)->toDateString(),
                'required_proofs'=>$this->requiredProofCount($order),
                'status'=>'open',
                'counts_toward_series'=>true,
            ]);
        }

        $order->statusHistory()->create([
            'changed_by'=>null,
            'from_status'=>'active',
            'to_status'=>'active',
            'reason'=>'Zweite Unterbrechung: Trageserie beginnt erneut bei Tag 1',
        ]);
        $this->shiftSockFollowers($order);
    }

    private function restartAfterInvalidStart(Order $order): void
    {
        $oldSeries=(int)$order->series_number;
        $newSeries=$oldSeries+1;
        $today=CarbonImmutable::today('Europe/Berlin');

        $order->days()->where('series_number',$oldSeries)->update(['counts_toward_series'=>false]);
        $order->update([
            'status'=>'waiting_start',
            'series_number'=>$newSeries,
            'series_interruptions'=>0,
            'confirmed_start_date'=>$today->toDateString(),
            'proposed_start_date'=>$today->toDateString(),
            'activation_date'=>null,
            'start_date'=>null,
            'end_date'=>null,
        ]);

        $order->days()->create([
            'series_number'=>$newSeries,
            'day_number'=>0,
            'date'=>$today->toDateString(),
            'required_proofs'=>1,
            'status'=>'activation',
            'counts_toward_series'=>true,
        ]);

        $order->statusHistory()->create([
            'changed_by'=>null,
            'from_status'=>'active',
            'to_status'=>'waiting_start',
            'reason'=>'Startfoto endgültig ungültig; neues Startfoto und neue Serie erforderlich. Neuer Aktivierungstag '.$today->format('d.m.Y').'.',
        ]);

        $this->shiftSockFollowers($order);
    }

    public function shiftSockFollowers(Order $order): void
    {
        $order->refresh();
        if(!$order->isSockWearing()) return;

        if($order->end_date){
            $cursorEnd=CarbonImmutable::parse($order->end_date,'Europe/Berlin');
        } elseif($order->confirmed_start_date){
            $cursorEnd=CarbonImmutable::parse($order->confirmed_start_date,'Europe/Berlin')
                ->addDays((int)data_get($order->offer_snapshot,'duration_days',1));
        } else {
            return;
        }
        $followers=Order::with('user')->where('user_id',$order->user_id)
            ->where('id','!=',$order->id)
            ->whereIn('status',['precheck','precheck_resubmit','approved','waiting_start'])
            ->whereNotNull('confirmed_start_date')
            ->orderBy('confirmed_start_date')
            ->get()
            ->filter(fn(Order $candidate)=>$candidate->isSockWearing());

        foreach($followers as $follower){
            $activation=CarbonImmutable::parse($follower->confirmed_start_date,'Europe/Berlin');
            if($activation->lte($cursorEnd)){
                $old=$activation;
                $activation=$cursorEnd->addDay();
                $follower->update([
                    'confirmed_start_date'=>$activation->toDateString(),
                    'proposed_start_date'=>$activation->toDateString(),
                ]);
                $reason='Automatisch von '.$old->format('d.m.Y').' auf '.$activation->format('d.m.Y').' verschoben, da ein vorheriger Socken-Trageauftrag verlängert wurde';

                $follower->statusHistory()->create([
                    'changed_by'=>null,
                    'from_status'=>$follower->status,
                    'to_status'=>$follower->status,
                    'reason'=>$reason,
                ]);

                $this->notifications->send(
                    $follower->user,
                    'sock_schedule_shifted',
                    'Sockenauftrag automatisch verschoben',
                    'Auftrag #'.$follower->order_number.' wurde wegen der Verlängerung eines vorherigen Sockenauftrags automatisch auf den '.$activation->format('d.m.Y').' verschoben. Eine erneute Bestätigung ist nicht erforderlich.',
                    route('orders.show',$follower),
                    ['order_id'=>$follower->id,'old_date'=>$old->toDateString(),'new_date'=>$activation->toDateString()]
                );

                $admin=User::where('role','admin')->where('status','active')->first();
                if($admin){
                    $this->notifications->send(
                        $admin,
                        'sock_schedule_shifted_admin',
                        'Socken-Terminverschiebung',
                        'Auftrag #'.$follower->order_number.' von '.$follower->user->first_name.' '.$follower->user->last_name.' wurde automatisch auf den '.$activation->format('d.m.Y').' verschoben.',
                        route('admin.orders.show',$follower),
                        ['order_id'=>$follower->id,'old_date'=>$old->toDateString(),'new_date'=>$activation->toDateString()]
                    );
                }
            }

            $duration=(int)data_get($follower->offer_snapshot,'duration_days',1);
            $cursorEnd=$activation->addDays($duration);
        }
    }

    private function assertSockSlotSchedule(Order $order, CarbonImmutable $activation): void
    {
        $duration=(int)data_get($order->offer_snapshot,'duration_days',1);
        $end=$activation->addDays($duration);

        $others=Order::where('user_id',$order->user_id)
            ->where('id','!=',$order->id)
            ->whereIn('status',['precheck','precheck_resubmit','approved','waiting_start','active','paused'])
            ->whereNotNull('confirmed_start_date')
            ->get()
            ->filter(fn(Order $candidate)=>$candidate->isSockWearing());

        foreach($others as $other){
            $otherStart=CarbonImmutable::parse($other->activation_date ?: $other->confirmed_start_date,'Europe/Berlin');
            $otherEnd=$other->end_date
                ? CarbonImmutable::parse($other->end_date,'Europe/Berlin')
                : $otherStart->addDays((int)data_get($other->offer_snapshot,'duration_days',1));

            abort_if($activation->lte($otherEnd) && $end->gte($otherStart),422,'Der geplante Socken-Tragezeitraum überschneidet sich mit einem bereits bestätigten Sockenauftrag.');
        }
    }

    public function requiredProofCount(Order $order): int
    {
        $requirements=data_get($order->current_requirements ?: $order->offer_snapshot,'proof_requirements',[]);
        if(!is_array($requirements)) return (int)data_get($order->offer_snapshot,'proofs_per_day',0);

        return collect($requirements)->sum(fn($window)=>(int)($window['required_images']??0));
    }

    private function normalizeFieldValues($fields, array $values): array
    {
        $normalized=[];

        foreach($fields as $field){
            $value=$values[$field->key]??null;

            if($field->type==='checkbox'){
                $value=in_array($value,[1,'1',true,'on'],true)?'1':'0';
            } elseif(is_string($value)){
                $value=trim($value);
            }

            if($field->required){
                abort_if($value===null || $value==='' || ($field->type==='checkbox' && $value!=='1'),422,'Das Feld „'.$field->label.'“ ist erforderlich.');
            }

            if(in_array($field->type,['select','radio'],true) && $value!==null && $value!==''){
                abort_unless(in_array((string)$value,array_map('strval',$field->options??[]),true),422,'Ungültiger Wert für „'.$field->label.'“.');
            }

            if($field->type==='number' && $value!==null && $value!==''){
                abort_unless(is_numeric($value),422,'Das Feld „'.$field->label.'“ muss eine Zahl enthalten.');
            }

            if(is_array($value)) $value=json_encode($value,JSON_UNESCAPED_UNICODE);

            $normalized[]=[
                'offer_field_id'=>$field->id,
                'label'=>$field->label,
                'key'=>$field->key,
                'value'=>$value,
                'field_snapshot'=>$field->toArray(),
            ];
        }

        return $normalized;
    }
}
