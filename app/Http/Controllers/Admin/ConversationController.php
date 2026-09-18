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
        $conversations=Conversation::whereNotNull('order_id')
            ->with('user','order')
            ->latest('last_message_at')
            ->paginate(30);

        return view('admin.messages.index',compact('conversations'));
    }

    public function show(Conversation $conversation)
    {
        abort_unless($conversation->order_id,404);

        $conversation->load('messages.user','user','order');

        $conversation->messages()
            ->where('user_id',$conversation->user_id)
            ->whereNull('read_at')
            ->update(['read_at'=>now()]);

        $writeLocked=$this->isWriteLocked($conversation);

        return view('admin.messages.show',compact('conversation','writeLocked'));
    }

    public function reply(Request $request, Conversation $conversation, MessageService $messages)
    {
        abort_unless($conversation->order_id,404);
        $conversation->loadMissing('order');
        abort_if($this->isWriteLocked($conversation),422,'Der 7-Tage-Zeitraum nach Auftragsabschluss ist abgelaufen. Der Chat ist nur noch lesbar.');

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
        return $order
            && $order->status==='completed'
            && $order->completed_at
            && $order->completed_at->copy()->addDays(7)->isPast();
    }
}
