<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function edit()
    {
        $user=request()->user()->load('profile','warnings','restrictions');
        return view('profile.edit',compact('user'));
    }

    public function update(Request $request)
    {
        $user=$request->user();
        $data=$request->validate([
            'first_name'=>['required','string','max:100'],
            'last_name'=>['required','string','max:100'],
            'phone'=>['nullable','string','max:50'],
            'street'=>['nullable','string','max:180'],
            'postal_code'=>['nullable','string','max:20'],
            'city'=>['nullable','string','max:120'],
            'country_code'=>['required','string','size:2'],
        ]);
        $user->update(['first_name'=>$data['first_name'],'last_name'=>$data['last_name']]);
        $user->profile()->updateOrCreate([],[
            'phone'=>$data['phone']??null,
            'street'=>$data['street']??null,
            'postal_code'=>$data['postal_code']??null,
            'city'=>$data['city']??null,
            'country_code'=>strtoupper($data['country_code']),
        ]);
        return back()->with('success','Profil wurde gespeichert.');
    }
}
