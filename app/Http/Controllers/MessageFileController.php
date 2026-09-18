<?php
namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageFileController extends Controller
{
    public function __invoke(Message $message): StreamedResponse
    {
        $message->loadMissing('conversation');
        $user=request()->user();

        $allowed=$message->conversation->user_id===$user->id
            || ($user->isAdmin() && $user->hasPermission('messages.manage'));

        abort_unless($allowed,403);
        abort_unless($message->attachment_path,404);

        return Storage::disk('messages')->download(
            $message->attachment_path,
            $message->attachment_original_name ?: basename($message->attachment_path)
        );
    }
}
