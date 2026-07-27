<?php

namespace App\Http\Controllers\Webhook;

use App\Events\AssistantMessageReceived;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Shipbot extends Controller
{
    public function events(Request $request)
    {
        $secret = config('services.shipbot.secret');
        if (blank($secret)) {
            auditLogWebhookFailure('shipbot', 'secret_not_configured');

            return response()->json(['message' => 'Not configured.'], 401);
        }

        $signature = Str::after($request->header('X-Shipbot-Signature', ''), 'sha256=');
        $hmac = hash_hmac('sha256', $request->getContent(), $secret);
        if (! isDev() && ! hash_equals($hmac, $signature)) {
            auditLogWebhookFailure('shipbot', 'invalid_signature');

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $userId = $request->input('user_id');
        $threadId = $request->input('thread_id');
        if (! is_numeric($userId) || blank($threadId)) {
            return response()->json(['message' => 'Invalid payload.'], 422);
        }

        event(new AssistantMessageReceived((int) $userId, (string) $threadId, $request->all()));

        return response()->json(['message' => 'ok']);
    }
}
