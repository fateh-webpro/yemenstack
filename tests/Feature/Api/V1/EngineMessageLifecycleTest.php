<?php

namespace Tests\Feature\Api\V1;

use App\Models\ApiCredential;
use App\Models\Client;
use App\Models\Message;
use App\Models\MessageAttempt;
use App\Models\WhatsappAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EngineMessageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/whatsapp/engine/messages/pending');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Invalid API token.',
            ]);
    }

    public function test_pending_endpoint_requires_messages_read_ability(): void
    {
        [$plainToken] = $this->createCredential([
            'abilities' => ['messages:send'],
        ]);

        $response = $this->withToken($plainToken)
            ->getJson('/api/v1/whatsapp/engine/messages/pending');

        $response
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'This API token is not allowed to read messages.',
            ]);
    }

    public function test_pending_endpoint_rejects_inconsistent_token(): void
    {
        $foreignClient = $this->createClient('Foreign Pending Client');

        [$plainToken, , $credentialClient, $credentialAccount] = $this->createCredential([
            'abilities' => ['messages:read', 'messages:send'],
            'account_client_id' => $foreignClient->id,
        ]);

        $message = $this->createMessage($credentialClient, $credentialAccount, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700000010',
        ]);

        $response = $this->withToken($plainToken)
            ->getJson('/api/v1/whatsapp/engine/messages/pending');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Invalid API token.',
            ]);

        $this->assertSame(Message::STATUS_PENDING, $message->fresh()->status);
        $this->assertDatabaseCount(MessageAttempt::class, 0);
    }

    public function test_pending_endpoint_returns_only_due_outbound_pending_messages_for_bound_account(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:read', 'messages:send'],
        ]);

        $otherClient = $this->createClient('Other Client');
        $otherAccount = $this->createWhatsappAccount($otherClient, 'Other Account', 'engine_other_account');

        $firstPending = $this->createMessage($client, $account, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700000001',
            'scheduled_at' => null,
        ]);

        $secondPending = $this->createMessage($client, $account, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700000002',
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->createMessage($client, $account, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700000003',
            'scheduled_at' => now()->addHour(),
        ]);

        $this->createMessage($client, $account, [
            'status' => Message::STATUS_QUEUED,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700000004',
        ]);

        $this->createMessage($client, $account, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_INBOUND,
            'recipient' => '967700000005',
        ]);

        $this->createMessage($otherClient, $otherAccount, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700000006',
        ]);

        $response = $this->withToken($plainToken)
            ->getJson('/api/v1/whatsapp/engine/messages/pending?limit=1');

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'meta' => [
                    'count' => 1,
                    'limit' => 1,
                ],
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstPending->id)
            ->assertJsonPath('data.0.status', Message::STATUS_PENDING)
            ->assertJsonMissingPath('data.0.client_id')
            ->assertJsonMissingPath('data.0.whatsapp_account_id');

        $this->assertSame(Message::STATUS_PENDING, $firstPending->fresh()->status);
        $this->assertSame(Message::STATUS_PENDING, $secondPending->fresh()->status);
    }

    public function test_claim_endpoint_requires_messages_send_ability(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:read'],
        ]);

        $message = $this->createMessage($client, $account, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        $response = $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$message->id}/claim");

        $response
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'This API token is not allowed to claim messages.',
            ]);

        $this->assertDatabaseCount(MessageAttempt::class, 0);
        $this->assertSame(Message::STATUS_PENDING, $message->fresh()->status);
    }

    public function test_claim_endpoint_moves_message_to_queued_and_creates_first_attempt(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:send', 'messages:read'],
        ]);

        $message = $this->createMessage($client, $account, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700000100',
            'body' => 'Pending message',
        ]);

        $response = $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$message->id}/claim");

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Message claimed.',
                'data' => [
                    'message_id' => $message->id,
                    'status' => Message::STATUS_QUEUED,
                    'attempt_number' => 1,
                ],
            ]);

        $attemptId = $response->json('data.attempt_id');

        $this->assertNotNull($attemptId);
        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_QUEUED,
            'recipient' => '967700000100',
            'body' => 'Pending message',
            'client_id' => $client->id,
            'whatsapp_account_id' => $account->id,
        ]);
        $this->assertDatabaseHas(MessageAttempt::class, [
            'id' => $attemptId,
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_QUEUED,
        ]);
        $this->assertDatabaseCount(MessageAttempt::class, 1);
    }

    public function test_claim_endpoint_rejects_non_claimable_messages_without_creating_new_attempts(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:send', 'messages:read'],
        ]);

        $queuedMessage = $this->createMessage($client, $account, [
            'status' => Message::STATUS_QUEUED,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        MessageAttempt::query()->create([
            'message_id' => $queuedMessage->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_QUEUED,
            'response_payload' => null,
            'error_message' => null,
            'attempted_at' => now(),
        ]);

        $sentMessage = $this->createMessage($client, $account, [
            'status' => Message::STATUS_SENT,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        $queuedResponse = $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$queuedMessage->id}/claim");

        $queuedResponse
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Message is not claimable.',
            ]);

        $sentResponse = $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$sentMessage->id}/claim");

        $sentResponse->assertStatus(409);

        $this->assertDatabaseCount(MessageAttempt::class, 1);
    }

    public function test_claim_endpoint_returns_not_found_for_message_from_other_bound_account(): void
    {
        [$plainToken] = $this->createCredential([
            'abilities' => ['messages:send', 'messages:read'],
        ]);

        [, , $otherClient, $otherAccount] = $this->createCredential([
            'client_name' => 'Other Client',
            'account_name' => 'Other Account',
            'session_name' => 'other_session_for_claim',
            'abilities' => ['messages:send', 'messages:read'],
        ]);

        $foreignMessage = $this->createMessage($otherClient, $otherAccount, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        $response = $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$foreignMessage->id}/claim");

        $response
            ->assertNotFound()
            ->assertJson([
                'success' => false,
                'message' => 'Message not found.',
            ]);

        $this->assertDatabaseCount(MessageAttempt::class, 0);
        $this->assertSame(Message::STATUS_PENDING, $foreignMessage->fresh()->status);
    }

    public function test_claim_endpoint_uses_next_attempt_number_when_previous_attempts_exist(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:send', 'messages:read'],
        ]);

        $message = $this->createMessage($client, $account, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        MessageAttempt::query()->create([
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_FAILED,
            'response_payload' => ['reason' => 'old-failure'],
            'error_message' => 'Old failure',
            'attempted_at' => now()->subMinute(),
        ]);

        $response = $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$message->id}/claim");

        $response
            ->assertOk()
            ->assertJsonPath('data.attempt_number', 2);

        $this->assertDatabaseHas(MessageAttempt::class, [
            'message_id' => $message->id,
            'attempt_number' => 2,
            'status' => MessageAttempt::STATUS_QUEUED,
        ]);
    }

    public function test_queued_endpoint_requires_messages_send_ability_and_reads_only_queued_messages(): void
    {
        [$plainTokenReadOnly, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:read'],
        ]);

        $responseForbidden = $this->withToken($plainTokenReadOnly)
            ->getJson('/api/v1/whatsapp/engine/messages/queued');

        $responseForbidden
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'This API token is not allowed to process queued messages.',
            ]);

        [$plainToken, , $clientTwo, $accountTwo] = $this->createCredential([
            'client_name' => 'Queued Client',
            'account_name' => 'Queued Account',
            'session_name' => 'queued_session_primary',
            'abilities' => ['messages:send'],
        ]);

        $firstQueued = $this->createMessage($clientTwo, $accountTwo, [
            'status' => Message::STATUS_QUEUED,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700000201',
        ]);

        $secondQueued = $this->createMessage($clientTwo, $accountTwo, [
            'status' => Message::STATUS_QUEUED,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700000202',
        ]);

        $this->createMessage($clientTwo, $accountTwo, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        $this->createMessage($clientTwo, $accountTwo, [
            'status' => Message::STATUS_SENT,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        $this->createMessage($client, $account, [
            'status' => Message::STATUS_QUEUED,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        $response = $this->withToken($plainToken)
            ->getJson('/api/v1/whatsapp/engine/messages/queued?limit=1');

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'meta' => [
                    'count' => 1,
                    'limit' => 1,
                ],
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstQueued->id);

        $this->assertSame(Message::STATUS_QUEUED, $firstQueued->fresh()->status);
        $this->assertSame(Message::STATUS_QUEUED, $secondQueued->fresh()->status);
    }

    public function test_mark_sent_updates_queued_message_and_existing_attempt(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:send'],
        ]);

        $message = $this->createMessage($client, $account, [
            'status' => Message::STATUS_QUEUED,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        MessageAttempt::query()->create([
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_QUEUED,
            'response_payload' => null,
            'error_message' => null,
            'attempted_at' => now()->subMinute(),
        ]);

        $sentAt = now()->toISOString();

        $response = $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$message->id}/mark-sent", [
                'external_message_id' => 'simulated-engine-message-1',
                'response_payload' => [
                    'provider' => 'local-simulator',
                    'mode' => 'simulation',
                ],
                'sent_at' => $sentAt,
                'mode' => 'simulation',
                'provider' => 'local-simulator',
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Message marked as sent.',
                'data' => [
                    'message_id' => $message->id,
                    'status' => Message::STATUS_SENT,
                    'external_message_id' => 'simulated-engine-message-1',
                    'attempt_status' => MessageAttempt::STATUS_SENT,
                ],
            ]);

        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_SENT,
            'external_message_id' => 'simulated-engine-message-1',
        ]);
        $this->assertDatabaseHas(MessageAttempt::class, [
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_SENT,
            'error_message' => null,
        ]);
        $this->assertDatabaseCount(MessageAttempt::class, 1);

        $attempt = MessageAttempt::query()->where('message_id', $message->id)->firstOrFail();
        $this->assertSame([
            'provider' => 'local-simulator',
            'mode' => 'simulation',
        ], $attempt->response_payload);
    }

    public function test_mark_sent_rejects_non_queued_messages(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:send'],
        ]);

        $message = $this->createMessage($client, $account, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        $response = $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$message->id}/mark-sent", [
                'external_message_id' => 'simulated-engine-message-2',
            ]);

        $response
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Message is not sendable.',
            ]);

        $this->assertDatabaseCount(MessageAttempt::class, 0);
        $this->assertSame(Message::STATUS_PENDING, $message->fresh()->status);
    }

    public function test_mark_sent_creates_attempt_if_queued_message_has_no_attempts(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:send'],
        ]);

        $message = $this->createMessage($client, $account, [
            'status' => Message::STATUS_QUEUED,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        $response = $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$message->id}/mark-sent", [
                'external_message_id' => 'simulated-engine-message-3',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.attempt_status', MessageAttempt::STATUS_SENT);

        $this->assertDatabaseCount(MessageAttempt::class, 1);
        $this->assertDatabaseHas(MessageAttempt::class, [
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_SENT,
        ]);
    }

    public function test_mark_failed_updates_queued_message_and_existing_attempt(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:send'],
        ]);

        $message = $this->createMessage($client, $account, [
            'status' => Message::STATUS_QUEUED,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        MessageAttempt::query()->create([
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_QUEUED,
            'response_payload' => null,
            'error_message' => null,
            'attempted_at' => now()->subMinute(),
        ]);

        $failedAt = now()->toISOString();

        $response = $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$message->id}/mark-failed", [
                'error_message' => 'Simulated provider failure.',
                'response_payload' => [
                    'provider' => 'local-simulator',
                    'mode' => 'simulation',
                ],
                'failed_at' => $failedAt,
                'mode' => 'simulation',
                'provider' => 'local-simulator',
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Message marked as failed.',
                'data' => [
                    'message_id' => $message->id,
                    'status' => Message::STATUS_FAILED,
                    'attempt_status' => MessageAttempt::STATUS_FAILED,
                ],
            ]);

        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_FAILED,
            'error_message' => 'Simulated provider failure.',
        ]);
        $this->assertDatabaseHas(MessageAttempt::class, [
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_FAILED,
            'error_message' => 'Simulated provider failure.',
        ]);
        $this->assertDatabaseCount(MessageAttempt::class, 1);
    }

    public function test_mark_failed_rejects_non_queued_messages(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:send'],
        ]);

        $message = $this->createMessage($client, $account, [
            'status' => Message::STATUS_SENT,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        $response = $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$message->id}/mark-failed", [
                'error_message' => 'Should not update.',
            ]);

        $response
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Message is not sendable.',
            ]);

        $this->assertDatabaseCount(MessageAttempt::class, 0);
        $this->assertSame(Message::STATUS_SENT, $message->fresh()->status);
    }

    public function test_success_lifecycle_runs_pending_to_claim_to_queued_to_sent(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:send', 'messages:read'],
        ]);

        $message = $this->createMessage($client, $account, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700000301',
        ]);

        $this->withToken($plainToken)
            ->getJson('/api/v1/whatsapp/engine/messages/pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->id);

        $claimResponse = $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$message->id}/claim");

        $claimResponse->assertOk();
        $attemptId = $claimResponse->json('data.attempt_id');

        $this->withToken($plainToken)
            ->getJson('/api/v1/whatsapp/engine/messages/queued')
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->id);

        $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$message->id}/mark-sent", [
                'external_message_id' => 'simulated-engine-lifecycle-sent',
            ])
            ->assertOk()
            ->assertJsonPath('data.attempt_id', $attemptId);

        $this->withToken($plainToken)
            ->getJson('/api/v1/whatsapp/engine/messages/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($plainToken)
            ->getJson('/api/v1/whatsapp/engine/messages/queued')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_SENT,
            'external_message_id' => 'simulated-engine-lifecycle-sent',
        ]);
        $this->assertDatabaseHas(MessageAttempt::class, [
            'id' => $attemptId,
            'message_id' => $message->id,
            'status' => MessageAttempt::STATUS_SENT,
        ]);
    }

    public function test_failure_lifecycle_runs_pending_to_claim_to_queued_to_failed(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:send', 'messages:read'],
        ]);

        $message = $this->createMessage($client, $account, [
            'status' => Message::STATUS_PENDING,
            'direction' => Message::DIRECTION_OUTBOUND,
        ]);

        $claimResponse = $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$message->id}/claim");

        $claimResponse->assertOk();
        $attemptId = $claimResponse->json('data.attempt_id');

        $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/messages/{$message->id}/mark-failed", [
                'error_message' => 'Engine simulation failure.',
            ])
            ->assertOk()
            ->assertJsonPath('data.attempt_id', $attemptId);

        $this->withToken($plainToken)
            ->getJson('/api/v1/whatsapp/engine/messages/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($plainToken)
            ->getJson('/api/v1/whatsapp/engine/messages/queued')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_FAILED,
            'error_message' => 'Engine simulation failure.',
        ]);
        $this->assertDatabaseHas(MessageAttempt::class, [
            'id' => $attemptId,
            'message_id' => $message->id,
            'status' => MessageAttempt::STATUS_FAILED,
        ]);
    }

    public function test_account_status_endpoint_updates_only_bound_whatsapp_account(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:send'],
        ]);

        $otherAccount = $this->createWhatsappAccount($client, 'Secondary Account', 'secondary_engine_session');

        $response = $this->withToken($plainToken)
            ->postJson('/api/v1/whatsapp/engine/account/status', [
                'status' => WhatsappAccount::STATUS_CONNECTED,
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'WhatsApp account status updated.',
                'data' => [
                    'whatsapp_account_id' => $account->id,
                    'status' => WhatsappAccount::STATUS_CONNECTED,
                ],
            ]);

        $this->assertDatabaseHas(WhatsappAccount::class, [
            'id' => $account->id,
            'status' => WhatsappAccount::STATUS_CONNECTED,
        ]);
        $this->assertSame(WhatsappAccount::STATUS_CONNECTED, $account->fresh()->status);
        $this->assertNotNull($account->fresh()->last_seen_at);
        $this->assertNull($account->fresh()->qr_expires_at);
        $this->assertSame(WhatsappAccount::STATUS_CONNECTED, $otherAccount->fresh()->status);
    }

    public function test_account_status_endpoint_validates_status_and_note_fields(): void
    {
        [$plainToken] = $this->createCredential([
            'abilities' => ['messages:send'],
        ]);

        $response = $this->withToken($plainToken)
            ->postJson('/api/v1/whatsapp/engine/account/status', [
                'status' => 'invalid-status',
                'note' => str_repeat('a', 1001),
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'note']);
    }

    public function test_account_status_endpoint_requires_messages_send_ability(): void
    {
        [$plainToken] = $this->createCredential([
            'abilities' => ['messages:read'],
        ]);

        $response = $this->withToken($plainToken)
            ->postJson('/api/v1/whatsapp/engine/account/status', [
                'status' => WhatsappAccount::STATUS_CONNECTED,
            ]);

        $response
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'This API token is not allowed to update WhatsApp account status.',
            ]);
    }

    /**
     * @return array{0:string,1:ApiCredential,2:Client,3:WhatsappAccount}
     */
    protected function createCredential(array $overrides = []): array
    {
        $client = $this->createClient(
            $overrides['client_name'] ?? 'Engine Client',
            $overrides['client_active'] ?? true,
            $overrides['client_slug'] ?? null
        );

        $whatsappAccount = $this->createWhatsappAccount(
            $client,
            $overrides['account_name'] ?? 'Engine Account',
            $overrides['session_name'] ?? ('engine_session_' . str()->random(8)),
            $overrides['whatsapp_account_active'] ?? true,
            $overrides['account_status'] ?? WhatsappAccount::STATUS_CONNECTED,
            $overrides['phone_number'] ?? '967700100100',
            $overrides['account_client_id'] ?? null
        );

        $plainToken = ApiCredential::generatePlainToken();

        $credential = ApiCredential::query()->create([
            'client_id' => $overrides['credential_client_id'] ?? $client->id,
            'whatsapp_account_id' => $overrides['credential_whatsapp_account_id'] ?? $whatsappAccount->id,
            'name' => $overrides['credential_name'] ?? 'Engine Credential',
            'token_hash' => ApiCredential::hashToken($plainToken),
            'abilities' => $overrides['abilities'] ?? ['messages:send', 'messages:read'],
            'last_used_at' => null,
            'expires_at' => $overrides['expires_at'] ?? Carbon::today()->addDays(7)->toDateString(),
            'is_active' => $overrides['is_active'] ?? true,
        ]);

        return [$plainToken, $credential->fresh(), $client->fresh(), $whatsappAccount->fresh()];
    }

    protected function createClient(string $name, bool $isActive = true, ?string $slug = null): Client
    {
        return Client::query()->create([
            'name' => $name,
            'slug' => $slug ?? str($name)->slug() . '-' . str()->random(6),
            'contact_name' => 'Engine Contact',
            'phone' => '967700200200',
            'email' => str($name)->slug() . '@example.test',
            'is_active' => $isActive,
            'notes' => null,
        ]);
    }

    protected function createWhatsappAccount(
        Client $client,
        string $name,
        string $sessionName,
        bool $isActive = true,
        string $status = WhatsappAccount::STATUS_CONNECTED,
        string $phoneNumber = '967700300300',
        ?int $clientIdOverride = null,
    ): WhatsappAccount {
        return WhatsappAccount::query()->create([
            'client_id' => $clientIdOverride ?? $client->id,
            'name' => $name,
            'phone_number' => $phoneNumber,
            'session_name' => $sessionName,
            'status' => $status,
            'last_seen_at' => null,
            'qr_expires_at' => null,
            'is_active' => $isActive,
            'notes' => null,
        ]);
    }

    protected function createMessage(Client $client, WhatsappAccount $account, array $overrides = []): Message
    {
        return Message::query()->create(array_merge([
            'client_id' => $client->id,
            'whatsapp_account_id' => $account->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700400400',
            'sender' => $account->phone_number,
            'message_type' => Message::TYPE_TEXT,
            'body' => 'Engine lifecycle test message',
            'payload' => ['source' => 'engine-feature-test'],
            'status' => Message::STATUS_PENDING,
            'external_message_id' => null,
            'scheduled_at' => null,
            'sent_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ], $overrides));
    }
}
