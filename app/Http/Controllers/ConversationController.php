<?php
namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Order;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index()
    {
        $conversations = request()->user()->conversations()->with('order')->latest('last_message_at')->paginate(20);
        return view('messages.index', compact('conversations'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required','string','max:180'],
            'order_id' => ['nullable','integer','exists:orders,id'],
            'message' => ['required','string','max:5000'],
        ]);
        if (!empty($data['order_id'])) {
            abort_unless(Order::whereKey($data['order_id'])->where('user_id',$request->user()->id)->exists(), 403);
        }
        $conversation = Conversation::create([
            'user_id'=>$request->user()->id,
            'order_id'=>$data['order_id'] ?? null,
            'subject'=>$data['subject'],
            'status'=>'open',
            'last_message_at'=>now(),
        ]);
        $conversation->messages()->create(['user_id'=>$request->user()->id,'body'=>$data['message']]);
        return redirect()->route('messages.show',$conversation)->with('success','Nachricht wurde gesendet.');
    }

    public function show(Conversation $conversation)
    {
        abort_unless($conversation->user_id === request()->user()->id, 403);
        $conversation->load('messages.user','order');
        return view('messages.show', compact('conversation'));
    }

    public function reply(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);
        $data = $request->validate(['message'=>['required','string','max:5000']]);
        $conversation->messages()->create(['user_id'=>$request->user()->id,'body'=>$data['message']]);
        $conversation->update(['last_message_at'=>now(),'status'=>'open']);
        return back()->with('success','Antwort wurde gesendet.');
    }
}
