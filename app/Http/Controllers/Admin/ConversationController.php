<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index()
    {
        $conversations = Conversation::with('user','order')->latest('last_message_at')->paginate(30);
        return view('admin.messages.index', compact('conversations'));
    }

    public function show(Conversation $conversation)
    {
        $conversation->load('messages.user','user','order');
        return view('admin.messages.show', compact('conversation'));
    }

    public function reply(Request $request, Conversation $conversation)
    {
        $data=$request->validate(['message'=>['required','string','max:5000']]);
        $conversation->messages()->create(['user_id'=>$request->user()->id,'body'=>$data['message']]);
        $conversation->update(['last_message_at'=>now(),'status'=>'open']);
        return back()->with('success','Antwort wurde gesendet.');
    }

    public function close(Conversation $conversation)
    {
        $conversation->update(['status'=>'closed']);
        return back()->with('success','Unterhaltung wurde geschlossen.');
    }
}
