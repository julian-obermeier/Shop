<?php
namespace App\Http\Controllers;

use App\Services\PrivacyService;

class PrivacyController extends Controller
{
    public function index()
    {
        return view('privacy.index');
    }

    public function export(PrivacyService $privacy)
    {
        $data=$privacy->export(request()->user());
        $filename='wear-and-earn-datenauszug-'.now()->format('Y-m-d-His').'.json';

        return response()->streamDownload(
            function() use($data){
                echo json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            },
            $filename,
            ['Content-Type'=>'application/json; charset=UTF-8']
        );
    }
}
