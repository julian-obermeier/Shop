<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\MessageService;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index()
    {
        $conversations=Conversation::whereNotNull('order_id')->with('user','order')->latest('last_message_at')->paginate(30);
        return view('admin.messages.index',compact('conversations'));
    }

    public function show(Conversation $conversation)
    {
        abort_unless($conversation->order_id,404);
        $conversation->load('messages.user','user','order');
        $conversation->messages()->where('user_id',$conversation->user_id)->whereNull('read_at')->update(['read_at'=>now()]);
        $writeLocked=$this->isWriteLocked($conversation);
        return view('admin.messages.show',compact('conversation','writeLocked'));
    }

    public function reply(Request $request, Conversation $conversation, MessageService $messages)
    {
        abort_unless($conversation->order_id,404);
        abort_if($this->isWriteLocked($conversation),422,'Der Auftragschat ist archiviert und nur noch lesbar.');
        $data=$request->validate(['message'=>['required','string','max:5000']]);
        $messages->create($conversation,$request->user(),$data['message']);
        return back()->with('success','Antwort wurde gesendet.');
    }

    private function isWriteLocked(Conversation $conversation): bool
    {
        $conversation->loadMissing('order');
        return (bool)$conversation->order?->archived_at;
    }
}
