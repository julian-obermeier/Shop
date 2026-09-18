<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $query=AuditLog::query()->with('user');
        if($request->filled('action')) $query->where('action','like','%'.$request->string('action').'%');
        if($request->filled('user_id')) $query->where('user_id',$request->integer('user_id'));
        $logs=$query->latest()->paginate(50)->withQueryString();
        return view('admin.audit.index',compact('logs'));
    }
}
