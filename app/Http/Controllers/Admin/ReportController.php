<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\ProofSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index()
    {
        $stats=[
            'providers'=>User::where('role','provider')->count(),
            'verified'=>User::where('role','provider')->whereNotNull('verified_at')->count(),
            'orders_total'=>Order::count(),
            'orders_active'=>Order::whereNotIn('status',['completed','cancelled','rejected'])->count(),
            'compensation_total'=>(float)Order::whereIn('status',['compensation_released','completed'])->sum('compensation_total'),
            'payouts_paid'=>(float)PayoutRequest::where('status','paid')->sum('amount'),
            'proofs_total'=>ProofSubmission::count(),
            'proofs_rejected'=>ProofSubmission::whereIn('review_status',['rejected','resubmit'])->count(),
        ];

        $statusCounts=Order::selectRaw('status, COUNT(*) as total')->groupBy('status')->orderByDesc('total')->get();
        $topOffers=Offer::withCount('orders')->orderByDesc('orders_count')->take(10)->get();
        return view('admin.reports.index',compact('stats','statusCounts','topOffers'));
    }

    public function export(Request $request, string $type): StreamedResponse
    {
        abort_unless(in_array($type,['orders','users','payouts'],true),404);
        $filename=$type.'-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function() use($type){
            $out=fopen('php://output','w');
            fwrite($out,"ï»¿");

            if($type==='orders'){
                fputcsv($out,['Auftragsnummer','Anbieterin','E-Mail','Status','Vergütung','Start','Ende','Erstellt'],';');
                Order::with('user')->orderBy('id')->chunk(500,function($rows) use($out){
                    foreach($rows as $row) fputcsv($out,[
                        $row->order_number,
                        $row->user->first_name.' '.$row->user->last_name,
                        $row->user->email,
                        $row->status,
                        number_format((float)$row->compensation_total,2,'.',''),
                        $row->start_date?->toDateString(),
                        $row->end_date?->toDateString(),
                        $row->created_at->toDateTimeString(),
                    ],';');
                });
            }

            if($type==='users'){
                fputcsv($out,['ID','Name','E-Mail','Status','Verifiziert','Registriert'],';');
                User::where('role','provider')->orderBy('id')->chunk(500,function($rows) use($out){
                    foreach($rows as $row) fputcsv($out,[
                        $row->id,
                        $row->first_name.' '.$row->last_name,
                        $row->email,
                        $row->status,
                        $row->verified_at?->toDateTimeString(),
                        $row->created_at->toDateTimeString(),
                    ],';');
                });
            }

            if($type==='payouts'){
                fputcsv($out,['Nummer','Anbieterin','Status','Betrag','Beantragt','Ausgezahlt'],';');
                PayoutRequest::with('user')->orderBy('id')->chunk(500,function($rows) use($out){
                    foreach($rows as $row) fputcsv($out,[
                        $row->payout_number,
                        $row->user->first_name.' '.$row->user->last_name,
                        $row->status,
                        number_format((float)$row->amount,2,'.',''),
                        $row->created_at->toDateTimeString(),
                        $row->paid_at?->toDateTimeString(),
                    ],';');
                });
            }

            fclose($out);
        },$filename,['Content-Type'=>'text/csv; charset=UTF-8']);
    }
}
