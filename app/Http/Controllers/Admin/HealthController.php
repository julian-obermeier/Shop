<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;

class HealthController extends Controller
{
    public function __invoke(SystemHealthService $health)
    {
        $result=$health->check();
        return view('admin.health.index',compact('result'));
    }
}
