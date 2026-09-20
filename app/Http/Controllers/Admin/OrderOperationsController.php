<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\NotificationService;
use App\Services\V1OrderWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderOperationsController extends Controller
{
    public function manualExtraDay(Request $request, Order $order, V1OrderWorkflowService $workflow, NotificationService $notifications)
    {
        abort_if($order->isTerminal(),422,'Ein beendeter Auftrag kann nicht verlängert werden.');
        $data=$request->validate([
            'paid'=>['nullable','boolean'],
            'amount'=>['nullable','numeric','min:0'],
            'reason'=>['nullable','string','max:2000'],
        ]);

        $paid=$request->boolean('paid');
        $amount=$paid?(float)($data['amount']??0):0;
        $workflow->addManualExtraDay($order,$request->user(),$paid,$amount,$data['reason']??null);

        $notifications->send(
            $order->user,
            'manual_extra_day',
            'Zusätzlicher Durchführungstag',
            'Auftrag #'.$order->order_number.' wurde um einen '.($paid?'bezahlten':'unbezahlten').' Tag verlängert.'.($paid?' Vergütung: '.number_format($amount,2,',','.').' €.':'').(!empty($data['reason'])?' Grund: '.$data['reason']:''),
            route('orders.show',$order)
        );

        return back()->with('success','Zusatztag wurde am Auftragsende angehängt.');
    }

    public function violation(Request $request, Order $order, int $violation, V1OrderWorkflowService $workflow, NotificationService $notifications)
    {
        $data=$request->validate(['decision'=>['required','in:confirm,discard']]);
        $confirm=$data['decision']==='confirm';
        $row=DB::table('violations')->where('id',$violation)->where('order_id',$order->id)->first();
        abort_unless($row,404);

        $workflow->decideViolation($order,$violation,$request->user(),$confirm);

        $notifications->send(
            $order->user,
            'violation_decision',
            $confirm?'Verstoß bestätigt':'Möglicher Verstoß verworfen',
            $confirm
                ? 'Der Verstoß zu Auftrag #'.$order->order_number.' wurde bestätigt. Ein zusätzlicher Durchführungstag wurde entsprechend der Auftragslogik berücksichtigt.'
                : 'Der mögliche Verstoß zu Auftrag #'.$order->order_number.' wurde verworfen.',
            route('orders.show',$order)
        );

        return back()->with('success',$confirm?'Verstoß bestätigt.':'Verstoß verworfen.');
    }

    public function damageDecision(Request $request, Order $order, int $damageCase, V1OrderWorkflowService $workflow, NotificationService $notifications)
    {
        $data=$request->validate([
            'decision'=>['required','in:accept,reject'],
            'note'=>['nullable','string','max:2000'],
        ]);

        $accepted=$data['decision']==='accept';
        $workflow->decideDamage($order,$damageCase,$request->user(),$accepted,$data['note']??null);

        $notifications->send(
            $order->user,
            'damage_decision',
            $accepted?'Beschädigung anerkannt':'Beschädigung nicht anerkannt',
            $accepted
                ? 'Die Beschädigung zu Auftrag #'.$order->order_number.' wurde anerkannt. Wähle einen neuen Artikel und führe die vollständige Vorabkontrolle erneut durch.'
                : 'Die Beschädigung zu Auftrag #'.$order->order_number.' wurde nicht anerkannt. Der Auftrag ist mit demselben Artikel fortzusetzen.'.(!empty($data['note'])?' '.$data['note']:''),
            route('orders.show',$order)
        );

        return back()->with('success','Beschädigungsvorgang wurde entschieden.');
    }

    public function damageEvidenceRequest(Request $request, Order $order, int $damageCase, NotificationService $notifications)
    {
        $case=DB::table('damage_cases')->where('id',$damageCase)->where('order_id',$order->id)->first();
        abort_unless($case,404);

        $data=$request->validate([
            'type'=>['required','in:photo,video,text,field'],
            'instructions'=>['required','string','max:2000'],
            'due_at'=>['required','date'],
        ]);

        DB::table('damage_evidence_requests')->insert([
            'damage_case_id'=>$damageCase,
            'type'=>$data['type'],
            'instructions'=>$data['instructions'],
            'due_at'=>$data['due_at'],
            'status'=>'open',
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);

        DB::table('damage_cases')->where('id',$damageCase)->update(['status'=>'evidence_requested','updated_at'=>now()]);

        $notifications->send(
            $order->user,
            'damage_evidence_requested',
            'Zusätzlicher Nachweis zur Beschädigung erforderlich',
            $data['instructions'].' Frist: '.\Carbon\Carbon::parse($data['due_at'])->format('d.m.Y H:i').' Uhr.',
            route('orders.show',$order)
        );

        return back()->with('success','Zusätzlicher Nachweis wurde angefordert.');
    }
}
