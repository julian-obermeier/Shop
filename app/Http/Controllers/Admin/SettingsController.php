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
        $defaultRules=[
            ['key'=>'level_1','violations'=>1,'max_active_orders'=>4,'blocked_offer_ids'=>[],'reason'=>'Auftragslimit nach erstem Zuverlässigkeitsverstoß auf 4 reduziert.'],
            ['key'=>'level_2','violations'=>2,'max_active_orders'=>3,'blocked_offer_ids'=>[],'reason'=>'Auftragslimit nach wiederholten Zuverlässigkeitsverstößen auf 3 reduziert.'],
            ['key'=>'level_3','violations'=>3,'max_active_orders'=>2,'blocked_offer_ids'=>[],'reason'=>'Auftragslimit nach wiederholten Zuverlässigkeitsverstößen auf 2 reduziert.'],
            ['key'=>'level_4','violations'=>4,'max_active_orders'=>1,'blocked_offer_ids'=>[],'reason'=>'Auftragslimit nach fortgesetzten Zuverlässigkeitsverstößen auf 1 reduziert.'],
        ];

        $settings=[
            'site_name'=>Setting::valueOf('site_name','Wear&Earn'),
            'proof_reminders_enabled'=>Setting::valueOf('proof_reminders_enabled',true),
            'email_notifications_enabled'=>Setting::valueOf('email_notifications_enabled',true),
            'push_notifications_enabled'=>Setting::valueOf('push_notifications_enabled',true),
            'support_email'=>Setting::valueOf('support_email',''),
            'reliability_rules'=>Setting::valueOf('reliability_rules',$defaultRules),
        ];

        return view('admin.settings.index',compact('settings'));
    }

    public function update(Request $request, AuditService $audit)
    {
        $data=$request->validate([
            'site_name'=>['required','string','max:120'],
            'support_email'=>['nullable','email','max:255'],
            'reliability_rules_json'=>['required','string','max:30000'],
        ]);

        $rules=json_decode($data['reliability_rules_json'],true);
        abort_unless(is_array($rules),422,'Die Zuverlässigkeitsregeln enthalten kein gültiges JSON.');

        $normalized=[];
        foreach($rules as $i=>$rule){
            abort_unless(is_array($rule),422,'Zuverlässigkeitsregel '.($i+1).' ist ungültig.');
            $violations=(int)($rule['violations']??0);
            $limit=array_key_exists('max_active_orders',$rule)?(int)$rule['max_active_orders']:null;
            abort_if($violations<1,422,'Jede Zuverlässigkeitsregel benötigt mindestens einen Verstoß als Schwelle.');
            abort_if($limit!==null && ($limit<0 || $limit>5),422,'Das Auftragslimit einer Zuverlässigkeitsregel muss zwischen 0 und 5 liegen.');

            $blocked=array_values(array_unique(array_map('intval',$rule['blocked_offer_ids']??[])));

            $normalized[]=[
                'key'=>(string)($rule['key']??('rule_'.($i+1))),
                'violations'=>$violations,
                'max_active_orders'=>$limit,
                'blocked_offer_ids'=>$blocked,
                'reason'=>(string)($rule['reason']??'Regelbasierte Zuverlässigkeitseinschränkung'),
            ];
        }

        usort($normalized,fn($a,$b)=>$a['violations']<=>$b['violations']);

        $pairs=[
            'site_name'=>[$data['site_name'],'string'],
            'proof_reminders_enabled'=>[$request->boolean('proof_reminders_enabled')?'1':'0','bool'],
            'email_notifications_enabled'=>[$request->boolean('email_notifications_enabled')?'1':'0','bool'],
            'push_notifications_enabled'=>[$request->boolean('push_notifications_enabled')?'1':'0','bool'],
            'support_email'=>[$data['support_email']??'','string'],
            'reliability_rules'=>[json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),'json'],
        ];

        foreach($pairs as $key=>[$value,$type]){
            $before=Setting::where('key',$key)->first()?->toArray() ?? [];
            $setting=Setting::updateOrCreate(['key'=>$key],['value'=>$value,'type'=>$type]);
            $audit->log('setting.updated',$setting,$before,$setting->fresh()->toArray());
        }

        return back()->with('success','Systemeinstellungen wurden gespeichert.');
    }
}
