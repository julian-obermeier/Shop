<?php
namespace App\Services;

use App\Models\Document;
use App\Models\User;

class ConsentService
{
    public function assertRequiredConsents(User $user): void
    {
        $documents=Document::where('active',true)
            ->where('requires_consent',true)
            ->with(['versions'=>fn($q)=>$q->where('active',true)->whereNotNull('published_at')->latest('published_at')])
            ->get();

        foreach($documents as $document){
            $version=$document->versions->first();
            if(!$version) continue;
            $accepted=$user->documentConsents()->where('document_version_id',$version->id)->exists();
            abort_unless($accepted,422,'Vor der Annahme eines Angebots musst du dem aktuellen Dokument „'.$document->title.'“ zustimmen.');
        }
    }
}
