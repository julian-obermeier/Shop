<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\WalletService;
use App\Services\ReliabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoodsInspectionController extends Controller
{
    public function store(
        Request $request,
        Order $order,
        AuditService $audit,
        NotificationService $notifications,
        WalletService $wallet,
        ReliabilityService $reliability
    ){
        abort_unless($order->status==='inspection',422,'Die Warenprüfung ist in diesem Status nicht möglich.');
        abort_unless(
            $order->readyForFinalInspection(),
            422,
            'Finale Warenprüfung ist erst möglich, wenn Ausführung und alle erforderlichen Nachweise akzeptiert, die Versandnachweise angenommen und der vollständige Wareneingang bestätigt wurden.'
        );

        $data=$request->validate([
            'result'=>['required','in:accepted,rework,rejected'],
            'reason'=>['nullable','string','max:3000','required_unless:result,accepted'],
            'categories'=>['required','array'],
            'categories.*.passed'=>['required','boolean'],
            'categories.*.points'=>['required','integer','min:0','max:10'],
            'categories.*.comment'=>['nullable','string','max:1000'],
            'manual_base_percentage'=>['nullable','numeric','min:0','max:100'],
            'extras'=>['nullable','array'],
            'extras.*.fulfilled'=>['nullable','boolean'],
            'extras.*.comment'=>['nullable','string','max:1000'],
        ]);

        $allowedCategories=[
            'appearance'=>'Aussehen',
            'smell'=>'Geruch',
            'taste'=>'Geschmack',
            'proofs'=>'Nachweise',
            'extras'=>'Extras',
        ];

        foreach(array_keys($allowedCategories) as $key){
            abort_unless(array_key_exists($key,$data['categories']),422,'Prüfkategorie '.$allowedCategories[$key].' fehlt.');
        }

        $before=$order->toArray();
        $inspectionConfig=data_get($order->current_requirements ?: $order->offer_snapshot,'inspection_config',[]);
        $categories=[];
        $totalPoints=0;
        $koFailed=false;

        foreach($allowedCategories as $key=>$label){
            $row=$data['categories'][$key];
            $passed=(bool)$row['passed'];
            $points=(int)$row['points'];
            $ko=(bool)data_get($inspectionConfig,"categories.$key.ko",false);

            $categories[$key]=[
                'label'=>$label,
                'passed'=>$passed,
                'points'=>$points,
                'comment'=>$row['comment']??null,
                'ko'=>$ko,
            ];
            $totalPoints+=$points;
            if($ko && !$passed) $koFailed=true;
        }

        $base=(float)data_get($order->offer_snapshot,'base_compensation',0);
        $pointsAffect=(bool)data_get($inspectionConfig,'points_affect_compensation',false);

        if($pointsAffect){
            $basePercentage=$this->percentageForPoints($totalPoints,data_get($inspectionConfig,'score_bands',[]));
        } else {
            $basePercentage=(float)($data['manual_base_percentage']??100);
        }

        $extraResults=[];
        $extraTotal=0.0;
        $contractedExtrasTotal=$order->options->sum(fn($option)=>(float)$option->price_delta);
        $additionalCompensation=max(
            0.0,
            round((float)$order->compensation_total - $base - $contractedExtrasTotal,2)
        );

        foreach($order->options as $option){
            $row=$data['extras'][$option->id]??[];
            $fulfilled=(bool)($row['fulfilled']??false);
            $extraResults[(string)$option->id]=[
                'name'=>$option->name,
                'fulfilled'=>$fulfilled,
                'amount'=>(float)$option->price_delta,
                'comment'=>$row['comment']??null,
            ];
            if($fulfilled) $extraTotal+=(float)$option->price_delta;
        }

        $calculated=$koFailed
            ? 0.0
            : round(($base*($basePercentage/100))+$extraTotal+$additionalCompensation,2);

        if($data['result']==='rejected') $calculated=0.0;

        DB::transaction(function() use(
            $request,$order,$data,$categories,$totalPoints,$basePercentage,$extraResults,$calculated,$koFailed,$additionalCompensation
        ){
            $order->goodsInspection()->updateOrCreate([],[
                'reviewed_by'=>$request->user()->id,
                'categories'=>$categories+[
                    '_total_points'=>$totalPoints,
                    '_ko_failed'=>$koFailed,
                    '_additional_compensation'=>$additionalCompensation,
                ],
                'base_percentage'=>$basePercentage,
                'extra_results'=>$extraResults,
                'calculated_compensation'=>$calculated,
                'result'=>$data['result'],
                'reason'=>$data['reason']??null,
                'reviewed_at'=>now(),
            ]);

            if($data['result']==='accepted'){
                $order->update([
                    'status'=>'accepted',
                    'final_compensation'=>$calculated,
                ]);
                $order->statusHistory()->create([
                    'changed_by'=>$request->user()->id,
                    'from_status'=>'inspection',
                    'to_status'=>'accepted',
                    'reason'=>'Warenprüfung angenommen; berechnete Vergütung '.$calculated.' EUR',
                ]);
            } elseif($data['result']==='rejected'){
                $order->update([
                    'status'=>'rejected',
                    'final_compensation'=>0,
                    'completed_at'=>now(),
                ]);
                $order->statusHistory()->create([
                    'changed_by'=>$request->user()->id,
                    'from_status'=>'inspection',
                    'to_status'=>'rejected',
                    'reason'=>$data['reason'],
                ]);
            } else {
                $order->statusHistory()->create([
                    'changed_by'=>$request->user()->id,
                    'from_status'=>'inspection',
                    'to_status'=>'inspection',
                    'reason'=>'Nachbesserung/Korrektur erforderlich: '.$data['reason'],
                ]);
            }
        });

        $audit->log('goods.inspection.completed',$order,$before,$order->fresh()->toArray());

        if($data['result']==='accepted'){
            $failedExtras=collect($extraResults)->filter(fn($row)=>!((bool)($row['fulfilled']??false)));
            if($failedExtras->isNotEmpty()){
                $freshOrder=$order->fresh(['user']);
                $freshOrder->update([
                    'reliability_issue_count'=>DB::raw('reliability_issue_count + 1'),
                    'last_reliability_issue'=>'Mindestens ein vereinbartes Extra wurde bei der Warenprüfung abgelehnt',
                ]);
                $reliability->recordViolation(
                    $freshOrder->user,
                    $freshOrder->fresh(),
                    'extra_rejected',
                    'Nicht vollständig erfüllte Zusatzoption(en): '.$failedExtras->pluck('name')->implode(', '),
                    ['extra_ids'=>$failedExtras->keys()->values()->all()]
                );
            }

            $wallet->release($order->fresh());
            $notifications->send(
                $order->user,
                'goods_accepted',
                'Ware angenommen und Vergütung freigegeben',
                'Die Warenprüfung für Auftrag #'.$order->order_number.' ist abgeschlossen. '.$calculated.' € wurden sofort als verfügbares Wallet-Guthaben freigegeben.',
                route('wallet.index')
            );
            return back()->with('success','Warenprüfung abgeschlossen; Vergütung wurde direkt ins verfügbare Wallet freigegeben.');
        }

        if($data['result']==='rejected'){
            $deadline=now('Europe/Berlin')->startOfDay()->addDays(3)->endOfDay();
            $notifications->send(
                $order->user,
                'goods_rejected',
                'Ware vollständig abgelehnt',
                'Die Ware zu Auftrag #'.$order->order_number.' wurde abgelehnt. Du kannst bis '.$deadline->format('d.m.Y H:i').' Uhr eine Rücksendung auf eigene Kosten verlangen. Grund: '.$data['reason'],
                route('orders.show',$order)
            );
        } else {
            $notifications->send(
                $order->user,
                'goods_rework',
                'Korrektur/Nachbesserung erforderlich',
                'Bei Auftrag #'.$order->order_number.' ist eine Korrektur/Nachbesserung erforderlich: '.$data['reason'],
                route('orders.show',$order)
            );
        }

        return back()->with('success','Warenprüfung wurde gespeichert.');
    }

    private function percentageForPoints(int $points, mixed $bands): float
    {
        if(!is_array($bands) || !$bands) return 100.0;

        foreach($bands as $band){
            $min=(int)($band['min']??0);
            $max=(int)($band['max']??0);
            if($points>=$min && $points<=$max) return (float)($band['percentage']??0);
        }

        return 0.0;
    }
}
