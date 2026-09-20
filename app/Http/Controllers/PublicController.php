<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Offer;
use App\Models\Setting;

class PublicController extends Controller
{
    public function home()
    {
        $offers=Offer::published()->with('category')->latest()->take(6)->get();
        $categories=Category::where('active',true)->whereNotNull('parent_id')->orderBy('sort_order')->take(12)->get();
        return view('public.home',compact('offers','categories'));
    }

    public function how(): \Illuminate\View\View { return view('public.how'); }
    public function faq(): \Illuminate\View\View { return view('public.faq'); }
    public function rules(): \Illuminate\View\View { return view('public.rules'); }

    public function contact()
    {
        $supportEmail=Setting::valueOf('support_email');
        return view('public.contact',compact('supportEmail'));
    }
}
