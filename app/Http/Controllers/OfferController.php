<?php
namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Offer;
use App\Models\OfferWaitlistEntry;
use App\Services\WaitlistService;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    public function index(Request $request, WaitlistService $waitlists)
    {
        $query=Offer::published()->with('category');

        if($request->filled('category')){
            $query->whereHas('category',fn($q)=>$q->where('slug',$request->string('category')));
        }

        if($request->filled('q')){
            $query->where(fn($q)=>$q
                ->where('title','like','%'.$request->q.'%')
                ->orWhere('short_description','like','%'.$request->q.'%'));
        }

        $offers=$query->orderByDesc('created_at')->paginate(12)->withQueryString();
        $categories=Category::where('active',true)->orderBy('name')->get();

        $capacity=collect($offers->items())->mapWithKeys(fn($offer)=>[$offer->id=>$waitlists->availableDirectSlots($offer)]);

        return view('offers.index',compact('offers','categories','capacity'));
    }

    public function show(Offer $offer, WaitlistService $waitlists)
    {
        abort_unless(Offer::published()->whereKey($offer->id)->exists(),404);
        $offer->load('category','options','fields');

        $availableSlots=$waitlists->availableDirectSlots($offer);
        $waitlistEntry=OfferWaitlistEntry::where('offer_id',$offer->id)
            ->where('user_id',request()->user()->id)
            ->whereIn('status',['waiting','reserved'])
            ->first();

        $waitlistPosition=null;
        if($waitlistEntry?->status==='waiting'){
            $waitlistPosition=OfferWaitlistEntry::where('offer_id',$offer->id)
                ->where('status','waiting')
                ->where('created_at','<=',$waitlistEntry->created_at)
                ->count();
        }

        $offerBlocked=request()->user()->effectiveOrderLimit()===0 || request()->user()->isOfferBlocked($offer->id);

        return view('offers.show',compact('offer','availableSlots','waitlistEntry','waitlistPosition','offerBlocked'));
    }
}
