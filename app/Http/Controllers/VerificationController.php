<?php
namespace App\Http\Controllers;

use App\Models\IdentityVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VerificationController extends Controller
{
    public function index()
    {
        $verification = request()->user()->verifications()->latest()->first();
        return view('verification.index', compact('verification'));
    }

    public function store(Request $request)
    {
        abort_if($request->user()->verified_at, 422, 'Das Konto ist bereits verifiziert.');
        abort_if($request->user()->verifications()->whereIn('status', ['requested','review'])->exists(), 422, 'Es ist bereits eine Prüfung offen.');

        $data = $request->validate([
            'document_front' => ['required','file','mimes:jpg,jpeg,png,pdf','max:10240'],
            'document_back' => ['nullable','file','mimes:jpg,jpeg,png,pdf','max:10240'],
        ]);

        $folder = $request->user()->id.'/'.Str::uuid();
        $front = $data['document_front']->store($folder, 'identity');
        $back = isset($data['document_back']) ? $data['document_back']->store($folder, 'identity') : null;

        IdentityVerification::create([
            'user_id' => $request->user()->id,
            'status' => 'requested',
            'method' => 'manual',
            'document_front_path' => $front,
            'document_back_path' => $back,
        ]);

        return back()->with('success', 'Die Verifizierung wurde zur Prüfung eingereicht.');
    }
}
