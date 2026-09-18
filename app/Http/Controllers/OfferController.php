<?php
namespace App\Http\Controllers;
use App\Models\Category;
use App\Models\Offer;
use Illuminate\Http\Request;
class OfferController extends Controller {
    public function index(Request $request){
        $query=Offer::published()->with('category');
        if($request->filled('category')) $query->whereHas('category',fn($q)=>$q->where('slug',$request->string('category')));
        if($request->filled('q')) $query->where(fn($q)=>$q->where('title','like','%'.$request->q.'%')->orWhere('short_description','like','%'.$request->q.'%'));
        $offers=$query->orderByDesc('created_at')->paginate(12)->withQueryString();
        $categories=Category::where('active',true)->orderBy('name')->get();
        return view('offers.index',compact('offers','categories'));
    }
    public function show(Offer $offer){ abort_unless($offer->active,404); $offer->load('category','options'); return view('offers.show',compact('offer')); }
}
