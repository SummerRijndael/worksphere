<?php

namespace App\Http\Controllers\Api;

use App\Events\Chat\MessageCreated;
use App\Events\MessageRead;
use App\Events\UserTyping;
use App\Http\Controllers\Controller;
use App\Models\Chat\Chat;
use App\Models\Chat\ChatMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DevController extends Controller
{
    /**
     * Ensure dev endpoints are only accessible in local/testing environments.
     */
    public function __construct()
    {
        if (! app()->environment('local', 'testing')) {
            abort(403, 'Development endpoints are disabled in production.');
        }
    }

    /**
     * List users for the debug selector.
     */
    public function getUsers()
    {
        return User::select('id', 'public_id', 'name', 'email', 'avatar')
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'public_id' => $user->public_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                ];
            });
    }

    /**
     * List chats with participants for debugging.
     */
    public function getChats()
    {
        return Chat::with(['participants:id,public_id,name'])
            ->orderBy('updated_at', 'desc')
            ->limit(30)
            ->get()
            ->map(function ($chat) {
                return [
                    'id' => $chat->id,
                    'public_id' => $chat->public_id,
                    'name' => $chat->name,
                    'type' => $chat->type,
                    'participants' => $chat->participants->map(fn ($p) => [
                        'id' => $p->id,
                        'public_id' => $p->public_id,
                        'name' => $p->name,
                    ]),
                ];
            });
    }

    /**
     * Force login as a specific user.
     */
    public function loginAs(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($request->user_id);
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Logged in as '.$user->name,
            'user' => $user,
        ]);
    }

    /**
     * Send a test message as a specific user.
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'as_user_id' => 'required|exists:users,id',
            'chat_public_id' => 'required|string',
            'content' => 'required|string|max:5000',
        ]);

        $user = User::findOrFail($request->as_user_id);
        $chat = Chat::where('public_id', $request->chat_public_id)->firstOrFail();

        // Create the message
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'content' => $request->content,
        ]);

        // Load relations for broadcast
        $message->load('user:id,public_id,name', 'media', 'chat');

        // Log for debugging
        Log::channel('single')->info('[DEV] sendMessage triggered', [
            'chat_public_id' => $chat->public_id,
            'chat_type' => $chat->type,
            'message_id' => $message->id,
            'message_public_id' => $message->public_id,
            'sender' => $user->name,
            'channel' => ($chat->type === 'dm' ? 'dm' : 'group').'.'.$chat->public_id,
        ]);

        // Dispatch the broadcast event
        broadcast(new MessageCreated($message));

        return response()->json([
            'success' => true,
            'message_id' => $message->public_id,
            'channel' => ($chat->type === 'dm' ? 'dm' : 'group').'.'.$chat->public_id,
            'event' => 'MessageCreated',
        ]);
    }

    /**
     * Trigger typing indicator as a specific user.
     */
    public function triggerTyping(Request $request)
    {
        $request->validate([
            'as_user_id' => 'required|exists:users,id',
            'chat_public_id' => 'required|string',
        ]);

        $user = User::findOrFail($request->as_user_id);
        $chat = Chat::where('public_id', $request->chat_public_id)->firstOrFail();

        Log::channel('single')->info('[DEV] triggerTyping', [
            'chat_public_id' => $chat->public_id,
            'user' => $user->name,
            'channel' => ($chat->type === 'dm' ? 'dm' : 'group').'.'.$chat->public_id,
        ]);

        broadcast(new UserTyping($chat->public_id, $user, $chat->type));

        return response()->json([
            'success' => true,
            'channel' => ($chat->type === 'dm' ? 'dm' : 'group').'.'.$chat->public_id,
            'event' => 'TypingStarted',
        ]);
    }

    /**
     * Mark messages as seen (simulate read receipt).
     */
    public function markSeen(Request $request)
    {
        $request->validate([
            'as_user_id' => 'required|exists:users,id',
            'chat_public_id' => 'required|string',
        ]);

        $user = User::findOrFail($request->as_user_id);
        $chat = Chat::where('public_id', $request->chat_public_id)->firstOrFail();

        // Get latest message in chat
        $latestMessage = ChatMessage::where('chat_id', $chat->id)
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $latestMessage) {
            return response()->json(['error' => 'No messages in chat'], 404);
        }

        // Update participant pivot
        $chat->participants()->updateExistingPivot($user->id, [
            'last_read_message_id' => $latestMessage->id,
        ]);

        Log::channel('single')->info('[DEV] markSeen', [
            'chat_public_id' => $chat->public_id,
            'user' => $user->name,
            'last_read_message_id' => $latestMessage->public_id,
        ]);

        broadcast(new MessageRead($chat->public_id, $latestMessage->public_id, $user));

        return response()->json([
            'success' => true,
            'channel' => 'user.'.$user->public_id,
            'event' => 'MessageRead',
            'last_read_message_id' => $latestMessage->public_id,
        ]);
    }

    /**
     * Trigger a test broadcast event (legacy).
     */
    public function broadcastTest(Request $request)
    {
        $request->validate([
            'event_type' => 'required|string',
            'channel' => 'required|string',
            'data' => 'nullable|array',
        ]);

        return response()->json([
            'message' => 'Broadcast triggered (simulated)',
            'payload' => $request->all(),
        ]);
    }
}
