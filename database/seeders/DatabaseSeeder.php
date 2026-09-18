<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $reliabilityRules=[
            [
                'key'=>'level_1',
                'violations'=>1,
                'max_active_orders'=>4,
                'blocked_offer_ids'=>[],
                'reason'=>'Auftragslimit nach erstem Zuverlässigkeitsverstoß auf 4 reduziert.',
            ],
            [
                'key'=>'level_2',
                'violations'=>2,
                'max_active_orders'=>3,
                'blocked_offer_ids'=>[],
                'reason'=>'Auftragslimit nach wiederholten Zuverlässigkeitsverstößen auf 3 reduziert.',
            ],
            [
                'key'=>'level_3',
                'violations'=>3,
                'max_active_orders'=>2,
                'blocked_offer_ids'=>[],
                'reason'=>'Auftragslimit nach wiederholten Zuverlässigkeitsverstößen auf 2 reduziert.',
            ],
            [
                'key'=>'level_4',
                'violations'=>4,
                'max_active_orders'=>1,
                'blocked_offer_ids'=>[],
                'reason'=>'Auftragslimit nach fortgesetzten Zuverlässigkeitsverstößen auf 1 reduziert.',
            ],
        ];

        $settings=[
            'site_name'=>['Wear&Earn','string'],
            'push_notifications_enabled'=>['1','bool'],
            'support_email'=>['','string'],
            'reliability_rules'=>[json_encode($reliabilityRules,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),'json'],
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
