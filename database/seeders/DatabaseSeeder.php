<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Offer;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.test'],
            [
                'role' => 'superadmin',
                'first_name' => 'Admin',
                'last_name' => 'Demo',
                'birth_date' => '1990-01-01',
                'password' => Hash::make('ChangeMe123!'),
                'status' => 'active',
                'verified_at' => now(),
            ]
        );
        WalletAccount::firstOrCreate(['user_id' => $admin->id]);

        $provider = User::firstOrCreate(
            ['email' => 'sophia@example.test'],
            [
                'role' => 'provider',
                'first_name' => 'Sophia',
                'last_name' => 'Muster',
                'birth_date' => '1998-05-12',
                'password' => Hash::make('ChangeMe123!'),
                'status' => 'active',
                'verified_at' => now(),
            ]
        );
        WalletAccount::firstOrCreate(['user_id' => $provider->id]);

        $categories = [
            ['name' => 'Socken', 'slug' => 'socken', 'icon' => '🧦'],
            ['name' => 'Schuhe', 'slug' => 'schuhe', 'icon' => '👟'],
            ['name' => 'Kombisets', 'slug' => 'kombisets', 'icon' => '✦'],
            ['name' => 'Kleidung', 'slug' => 'kleidung', 'icon' => '◇'],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(['slug' => $category['slug']], $category);
        }

        $socken = Category::where('slug', 'socken')->firstOrFail();
        $schuhe = Category::where('slug', 'schuhe')->firstOrFail();
        $kombi = Category::where('slug', 'kombisets')->firstOrFail();

        $offer = Offer::firstOrCreate(
            ['slug' => 'socken-5-tage'],
            [
                'category_id' => $socken->id,
                'title' => 'Socken – 5 Tage',
                'short_description' => 'Ein klar definierter 5-Tage-Auftrag mit täglichen Nachweisen und optionalen Zusatzbedingungen.',
                'description' => 'Du wählst die gewünschten Zusatzoptionen aus. Nach der Annahme werden die Bedingungen für deinen Auftrag fest gespeichert.',
                'base_compensation' => 40,
                'duration_days' => 5,
                'minimum_minutes_per_day' => 480,
                'proofs_per_day' => 2,
                'shipping_deadline_hours' => 24,
                'requires_precheck' => true,
                'active' => true,
                'rules' => [
                    'Tägliche Nachweise fristgerecht einreichen',
                    'Ware nach Abschluss innerhalb der Versandfrist versenden',
                    'Nur zuvor bestätigte Artikel verwenden',
                ],
            ]
        );

        $offer->options()->updateOrCreate(
            ['name' => 'Beim Sport getragen'],
            [
                'description' => 'Mindestens 60 Minuten sportliche Aktivität.',
                'price_delta' => 8,
                'extra_proofs_per_day' => 1,
                'sort_order' => 1,
                'active' => true,
            ]
        );

        $offer->options()->updateOrCreate(
            ['name' => 'Beim Schlafen getragen'],
            [
                'description' => 'Während einer Nacht innerhalb des Auftrags.',
                'price_delta' => 6,
                'extra_proofs_per_day' => 0,
                'sort_order' => 2,
                'active' => true,
            ]
        );

        $offer->options()->updateOrCreate(
            ['name' => '+1 zusätzlicher Tag'],
            [
                'description' => 'Verlängert die Erfüllungsphase um einen Tag.',
                'price_delta' => 10,
                'extra_duration_days' => 1,
                'sort_order' => 3,
                'active' => true,
            ]
        );

        Offer::firstOrCreate(
            ['slug' => 'sportsocken-7-tage'],
            [
                'category_id' => $socken->id,
                'title' => 'Sportsocken – 7 Tage',
                'short_description' => 'Längere Laufzeit mit sportbezogenen Optionen.',
                'description' => 'Sieben Tage mit täglichen Nachweisen.',
                'base_compensation' => 55,
                'duration_days' => 7,
                'minimum_minutes_per_day' => 480,
                'proofs_per_day' => 3,
                'shipping_deadline_hours' => 24,
                'requires_precheck' => true,
                'active' => true,
                'rules' => ['Tägliche Nachweise vollständig', 'Versand nach Abschluss'],
            ]
        );

        Offer::firstOrCreate(
            ['slug' => 'getragene-sneaker'],
            [
                'category_id' => $schuhe->id,
                'title' => 'Getragene Sneaker',
                'short_description' => 'Ankaufangebot für vorab bestätigte Sneaker.',
                'description' => 'Die Schuhe werden vor Auftragsannahme geprüft und bestätigt.',
                'base_compensation' => 70,
                'duration_days' => 5,
                'minimum_minutes_per_day' => 360,
                'proofs_per_day' => 2,
                'shipping_deadline_hours' => 48,
                'requires_precheck' => true,
                'active' => true,
                'rules' => ['Vorprüfung der Schuhe erforderlich', 'Nachweise gemäß Auftrag'],
            ]
        );

        Offer::firstOrCreate(
            ['slug' => 'socken-sneaker-kombi'],
            [
                'category_id' => $kombi->id,
                'title' => 'Socken + Sneaker (Kombi)',
                'short_description' => 'Kombinationsauftrag mit zwei Artikeln.',
                'description' => 'Socken und vorab bestätigte Schuhe werden gemeinsam als Auftrag geführt.',
                'base_compensation' => 95,
                'duration_days' => 7,
                'minimum_minutes_per_day' => 480,
                'proofs_per_day' => 3,
                'shipping_deadline_hours' => 24,
                'requires_precheck' => true,
                'active' => true,
                'rules' => ['Beide Artikel einsenden', 'Tägliche Nachweise vollständig'],
            ]
        );
    }
}
