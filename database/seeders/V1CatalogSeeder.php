<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class V1CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'site_name' => ['Wear&Earn', 'string'],
            'support_email' => ['', 'string'],
            'timezone' => ['Europe/Berlin', 'string'],
            'currency' => ['EUR', 'string'],
            'minimum_payout' => ['10.00', 'decimal'],
            'payout_bank_enabled' => ['1', 'bool'],
            'payout_paypal_enabled' => ['1', 'bool'],
            'payout_bank_fee_fixed' => ['0.00', 'decimal'],
            'payout_bank_fee_percent' => ['0.00', 'decimal'],
            'payout_paypal_fee_fixed' => ['0.00', 'decimal'],
            'payout_paypal_fee_percent' => ['0.00', 'decimal'],
            'proof_grace_minutes' => ['60', 'int'],
            'default_morning_window' => ['06:00-10:00', 'string'],
            'default_midday_window' => ['12:00-16:00', 'string'],
            'default_evening_window' => ['18:00-23:59', 'string'],
        ];

        foreach ($settings as $key => [$value, $type]) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'type' => $type]);
        }

        $groups = [
            ['name' => 'Getragene Kleidung', 'slug' => 'getragene-kleidung', 'icon' => '◇', 'kind' => 'group'],
            ['name' => 'Schuhe & Fußbereich', 'slug' => 'schuhe-fussbereich', 'icon' => '◈', 'kind' => 'group'],
            ['name' => 'Dessous', 'slug' => 'dessous', 'icon' => '♡', 'kind' => 'group'],
            ['name' => 'Accessoires', 'slug' => 'accessoires', 'icon' => '✦', 'kind' => 'group'],
            ['name' => 'Körperbezogene Artikel', 'slug' => 'koerperbezogene-artikel', 'icon' => '○', 'kind' => 'group'],
            ['name' => 'Digitale Inhalte', 'slug' => 'digitale-inhalte', 'icon' => '▣', 'kind' => 'group'],
            ['name' => 'Sets & Sonstiges', 'slug' => 'sets-sonstiges', 'icon' => '＋', 'kind' => 'group'],
        ];

        $parents = [];
        foreach ($groups as $i => $group) {
            $parents[$group['slug']] = Category::updateOrCreate(
                ['slug' => $group['slug']],
                [
                    'name' => $group['name'],
                    'icon' => $group['icon'],
                    'kind' => $group['kind'],
                    'sort_order' => ($i + 1) * 100,
                    'system_template' => true,
                    'active' => true,
                    'config' => ['group' => true],
                ]
            );
        }

        $categories = [
            ['Socken', 'socken', '🧦', 'schuhe-fussbereich', 'physical', 'socks'],
            ['Schuhe', 'schuhe', '👟', 'schuhe-fussbereich', 'physical', 'shoes'],
            ['Einlegesohlen', 'einlegesohlen', '◫', 'schuhe-fussbereich', 'physical', 'generic'],
            ['Schnürsenkel', 'schnuersenkel', '〰', 'schuhe-fussbereich', 'physical', 'generic'],
            ['Kniestrümpfe', 'kniestruempfe', '◧', 'schuhe-fussbereich', 'physical', 'socks'],
            ['Overknees', 'overknees', '◨', 'schuhe-fussbereich', 'physical', 'socks'],
            ['Feinstrümpfe', 'feinstruempfe', '◩', 'schuhe-fussbereich', 'physical', 'socks'],
            ['Strumpfhosen', 'strumpfhosen', '◇', 'getragene-kleidung', 'physical', 'clothing'],
            ['Nylons', 'nylons', '◇', 'getragene-kleidung', 'physical', 'clothing'],
            ['Leggings', 'leggings', '▱', 'getragene-kleidung', 'physical', 'clothing'],
            ['Shorts', 'shorts', '▱', 'getragene-kleidung', 'physical', 'clothing'],
            ['Hotpants', 'hotpants', '▱', 'getragene-kleidung', 'physical', 'clothing'],
            ['Sportkleidung', 'sportkleidung', '◆', 'getragene-kleidung', 'physical', 'clothing'],
            ['T-Shirts', 't-shirts', '◇', 'getragene-kleidung', 'physical', 'clothing'],
            ['Tops', 'tops', '◇', 'getragene-kleidung', 'physical', 'intimate_clothing'],
            ['Pullover', 'pullover', '◇', 'getragene-kleidung', 'physical', 'clothing'],
            ['Hoodies', 'hoodies', '◇', 'getragene-kleidung', 'physical', 'clothing'],
            ['Schlafkleidung', 'schlafkleidung', '☾', 'getragene-kleidung', 'physical', 'clothing'],
            ['Pyjamas', 'pyjamas', '☾', 'getragene-kleidung', 'physical', 'clothing'],
            ['Arbeitskleidung', 'arbeitskleidung', '▦', 'getragene-kleidung', 'physical', 'clothing'],
            ['Berufskleidung', 'berufskleidung', '▦', 'getragene-kleidung', 'physical', 'clothing'],
            ['Kostüm-/Cosplay-Kleidung', 'kostuem-cosplay', '☆', 'getragene-kleidung', 'physical', 'clothing'],
            ['Slips', 'slips', '♡', 'dessous', 'physical', 'intimate_clothing'],
            ['BHs', 'bhs', '♡', 'dessous', 'physical', 'intimate_clothing'],
            ['Bodys', 'bodys', '♡', 'dessous', 'physical', 'intimate_clothing'],
            ['Bikinis', 'bikinis', '♡', 'dessous', 'physical', 'intimate_clothing'],
            ['Badeanzüge', 'badeanzuege', '♡', 'dessous', 'physical', 'intimate_clothing'],
            ['Dessous – Sonstiges', 'dessous-sonstiges', '♡', 'dessous', 'physical', 'intimate_clothing'],
            ['Handschuhe', 'handschuhe', '◇', 'accessoires', 'physical', 'generic'],
            ['Mützen', 'muetzen', '◇', 'accessoires', 'physical', 'generic'],
            ['Caps', 'caps', '◇', 'accessoires', 'physical', 'generic'],
            ['Schals', 'schals', '◇', 'accessoires', 'physical', 'generic'],
            ['Persönliche Accessoires', 'persoenliche-accessoires', '✦', 'accessoires', 'physical', 'generic'],
            ['Haare/Haarsträhnen', 'haare-haarstraehnen', '〰', 'koerperbezogene-artikel', 'special', 'generic'],
            ['Spucke', 'spucke', '○', 'koerperbezogene-artikel', 'special', 'saliva'],
            ['Wichsanleitung', 'wichsanleitung', '▣', 'digitale-inhalte', 'digital', 'digital'],
            ['Digitale Inhalte – Sonstiges', 'digitale-inhalte-sonstiges', '▣', 'digitale-inhalte', 'digital', 'digital'],
            ['Individuelle Sets', 'individuelle-sets', '＋', 'sets-sonstiges', 'physical', 'combo'],
            ['Sonstiges', 'sonstiges', '…', 'sets-sonstiges', 'physical', 'generic'],
        ];

        foreach ($categories as $i => [$name, $slug, $icon, $parentSlug, $kind, $profile]) {
            $config = [
                'precheck_profile' => $profile,
                'third_party_goods_allowed' => false,
                'seller_must_perform_personally' => true,
            ];

            if ($profile === 'socks') {
                $config['precheck_slots'] = [
                    ['key' => 'item_front', 'label' => 'Ausgewählte Socken – Vorderansicht'],
                    ['key' => 'item_back', 'label' => 'Ausgewählte Socken – Rückansicht'],
                    ['key' => 'feet_top', 'label' => 'Nackte Füße – von oben'],
                    ['key' => 'feet_soles', 'label' => 'Nackte Füße – Sohlen'],
                    ['key' => 'feet_sides', 'label' => 'Nackte Füße – Seitenansicht'],
                ];
            } elseif ($profile === 'shoes') {
                $config['precheck_slots'] = [
                    ['key' => 'shoes_outside', 'label' => 'Schuhe – Außenansichten'],
                    ['key' => 'shoes_inside', 'label' => 'Schuhe – Innenbereich'],
                    ['key' => 'shoes_soles', 'label' => 'Schuhe – Sohlen'],
                    ['key' => 'feet_top', 'label' => 'Nackte Füße – von oben'],
                    ['key' => 'feet_soles', 'label' => 'Nackte Füße – Sohlen'],
                    ['key' => 'feet_sides', 'label' => 'Nackte Füße – Seitenansicht'],
                ];
            } elseif ($profile === 'intimate_clothing') {
                $config['precheck_slots'] = [
                    ['key' => 'item_front', 'label' => 'Artikel – Vorderseite'],
                    ['key' => 'item_back', 'label' => 'Artikel – Rückseite'],
                    ['key' => 'item_details', 'label' => 'Artikel – relevante Details'],
                    ['key' => 'worn_start', 'label' => 'Startnachweis – getragen'],
                ];
            } elseif ($profile === 'saliva') {
                $config['precheck_slots'] = [
                    ['key' => 'container', 'label' => 'Vorgesehener Behälter'],
                ];
                $config['shipping_requirements'] = [
                    'Auslaufsicherer Primärbehälter',
                    'Zusätzliche dichte Umverpackung',
                    'Pflichtfoto des befüllten Behälters',
                ];
            }

            Category::updateOrCreate(
                ['slug' => $slug],
                [
                    'parent_id' => $parents[$parentSlug]->id,
                    'name' => $name,
                    'icon' => $icon,
                    'kind' => $kind,
                    'sort_order' => ($i + 1) * 10,
                    'system_template' => true,
                    'active' => true,
                    'config' => $config,
                ]
            );
        }

        DB::table('settings')->whereIn('key', ['reliability_rules', 'push_notifications_enabled'])->delete();
    }
}
