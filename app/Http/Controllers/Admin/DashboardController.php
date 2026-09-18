<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\ProofSubmission;
use App\Models\User;
class DashboardController extends Controller {
    public function __invoke(){
        $stats=[
            'providers'=>User::where('role','provider')->count(),
            'active_offers'=>Offer::where('active',true)->count(),
            'active_orders'=>Order::whereNotIn('status',['completed','cancelled','rejected'])->count(),
            'proofs_pending'=>ProofSubmission::where('review_status','pending')->count(),
            'payouts_pending'=>PayoutRequest::whereIn('status',['requested','review'])->count(),
        ];
        $orders=Order::with('user')->latest()->take(8)->get();
        return view('admin.dashboard.index',compact('stats','orders'));
    }
}
