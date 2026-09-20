<?php
namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    public function index(Request $request)
    {
        $query=Offer::published()->with('category');

        if($request->filled('category')){
            $query->whereHas('category',fn($q)=>$q->where('slug',$request->string('category')));
        }
        if($request->filled('q')){
            $term=trim((string)$request->q);
            $query->where(fn($q)=>$q
                ->where('title','like','%'.$term.'%')
                ->orWhere('short_description','like','%'.$term.'%')
                ->orWhere('description','like','%'.$term.'%'));
        }
        if($request->filled('min_compensation')) $query->where('base_compensation','>=',(float)$request->min_compensation);
        if($request->filled('max_compensation')) $query->where('base_compensation','<=',(float)$request->max_compensation);
        if($request->filled('duration')) $query->where('duration_days',(int)$request->duration);

        $offers=$query->orderByDesc('created_at')->paginate(12)->withQueryString();
        $categories=Category::where('active',true)->whereNotNull('parent_id')->orderBy('sort_order')->orderBy('name')->get();

        return view('offers.index',compact('offers','categories'));
    }

    public function show(Offer $offer)
    {
        abort_unless(Offer::published()->whereKey($offer->id)->exists(),404);
        $offer->load('category','options','fields');

        $categoryConflict=false;
        if(auth()->check() && !auth()->user()->isAdmin()){
            $categoryConflict=Order::where('user_id',auth()->id())
                ->whereNotIn('status',['completed','cancelled','rejected','request_rejected','not_started','archived'])
                ->whereHas('offer',fn($q)=>$q->where('category_id',$offer->category_id))
                ->exists();
        }

        return view('offers.show',compact('offer','categoryConflict'));
    }
}
