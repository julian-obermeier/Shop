<?php
namespace App\Services;

use App\Models\Order;
use App\Models\ReliabilityEvent;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserRestriction;
use Illuminate\Support\Facades\DB;

class ReliabilityService
{
    public function recordViolation(User $user, ?Order $order, string $type, string $description, array $metadata=[]): ReliabilityEvent
    {
        return DB::transaction(function() use($user,$order,$type,$description,$metadata){
            $locked=User::with('profile')->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $profile=$locked->profile()->lockForUpdate()->firstOrCreate([]);
            $count=(int)$profile->reliability_cycle_violations+1;
            $profile->update(['reliability_cycle_violations'=>$count]);

            $active=$locked->restrictions()
                ->where('active',true)
                ->where('type','reliability')
                ->lockForUpdate()
                ->first();

            if($active){
                $active->update([
                    'successful_count'=>0,
                    'progress_reset_at'=>now(),
                ]);
            }

            $rule=$this->ruleForViolationCount($count);
            if($rule){
                $restriction=$active ?: $locked->restrictions()->create([
                    'issued_by'=>null,
                    'type'=>'reliability',
                    'reason'=>'Automatische Zuverlässigkeitseinschränkung',
                    'starts_at'=>now(),
                    'active'=>true,
                    'required_successes'=>5,
                    'successful_count'=>0,
                ]);

                $restriction->update([
                    'reason'=>$rule['reason'] ?? ('Regelbasierte Einschränkung nach '.$count.' Zuverlässigkeitsverstoß/-verstößen im aktuellen Bewährungszyklus.'),
                    'max_active_orders'=>array_key_exists('max_active_orders',$rule)?(int)$rule['max_active_orders']:null,
                    'blocked_offer_ids'=>array_values(array_unique(array_map('intval',$rule['blocked_offer_ids']??[]))) ?: null,
                    'source_rule_key'=>(string)($rule['key']??'rule_'.$count),
                    'successful_count'=>0,
                    'progress_reset_at'=>now(),
                ]);
            } else {
                $restriction=$active;
            }

            return ReliabilityEvent::create([
                'user_id'=>$locked->id,
                'order_id'=>$order?->id,
                'restriction_id'=>$restriction?->id,
                'type'=>$type,
                'event_kind'=>'violation',
                'description'=>$description,
                'metadata'=>$metadata ?: null,
                'occurred_at'=>now(),
            ]);
        });
    }

    public function recordCleanCompletion(Order $order): void
    {
        DB::transaction(function() use($order){
            $order=Order::with(['user.profile','days.proofs','goodsInspection','shipment.evidences'])->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if(!$this->isCleanOrder($order)) return;

            $restrictions=$order->user->restrictions()
                ->where('active',true)
                ->where('type','reliability')
                ->lockForUpdate()
                ->get();

            if($restrictions->isEmpty()) return;

            foreach($restrictions as $restriction){
                $next=min((int)$restriction->required_successes,(int)$restriction->successful_count+1);
                $restriction->update(['successful_count'=>$next]);

                ReliabilityEvent::create([
                    'user_id'=>$order->user_id,
                    'order_id'=>$order->id,
                    'restriction_id'=>$restriction->id,
                    'type'=>'clean_order',
                    'event_kind'=>'success',
                    'description'=>'Fehlerfreier Bewährungsauftrag abgeschlossen: '.$next.' von '.$restriction->required_successes.'.',
                    'metadata'=>[
                        'successful_count'=>$next,
                        'required_successes'=>(int)$restriction->required_successes,
                    ],
                    'occurred_at'=>now(),
                ]);
            }
        });
    }

    public function liftRestriction(UserRestriction $restriction, User $admin): void
    {
        DB::transaction(function() use($restriction,$admin){
            $restriction=UserRestriction::with('user.profile')->whereKey($restriction->id)->lockForUpdate()->firstOrFail();
            abort_unless($restriction->active,422,'Diese Einschränkung ist bereits aufgehoben.');
            abort_unless((int)$restriction->successful_count >= (int)$restriction->required_successes,422,'Die Bewährungsphase ist noch nicht vollständig erfüllt.');

            $restriction->update([
                'active'=>false,
                'ends_at'=>now(),
            ]);

            $restriction->user->profile()->updateOrCreate([],[
                'reliability_cycle_violations'=>0,
            ]);

            ReliabilityEvent::create([
                'user_id'=>$restriction->user_id,
                'restriction_id'=>$restriction->id,
                'type'=>'restriction_lifted',
                'event_kind'=>'administrative',
                'description'=>'Zuverlässigkeitseinschränkung nach abgeschlossener Bewährungsphase manuell aufgehoben.',
                'metadata'=>['admin_user_id'=>$admin->id],
                'occurred_at'=>now(),
            ]);
        });
    }

    public function isCleanOrder(Order $order): bool
    {
        if((int)$order->reliability_issue_count>0) return false;

        $proofs=$order->days->flatMap(fn($day)=>$day->proofs);
        if($proofs->contains(fn($proof)=>(int)$proof->retry_number>0 || $proof->review_status==='rejected')) return false;

        if($order->shipment){
            if(in_array($order->shipment->review_status,['rejected','expired'],true)) return false;
            if($order->shipment->evidences->contains(fn($evidence)=>(int)$evidence->attempt>0)) return false;
        }

        $extras=$order->goodsInspection?->extra_results;
        if(is_array($extras) && collect($extras)->contains(fn($result)=>!((bool)($result['fulfilled']??false)))) return false;

        return true;
    }

    private function ruleForViolationCount(int $count): ?array
    {
        $rules=Setting::valueOf('reliability_rules',[
            ['key'=>'level_1','violations'=>1,'max_active_orders'=>4,'reason'=>'Auftragslimit nach erstem Zuverlässigkeitsverstoß auf 4 reduziert.'],
            ['key'=>'level_2','violations'=>2,'max_active_orders'=>3,'reason'=>'Auftragslimit nach wiederholten Zuverlässigkeitsverstößen auf 3 reduziert.'],
            ['key'=>'level_3','violations'=>3,'max_active_orders'=>2,'reason'=>'Auftragslimit nach wiederholten Zuverlässigkeitsverstößen auf 2 reduziert.'],
            ['key'=>'level_4','violations'=>4,'max_active_orders'=>1,'reason'=>'Auftragslimit nach fortgesetzten Zuverlässigkeitsverstößen auf 1 reduziert.'],
        ]);

        if(!is_array($rules)) return null;

        return collect($rules)
            ->filter(fn($rule)=>(int)($rule['violations']??PHP_INT_MAX) <= $count)
            ->sortByDesc(fn($rule)=>(int)($rule['violations']??0))
            ->first();
    }
}
