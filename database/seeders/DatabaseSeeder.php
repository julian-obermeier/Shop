<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $permissionMap=[
            'admin.dashboard'=>'Admin-Dashboard öffnen',
            'users.manage'=>'Anbieterinnen verwalten',
            'categories.manage'=>'Kategorien verwalten',
            'offers.manage'=>'Angebote verwalten',
            'orders.manage'=>'Aufträge und Vorprüfungen verwalten',
            'proofs.manage'=>'Nachweise prüfen',
            'verification.manage'=>'Verifizierungen prüfen',
            'payouts.manage'=>'Auszahlungen verwalten',
            'messages.manage'=>'Nachrichten verwalten',
            'documents.manage'=>'Dokumente verwalten',
            'reports.view'=>'Berichte und Exporte ansehen',
            'audit.view'=>'Audit-Log ansehen',
            'settings.manage'=>'Systemeinstellungen verwalten',
            'privacy.manage'=>'Datenschutzanfragen verwalten',
        ];

        $permissions=[];
        foreach($permissionMap as $key=>$name){
            $permissions[$key]=Permission::updateOrCreate(['key'=>$key],['name'=>$name]);
        }

        $roles=[
            'admin'=>array_keys($permissionMap),
            'staff'=>[
                'admin.dashboard','users.manage','orders.manage','proofs.manage',
                'verification.manage','messages.manage',
            ],
            'accounting'=>['admin.dashboard','payouts.manage','reports.view'],
        ];

        foreach($roles as $role=>$keys){
            foreach($keys as $key){
                DB::table('role_permissions')->updateOrInsert(
                    ['role'=>$role,'permission_id'=>$permissions[$key]->id],
                    ['updated_at'=>now(),'created_at'=>now()]
                );
            }
        }

        $settings=[
            'site_name'=>['Wear&Earn','string'],
            'minimum_payout'=>['10','float'],
            'proof_reminders_enabled'=>['1','bool'],
            'email_notifications_enabled'=>['1','bool'],
            'support_email'=>['','string'],
            'identity_retention_days'=>['30','int'],
            'precheck_retention_days'=>['180','int'],
            'proof_retention_days'=>['365','int'],
            'message_attachment_retention_days'=>['365','int'],
        ];

        foreach($settings as $key=>[$value,$type]){
            Setting::updateOrCreate(['key'=>$key],['value'=>$value,'type'=>$type]);
        }

        $categories=[
            ['name'=>'Socken','slug'=>'socken','icon'=>'🧦'],
            ['name'=>'Schuhe','slug'=>'schuhe','icon'=>'👟'],
            ['name'=>'Kombisets','slug'=>'kombisets','icon'=>'✦'],
            ['name'=>'Kleidung','slug'=>'kleidung','icon'=>'◇'],
        ];

        foreach($categories as $category){
            Category::updateOrCreate(
                ['slug'=>$category['slug']],
                $category+['active'=>true]
            );
        }
    }
}
