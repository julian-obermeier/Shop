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
        $conversations=request()->user()->conversations()->with('order')->latest('last_message_at')->paginate(20);
        return view('messages.index',compact('conversations'));
    }

    public function store(Request $request, MessageService $messages)
    {
        $data=$request->validate([
            'subject'=>['required','string','max:180'],
            'order_id'=>['nullable','integer','exists:orders,id'],
            'message'=>['nullable','string','max:5000','required_without:attachment'],
            'attachment'=>['nullable','file','mimes:jpg,jpeg,png,webp,pdf','max:10240'],
        ]);

        if(!empty($data['order_id'])){
            abort_unless(
                Order::whereKey($data['order_id'])->where('user_id',$request->user()->id)->exists(),
                403
            );
        }

        $conversation=Conversation::create([
            'user_id'=>$request->user()->id,
            'order_id'=>$data['order_id']??null,
            'subject'=>$data['subject'],
            'status'=>'open',
            'last_message_at'=>now(),
        ]);

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
        abort_unless($conversation->user_id===request()->user()->id,403);
        $conversation->load('messages.user','order');

        $conversation->messages()
            ->whereHas('user',fn($q)=>$q->whereIn('role',['superadmin','admin','staff','accounting']))
            ->whereNull('read_at')
            ->update(['read_at'=>now()]);

        return view('messages.show',compact('conversation'));
    }

    public function reply(Request $request, Conversation $conversation, MessageService $messages)
    {
        abort_unless($conversation->user_id===$request->user()->id,403);
        abort_unless($conversation->status!=='closed',422,'Diese Unterhaltung ist geschlossen.');

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
}
