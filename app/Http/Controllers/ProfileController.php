<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function edit()
    {
        $user=request()->user()->load(['profile','warnings','restrictions','reliabilityEvents'=>fn($q)=>$q->with('order')->latest('occurred_at')]);
        return view('profile.edit',compact('user'));
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
