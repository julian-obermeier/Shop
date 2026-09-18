<?php
namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Order;
use App\Services\MessageService;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index()
    {
        $conversations=request()->user()->conversations()
            ->whereNotNull('order_id')
            ->with('order')
            ->latest('last_message_at')
            ->paginate(20);

        return view('messages.index',compact('conversations'));
    }

    public function store(Request $request, MessageService $messages)
    {
        $data=$request->validate([
            'order_id'=>['required','integer','exists:orders,id'],
            'message'=>['nullable','string','max:5000','required_without:attachment'],
            'attachment'=>['nullable','file','mimes:jpg,jpeg,png,webp,pdf','max:10240'],
        ]);

        $order=Order::whereKey($data['order_id'])
            ->where('user_id',$request->user()->id)
            ->firstOrFail();

        $conversation=Conversation::firstOrCreate(
            ['user_id'=>$request->user()->id,'order_id'=>$order->id],
            [
                'subject'=>'Auftrag #'.$order->order_number,
                'status'=>'open',
                'last_message_at'=>now(),
            ]
        );

        abort_if($this->isWriteLocked($conversation),422,'Der Nachrichtenbereich dieses Auftrags ist bereits schreibgeschützt.');

        $messages->create(
            $conversation,
            $request->user(),
            $data['message']??null,
            $request->file('attachment')
        );

        return redirect()->route('messages.show',$conversation)->with('success','Nachricht wurde gesendet.');
    }

    public function show(Conversation $conversation)
    {
        abort_unless($conversation->user_id===request()->user()->id && $conversation->order_id,403);
        $conversation->load('messages.user','order');

        $conversation->messages()
            ->whereHas('user',fn($q)=>$q->where('role','admin'))
            ->whereNull('read_at')
            ->update(['read_at'=>now()]);

        $writeLocked=$this->isWriteLocked($conversation);

        return view('messages.show',compact('conversation','writeLocked'));
    }

    public function reply(Request $request, Conversation $conversation, MessageService $messages)
    {
        abort_unless($conversation->user_id===$request->user()->id && $conversation->order_id,403);
        abort_if($this->isWriteLocked($conversation),422,'Der Nachrichtenbereich dieses Auftrags ist bereits schreibgeschützt.');

        $data=$request->validate([
            'message'=>['nullable','string','max:5000','required_without:attachment'],
            'attachment'=>['nullable','file','mimes:jpg,jpeg,png,webp,pdf','max:10240'],
        ]);

        $messages->create(
            $conversation,
            $request->user(),
            $data['message']??null,
            $request->file('attachment')
        );

        return back()->with('success','Antwort wurde gesendet.');
    }

    private function isWriteLocked(Conversation $conversation): bool
    {
        $order=$conversation->order;
        if(!$order || !$order->completed_at) return false;
        return $order->completed_at->copy()->addDays(7)->isPast();
    }
}
