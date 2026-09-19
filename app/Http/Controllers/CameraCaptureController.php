<?php

namespace App\Http\Controllers;

use App\Services\CameraCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CameraCaptureController extends Controller
{
    public function issue(Request $request, CameraCaptureService $captures): JsonResponse
    {
        $data=$request->validate([
            'context'=>['required','string','max:190','regex:/^(proof|shipment):[A-Za-z0-9:_-]+$/'],
        ]);

        return response()->json($captures->issue($request,$data['context']));
    }
}
