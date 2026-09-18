<?php
namespace App\Http\Controllers;

use App\Models\Offer;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function edit()
    {
        $user=request()->user()->load(['profile','warnings','restrictions','reliabilityEvents'=>fn($q)=>$q->with('order')->latest('occurred_at')]);

        $blockedOfferIds=$user->restrictions
            ->where('active',true)
            ->flatMap(fn($restriction)=>is_array($restriction->blocked_offer_ids)?$restriction->blocked_offer_ids:[])
            ->map(fn($id)=>(int)$id)
            ->filter()
            ->unique()
            ->values();

        $blockedOffers=Offer::whereIn('id',$blockedOfferIds)->pluck('title','id');

        return view('profile.edit',compact('user','blockedOffers'));
    }

    public function update(Request $request)
    {
        $data=$request->validate([
            'phone'=>['nullable','string','max:50'],
        ]);

        $request->user()->profile()->updateOrCreate([],[
            'phone'=>$data['phone']??null,
        ]);

        return back()->with('success','Telefonnummer wurde gespeichert.');
    }
}
