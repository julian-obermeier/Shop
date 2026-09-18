<?php
namespace App\Http\Controllers;
use App\Models\Offer;
class DashboardController extends Controller {
    public function __invoke(){
        $user=request()->user();
        $activeOrders=$user->orders()->with('days.proofs')->whereNotIn('status',['completed','cancelled','rejected'])->latest()->take(4)->get();
        $offers=Offer::published()->with('category')->latest()->take(4)->get();
        $wallet=$user->walletAccount()->firstOrCreate([]);
        return view('dashboard',compact('activeOrders','offers','wallet'));
    }
}
