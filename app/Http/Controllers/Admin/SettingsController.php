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
            'email_notifications_enabled'=>Setting::valueOf('email_notifications_enabled',true),
            'support_email'=>Setting::valueOf('support_email',''),
            'identity_retention_days'=>Setting::valueOf('identity_retention_days',30),
            'precheck_retention_days'=>Setting::valueOf('precheck_retention_days',180),
            'proof_retention_days'=>Setting::valueOf('proof_retention_days',365),
            'message_attachment_retention_days'=>Setting::valueOf('message_attachment_retention_days',365),
        ];
        return view('admin.settings.index',compact('settings'));
    }

    public function update(Request $request, AuditService $audit)
    {
        $data=$request->validate([
            'site_name'=>['required','string','max:120'],
            'minimum_payout'=>['required','numeric','min:0'],
            'support_email'=>['nullable','email','max:255'],
            'identity_retention_days'=>['required','integer','min:1','max:3650'],
            'precheck_retention_days'=>['required','integer','min:1','max:3650'],
            'proof_retention_days'=>['required','integer','min:1','max:3650'],
            'message_attachment_retention_days'=>['required','integer','min:1','max:3650'],
        ]);

        $pairs=[
            'site_name'=>[$data['site_name'],'string'],
            'minimum_payout'=>[(string)$data['minimum_payout'],'float'],
            'proof_reminders_enabled'=>[$request->boolean('proof_reminders_enabled')?'1':'0','bool'],
            'email_notifications_enabled'=>[$request->boolean('email_notifications_enabled')?'1':'0','bool'],
            'support_email'=>[$data['support_email']??'','string'],
            'identity_retention_days'=>[(string)$data['identity_retention_days'],'int'],
            'precheck_retention_days'=>[(string)$data['precheck_retention_days'],'int'],
            'proof_retention_days'=>[(string)$data['proof_retention_days'],'int'],
            'message_attachment_retention_days'=>[(string)$data['message_attachment_retention_days'],'int'],
        ];

        foreach($pairs as $key=>[$value,$type]){
            $before=Setting::where('key',$key)->first()?->toArray() ?? [];
            $setting=Setting::updateOrCreate(['key'=>$key],['value'=>$value,'type'=>$type]);
            $audit->log('setting.updated',$setting,$before,$setting->fresh()->toArray());
        }

        return back()->with('success','Systemeinstellungen wurden gespeichert.');
    }
}
