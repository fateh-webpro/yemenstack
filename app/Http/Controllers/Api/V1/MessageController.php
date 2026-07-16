<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreMessageRequest;
use App\Models\Message;
use Illuminate\Http\JsonResponse;

class MessageController extends Controller
{
    public function store(StoreMessageRequest $request): JsonResponse
    {
        $credential = $request->attributes->get('api_credential');
        $client = $request->attributes->get('client');
        $whatsappAccount = $request->attributes->get('whatsapp_account');

        if (! $credential || ! $credential->hasAbility('messages:send')) {
            return response()->json([
                'success' => false,
                'message' => 'This API token is not allowed to send messages.',
            ], 403);
        }

        $message = Message::query()->create([
            'client_id' => $client->id,
            'whatsapp_account_id' => $whatsappAccount->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => $request->validated('recipient'),
            'sender' => $whatsappAccount->phone_number,
            'message_type' => Message::TYPE_TEXT,
            'body' => $request->validated('body'),
            'payload' => $request->validated('payload'),
            'status' => Message::STATUS_PENDING,
            'scheduled_at' => $request->validated('scheduled_at'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message accepted.',
            'data' => [
                'message_id' => $message->id,
                'status' => $message->status,
            ],
        ], 201);
    }
}