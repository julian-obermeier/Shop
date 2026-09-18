<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        $settings=[
            'site_name'=>Setting::valueOf('site_name','Wear&Earn'),
            'minimum_payout'=>Setting::valueOf('minimum_payout',10),
            'proof_reminders_enabled'=>Setting::valueOf('proof_reminders_enabled',true),
            'support_email'=>Setting::valueOf('support_email',''),
        ];
        return view('admin.settings.index',compact('settings'));
    }

    public function update(Request $request, AuditService $audit)
    {
        $data=$request->validate([
            'site_name'=>['required','string','max:120'],
            'minimum_payout'=>['required','numeric','min:0'],
            'support_email'=>['nullable','email','max:255'],
        ]);

        $pairs=[
            'site_name'=>[$data['site_name'],'string'],
            'minimum_payout'=>[(string)$data['minimum_payout'],'float'],
            'proof_reminders_enabled'=>[$request->boolean('proof_reminders_enabled')?'1':'0','bool'],
            'support_email'=>[$data['support_email']??'','string'],
        ];

        foreach($pairs as $key=>[$value,$type]){
            $before=Setting::where('key',$key)->first()?->toArray() ?? [];
            $setting=Setting::updateOrCreate(['key'=>$key],['value'=>$value,'type'=>$type]);
            $audit->log('setting.updated',$setting,$before,$setting->fresh()->toArray());
        }

        return back()->with('success','Systemeinstellungen wurden gespeichert.');
    }
}
