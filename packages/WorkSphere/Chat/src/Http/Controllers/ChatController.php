<?php

namespace WorkSphere\Chat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use WorkSphere\Chat\ChatManager;
use WorkSphere\Chat\Models\Chat;

class ChatController extends Controller
{
    public function __construct(
        protected ChatManager $manager
    ) {}

    /**
     * List all chats for the authenticated user.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        
        // This expects the User model to use the InteractsWithChat trait
        $chats = $user->pkgChats()
            ->with(['participants', 'messages' => function($q) {
                $q->latest()->limit(1);
            }])
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $chats->map(fn($chat) => $this->mapChat($chat))
        ]);
    }

    /**
     * Create a test chat for the lab.
     */
    public function seedLab(): JsonResponse
    {
        $user = Auth::user();
        $chat = $this->manager->createChat('dm', 'Lab Test Chat');
        $this->manager->addParticipant($chat, $user->id, 'owner');
        
        return response()->json([
            'data' => [
                'public_id' => $chat->public_id
            ]
        ]);
    }

    /**
     * Join an existing lab chat.
     */
    public function join(string $publicId): JsonResponse
    {
        $user = Auth::user();
        $chat = Chat::where('public_id', $publicId)->firstOrFail();
        
        $this->manager->addParticipant($chat, $user->id, 'member');
        
        return response()->json([
            'data' => $this->mapChat($chat)
        ]);
    }

    /**
     * Get a specific chat.
     */
    public function show(string $publicId): JsonResponse
    {
        $chat = Chat::where('public_id', $publicId)
            ->with(['participants', 'messages' => function($q) {
                $q->latest()->limit(50);
            }])
            ->firstOrFail();

        abort_if(! $chat->participants->contains(Auth::id()), 403);

        return response()->json([
            'data' => $this->mapChat($chat)
        ]);
    }

    /**
     * Send a message to a chat.
     */
    public function send(Request $request, string $publicId): JsonResponse
    {
        $chat = Chat::where('public_id', $publicId)->firstOrFail();
        abort_if(! $chat->participants->contains(Auth::id()), 403);

        $validated = $request->validate([
            'content' => 'required|string',
        ]);

        $message = $this->manager->sendMessage(
            $chat, 
            Auth::id(), 
            $validated['content']
        );

        return response()->json([
            'data' => $message
        ]);
    }

    /**
     * Standardize chat output.
     */
    protected function mapChat(Chat $chat): array
    {
        return [
            'id' => $chat->public_id,
            'name' => $chat->name,
            'type' => $chat->type,
            'participants' => $chat->participants->map(fn($p) => [
                'id' => $p->public_id,
                'name' => $p->name,
                'avatar' => $p->avatar_url,
            ]),
            'latest_message' => $chat->messages->first(),
            'updated_at' => $chat->updated_at->toIso8601String(),
        ];
    }
}
