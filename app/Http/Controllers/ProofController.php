<?php
namespace App\Http\Controllers;

use App\Models\OrderDay;
use App\Models\ProofChallenge;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProofController extends Controller
{
    public function challenge(Request $request, OrderDay $day)
    {
        $day->load('order');
        abort_unless($day->order->user_id===$request->user()->id,403);
        abort_if($request->user()->hasRestriction('uploads'),422,'Nachweise sind für dieses Konto derzeit gesperrt.');

        $data=$request->validate([
            'window_key'=>['required','string','max:80'],
        ]);

        $window=$this->resolveWindow($day,$data['window_key']);
        $this->assertWindowOpen($day,$window);

        return DB::transaction(function() use($request,$day,$window){
            ProofChallenge::where('order_id',$day->order_id)
                ->where('order_day_id',$day->id)
                ->where('window_key',$window['key'])
                ->whereNull('used_at')
                ->update(['used_at'=>now()]);

            $code=strtoupper(Str::random(6));
            $challenge=ProofChallenge::create([
                'order_id'=>$day->order_id,
                'order_day_id'=>$day->id,
                'user_id'=>$request->user()->id,
                'purpose'=>$day->day_number===0?'start':'daily',
                'window_key'=>$window['key'],
                'code'=>$code,
                'expires_at'=>now()->addMinutes(10),
            ]);

            return back()->with('success','Der Nachweiscode '.$challenge->code.' ist 10 Minuten gültig.');
        });
    }

    public function store(Request $request, OrderDay $day, OrderService $orders)
    {
        $day->load('order');
        $order=$day->order;

        abort_if($request->user()->hasRestriction('uploads'),422,'Nachweise sind für dieses Konto derzeit gesperrt.');
        abort_unless($order->user_id===$request->user()->id,403);

        if($day->day_number===0){
            abort_unless($order->status==='waiting_start',422,'Das Startfoto ist aktuell nicht vorgesehen.');
        } else {
            abort_unless($order->status==='active',422,'Nachweise sind nur während der aktiven Erfüllungsphase möglich.');
        }

        $data=$request->validate([
            'proof'=>['required','image','mimes:jpg,jpeg,png,webp','max:10240'],
            'challenge_id'=>['required','integer','exists:proof_challenges,id'],
            'proof_code'=>['required','string','max:16'],
            'window_key'=>['required','string','max:80'],
            'text_value'=>['nullable','string','max:2000'],
        ]);

        $window=$this->resolveWindow($day,$data['window_key']);
        $this->assertWindowOpen($day,$window,true);

        $challenge=ProofChallenge::whereKey($data['challenge_id'])
            ->where('order_id',$order->id)
            ->where('order_day_id',$day->id)
            ->where('user_id',$request->user()->id)
            ->where('window_key',$window['key'])
            ->firstOrFail();

        abort_unless($challenge->isUsable(),422,'Der Nachweiscode ist abgelaufen oder wurde bereits verwendet.');
        abort_unless(hash_equals($challenge->code,strtoupper(trim($data['proof_code']))),422,'Der eingegebene Nachweiscode stimmt nicht.');

        if(!empty($window['text_required'])){
            abort_if(trim((string)($data['text_value']??''))==='',422,'Für dieses Nachweisfenster ist zusätzlich ein Text erforderlich.');
        }

        $rejected=$day->proofs()
            ->where('window_key',$window['key'])
            ->where('review_status','rejected')
            ->orderByDesc('id')
            ->get();

        $retryNumber=$rejected->count();
        if($retryNumber>2){
            $latest=$rejected->first();
            abort_unless($latest?->extra_retry_granted,422,'Die zwei regulären Nachreichversuche sind ausgeschöpft. Ein weiterer Versuch muss vom Admin freigegeben werden.');
            $latest->update(['extra_retry_granted'=>false]);
        }

        if($retryNumber>0){
            $latest=$rejected->first();
            abort_if($latest?->resubmit_due_at && $latest->resubmit_due_at->isPast(),422,'Die Nachreichfrist ist bereits abgelaufen.');
        }

        $file=$data['proof'];
        $extension=strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $path=$file->storeAs($day->order_id.'/series-'.$day->series_number.'/day-'.$day->day_number,Str::uuid().'.'.$extension,'proofs');
        $absolute=Storage::disk('proofs')->path($path);

        DB::transaction(function() use($day,$request,$file,$path,$absolute,$challenge,$window,$data,$retryNumber,$orders){
            $challenge=ProofChallenge::whereKey($challenge->id)->lockForUpdate()->firstOrFail();
            abort_unless($challenge->isUsable(),422,'Der Nachweiscode wurde zwischenzeitlich verwendet oder ist abgelaufen.');
            $challenge->update(['used_at'=>now()]);

            $day->proofs()->create([
                'user_id'=>$request->user()->id,
                'type'=>$day->day_number===0?'start_photo':'photo',
                'window_key'=>$window['key'],
                'text_value'=>$data['text_value']??null,
                'proof_code'=>$challenge->code,
                'proof_code_expires_at'=>$challenge->expires_at,
                'proof_challenge_id'=>$challenge->id,
                'storage_path'=>$path,
                'original_name'=>$file->getClientOriginalName(),
                'mime_type'=>$file->getMimeType() ?: 'image/jpeg',
                'file_size'=>$file->getSize(),
                'sha256'=>hash_file('sha256',$absolute),
                'retry_number'=>$retryNumber,
                'review_status'=>'pending',
            ]);

            if($day->day_number===0) $orders->activateAfterStartProof($day);
        });

        return back()->with('success',$day->day_number===0
            ? 'Startfoto wurde eingereicht. Tag 1 beginnt am folgenden Kalendertag.'
            : 'Nachweis wurde im Original eingereicht.');
    }

    private function resolveWindow(OrderDay $day, string $key): array
    {
        if($day->day_number===0){
            abort_unless($key==='start',422,'Ungültiges Startfenster.');
            return [
                'key'=>'start',
                'label'=>'Startfoto',
                'start'=>'00:00',
                'end'=>'23:59',
                'required_images'=>1,
                'text_required'=>false,
                'face_required'=>(bool)data_get($day->order->current_requirements,'start_face_required',false),
            ];
        }

        $windows=data_get($day->order->current_requirements ?: $day->order->offer_snapshot,'proof_requirements',[]);
        $window=collect(is_array($windows)?$windows:[])->first(fn($row)=>(string)($row['key']??'')===$key);
        abort_unless($window,422,'Unbekanntes Nachweisfenster.');

        return $window;
    }

    private function assertWindowOpen(OrderDay $day, array $window, bool $allowResubmission=false): void
    {
        $now=CarbonImmutable::now('Europe/Berlin');

        if($allowResubmission){
            $latest=$day->proofs()
                ->where('window_key',$window['key'])
                ->where('review_status','rejected')
                ->latest('id')
                ->first();
            if($latest?->resubmit_due_at && $latest->resubmit_due_at->isFuture()) return;
        }

        abort_unless($day->date->isSameDay($now),422,'Nachweise können nur am vorgesehenen Kalendertag aufgenommen werden.');

        if($day->day_number===0) return;

        $start=CarbonImmutable::parse($day->date->format('Y-m-d').' '.($window['start']??'00:00'),'Europe/Berlin');
        $end=CarbonImmutable::parse($day->date->format('Y-m-d').' '.($window['end']??'23:59'),'Europe/Berlin');

        if($end->lt($start)) $end=$end->addDay();

        if($now->betweenIncluded($start,$end)) return;

        abort(422,'Dieses Nachweisfenster ist aktuell nicht geöffnet.');
    }
}
