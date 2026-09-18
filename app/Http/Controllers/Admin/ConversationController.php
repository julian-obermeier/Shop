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
        $conversations=Conversation::with('user','order')->latest('last_message_at')->paginate(30);
        return view('admin.messages.index',compact('conversations'));
    }

    public function show(Conversation $conversation)
    {
        $conversation->load('messages.user','user','order');

        $conversation->messages()
            ->where('user_id',$conversation->user_id)
            ->whereNull('read_at')
            ->update(['read_at'=>now()]);

        return view('admin.messages.show',compact('conversation'));
    }

    public function reply(Request $request, Conversation $conversation, MessageService $messages)
    {
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

    public function close(Conversation $conversation)
    {
        $conversation->update(['status'=>'closed']);
        return back()->with('success','Unterhaltung wurde geschlossen.');
    }
}
