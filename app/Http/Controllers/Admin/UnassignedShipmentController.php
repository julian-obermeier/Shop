<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UnassignedShipment;
use App\Services\AuditService;
use Illuminate\Http\Request;

class UnassignedShipmentController extends Controller
{
    public function index(Request $request)
    {
        $query=UnassignedShipment::with('recorder')->latest('received_at');

        if($request->filled('q')){
            $term='%'.$request->string('q').'%';
            $query->where(function($q) use($term){
                $q->where('sender_name','like',$term)
                    ->orWhere('sender_address','like',$term)
                    ->orWhere('tracking_number','like',$term)
                    ->orWhere('carrier','like',$term);
            });
        }

        $shipments=$query->paginate(30)->withQueryString();

        return view('admin.unassigned-shipments.index',compact('shipments'));
    }

    public function store(Request $request, AuditService $audit)
    {
        $data=$request->validate([
            'sender_name'=>['nullable','string','max:255'],
            'sender_address'=>['nullable','string','max:500'],
            'carrier'=>['nullable','string','max:120'],
            'tracking_number'=>['nullable','string','max:180'],
            'shipping_date'=>['nullable','date'],
            'received_at'=>['required','date'],
            'matching_attempt'=>['required','string','max:3000'],
            'notes'=>['nullable','string','max:3000'],
        ]);

        $shipment=UnassignedShipment::create($data+[
            'recorded_by'=>$request->user()->id,
            'status'=>'unassigned',
        ]);

        $audit->log('shipment.unassigned.recorded',$shipment,[],$shipment->toArray());

        return back()->with('success','Nicht zuordenbare Sendung wurde endgültig dokumentiert. Es erfolgt keine automatische Rücksendung und keine spätere Zuordnungsfrist.');
    }
}
