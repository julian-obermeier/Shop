<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CameraCaptureService
{
    private const SESSION_KEY='camera_capture_tokens';
    private const TTL_SECONDS=120;

    public function issue(Request $request, string $context): array
    {
        $this->assertContext($context);

        $tokens=$this->prune((array)$request->session()->get(self::SESSION_KEY,[]));
        $plain=Str::random(64);
        $hash=hash('sha256',$plain);
        $expiresAt=now()->addSeconds(self::TTL_SECONDS);

        $tokens[$hash]=[
            'context'=>$context,
            'user_id'=>(int)$request->user()->id,
            'expires_at'=>$expiresAt->timestamp,
        ];

        $request->session()->put(self::SESSION_KEY,$tokens);

        return [
            'token'=>$plain,
            'expires_at'=>$expiresAt->toIso8601String(),
        ];
    }

    public function consume(Request $request, string $plain, string $context): void
    {
        $this->assertContext($context);
        abort_if(trim($plain)==='',422,'Die Live-Kamera-Bestätigung fehlt. Bitte nimm das Foto erneut direkt in der Webanwendung auf.');

        $tokens=$this->prune((array)$request->session()->get(self::SESSION_KEY,[]));
        $hash=hash('sha256',$plain);
        $entry=$tokens[$hash]??null;

        unset($tokens[$hash]);
        $request->session()->put(self::SESSION_KEY,$tokens);

        abort_unless(
            is_array($entry)
            && (int)($entry['user_id']??0)===(int)$request->user()->id
            && hash_equals((string)($entry['context']??''),$context)
            && (int)($entry['expires_at']??0)>=now()->timestamp,
            422,
            'Die Live-Kamera-Bestätigung ist ungültig oder abgelaufen. Bitte nimm das Foto erneut direkt in der Webanwendung auf.'
        );
    }

    private function prune(array $tokens): array
    {
        $now=now()->timestamp;

        return collect($tokens)
            ->filter(fn($entry)=>is_array($entry) && (int)($entry['expires_at']??0)>=$now)
            ->take(-20)
            ->all();
    }

    private function assertContext(string $context): void
    {
        abort_unless(
            preg_match('/^(proof|shipment):[A-Za-z0-9:_-]{1,180}$/',$context)===1,
            422,
            'Ungültiger Live-Kamera-Kontext.'
        );
    }
}
