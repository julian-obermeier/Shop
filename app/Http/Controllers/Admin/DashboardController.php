<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPrecheck;
use App\Models\PayoutRequest;
use App\Models\ProofSubmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $now=CarbonImmutable::now('Europe/Berlin');
        $today=$now->toDateString();

        $queues=[
            'prechecks'=>OrderPrecheck::with('order.user')->whereIn('status',['submitted','resubmit'])->latest('submitted_at')->take(8)->get(),
            'proofs'=>ProofSubmission::with('orderDay.order.user')->where('review_status','pending')->latest()->take(8)->get(),
            'violations'=>DB::table('violations')->join('orders','orders.id','=','violations.order_id')->whereIn('violations.status',['open','reviewed'])->select('violations.*','orders.order_number')->orderByDesc('violations.id')->limit(8)->get(),
            'damage'=>DB::table('damage_cases')->join('orders','orders.id','=','damage_cases.order_id')->whereIn('damage_cases.status',['reported','evidence_requested','review'])->select('damage_cases.*','orders.order_number')->orderByDesc('damage_cases.id')->limit(8)->get(),
            'digital'=>DB::table('digital_components')->join('orders','orders.id','=','digital_components.order_id')->whereIn('digital_components.status',['submitted','revision_required'])->select('digital_components.*','orders.order_number')->orderByDesc('digital_components.updated_at')->limit(8)->get(),
            'payouts'=>PayoutRequest::with('user')->whereIn('status',['requested','review','approved','failed','payment_executed'])->latest()->take(8)->get(),
        ];

        $deadlines=collect()
            ->merge(DB::table('damage_evidence_requests')->where('status','open')->whereNotNull('due_at')->get()->map(fn($r)=>(object)['type'=>'Beschädigungsnachweis','title'=>$r->instructions,'due_at'=>$r->due_at,'order_id'=>DB::table('damage_cases')->where('id',$r->damage_case_id)->value('order_id')]))
            ->merge(DB::table('spontaneous_requests')->where('status','requested')->whereNotNull('due_at')->get()->map(fn($r)=>(object)['type'=>'Spontaner Nachweis','title'=>$r->instructions,'due_at'=>$r->due_at,'order_id'=>$r->order_id]))
            ->merge(DB::table('revision_rounds')->whereIn('status',['open','submitted'])->whereNotNull('due_at')->get()->map(function($r){$component=DB::table('digital_components')->where('id',$r->digital_component_id)->first();return (object)['type'=>'Digitale Revision','title'=>'Revision '.$r->round_no,'due_at'=>$r->due_at,'order_id'=>$component?->order_id];}))
            ->merge(Order::whereNotNull('shipping_due_at')->whereIn('status',['waiting_shipping','shipping_overdue'])->get()->map(fn($o)=>(object)['type'=>'Versand','title'=>'Versand #'.$o->order_number,'due_at'=>$o->shipping_due_at,'order_id'=>$o->id]))
            ->sortBy('due_at')
            ->values();

        $overdue=$deadlines->filter(fn($d)=>CarbonImmutable::parse($d->due_at,'Europe/Berlin')->lt($now));
        $todayDue=$deadlines->filter(fn($d)=>CarbonImmutable::parse($d->due_at,'Europe/Berlin')->toDateString()===$today && CarbonImmutable::parse($d->due_at,'Europe/Berlin')->gte($now));

        $stats=[
            'active_orders'=>Order::whereNotIn('status',['completed','cancelled','rejected','request_rejected','not_started','archived'])->count(),
            'prechecks_pending'=>$queues['prechecks']->count(),
            'proofs_pending'=>ProofSubmission::where('review_status','pending')->count(),
            'violations_open'=>DB::table('violations')->whereIn('status',['open','reviewed'])->count(),
            'damage_open'=>DB::table('damage_cases')->whereIn('status',['reported','evidence_requested','review'])->count(),
            'payouts_pending'=>PayoutRequest::whereIn('status',['requested','review','approved','failed','payment_executed'])->count(),
            'overdue'=>$overdue->count(),
        ];

        return view('admin.dashboard.index',compact('queues','deadlines','overdue','todayDue','stats'));
    }
}
