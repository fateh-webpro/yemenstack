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

class MessageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_endpoint_rejects_requests_without_authorization_token(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'recipient' => '967777000000',
            'body' => 'Test message',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Invalid API token.',
            ]);

        $this->assertDatabaseCount(Message::class, 0);
    }

    public function test_messages_endpoint_rejects_requests_with_invalid_token(): void
    {
        $response = $this->withToken('yswg_invalid_token')
            ->postJson('/api/v1/messages', [
                'recipient' => '967777000000',
                'body' => 'Test message',
            ]);

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Invalid API token.',
            ]);

        $this->assertDatabaseCount(Message::class, 0);
    }

    public function test_messages_endpoint_rejects_inactive_token(): void
    {
        [$plainToken] = $this->createCredential([
            'is_active' => false,
        ]);

        $response = $this->withToken($plainToken)
            ->postJson('/api/v1/messages', [
                'recipient' => '967777000000',
                'body' => 'Test message',
            ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount(Message::class, 0);
    }

    public function test_messages_endpoint_rejects_expired_token(): void
    {
        [$plainToken] = $this->createCredential([
            'expires_at' => Carbon::yesterday()->toDateString(),
        ]);

        $response = $this->withToken($plainToken)
            ->postJson('/api/v1/messages', [
                'recipient' => '967777000000',
                'body' => 'Test message',
            ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount(Message::class, 0);
    }

    public function test_messages_endpoint_rejects_token_without_messages_send_ability(): void
    {
        [$plainToken] = $this->createCredential([
            'abilities' => ['messages:read'],
        ]);

        $response = $this->withToken($plainToken)
            ->postJson('/api/v1/messages', [
                'recipient' => '967777000000',
                'body' => 'Test message',
            ]);

        $response
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'This API token is not allowed to send messages.',
            ]);

        $this->assertDatabaseCount(Message::class, 0);
    }

    public function test_messages_endpoint_rejects_token_when_client_is_inactive(): void
    {
        [$plainToken] = $this->createCredential([
            'client_active' => false,
        ]);

        $response = $this->withToken($plainToken)
            ->postJson('/api/v1/messages', [
                'recipient' => '967777000000',
                'body' => 'Test message',
            ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount(Message::class, 0);
    }

    public function test_messages_endpoint_rejects_token_when_whatsapp_account_is_inactive(): void
    {
        [$plainToken] = $this->createCredential([
            'whatsapp_account_active' => false,
        ]);

        $response = $this->withToken($plainToken)
            ->postJson('/api/v1/messages', [
                'recipient' => '967777000000',
                'body' => 'Test message',
            ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount(Message::class, 0);
    }

    public function test_messages_endpoint_validates_required_payload_fields(): void
    {
        [$plainToken] = $this->createCredential();

        $response = $this->withToken($plainToken)
            ->postJson('/api/v1/messages', []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipient', 'body']);

        $this->assertDatabaseCount(Message::class, 0);
    }

    public function test_messages_endpoint_validates_body_length(): void
    {
        [$plainToken] = $this->createCredential();

        $response = $this->withToken($plainToken)
            ->postJson('/api/v1/messages', [
                'recipient' => '967777000000',
                'body' => str_repeat('a', 5001),
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);

        $this->assertDatabaseCount(Message::class, 0);
    }

    public function test_messages_endpoint_creates_a_pending_message_for_the_token_client_and_account(): void
    {
        [$plainToken, $credential, $client, $whatsappAccount] = $this->createCredential();

        $scheduledAt = Carbon::now()->addMinutes(15)->toISOString();

        $response = $this->withToken($plainToken)
            ->postJson('/api/v1/messages', [
                'recipient' => '967777000000',
                'body' => 'Outbound test message',
                'scheduled_at' => $scheduledAt,
                'payload' => [
                    'source' => 'feature-test',
                ],
                'client_id' => 999999,
                'whatsapp_account_id' => 999999,
                'direction' => Message::DIRECTION_INBOUND,
                'status' => Message::STATUS_SENT,
            ]);

        $response
            ->assertCreated()
            ->assertJson([
                'success' => true,
                'message' => 'Message accepted.',
                'data' => [
                    'status' => Message::STATUS_PENDING,
                ],
            ]);

        $messageId = $response->json('data.message_id');

        $this->assertNotNull($messageId);
        $this->assertDatabaseCount(Message::class, 1);
        $this->assertDatabaseCount(MessageAttempt::class, 0);
        $this->assertDatabaseHas(Message::class, [
            'id' => $messageId,
            'client_id' => $client->id,
            'whatsapp_account_id' => $whatsappAccount->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967777000000',
            'sender' => $whatsappAccount->phone_number,
            'message_type' => Message::TYPE_TEXT,
            'body' => 'Outbound test message',
            'status' => Message::STATUS_PENDING,
        ]);

        $message = Message::query()->findOrFail($messageId);

        $this->assertSame(['source' => 'feature-test'], $message->payload);
        $this->assertSame($credential->client_id, $message->client_id);
        $this->assertSame($credential->whatsapp_account_id, $message->whatsapp_account_id);
    }

    public function test_messages_endpoint_isolated_by_api_credential_binding_even_if_payload_contains_other_ids(): void
    {
        [$plainTokenOne, , $clientOne, $accountOne] = $this->createCredential([
            'client_name' => 'Client One',
            'account_name' => 'Account One',
            'session_name' => 'session_one',
        ]);

        [, , $clientTwo, $accountTwo] = $this->createCredential([
            'client_name' => 'Client Two',
            'account_name' => 'Account Two',
            'session_name' => 'session_two',
        ]);

        $response = $this->withToken($plainTokenOne)
            ->postJson('/api/v1/messages', [
                'recipient' => '967777000000',
                'body' => 'Isolation test message',
                'client_id' => $clientTwo->id,
                'whatsapp_account_id' => $accountTwo->id,
            ]);

        $response->assertCreated();

        $messageId = $response->json('data.message_id');

        $this->assertDatabaseHas(Message::class, [
            'id' => $messageId,
            'client_id' => $clientOne->id,
            'whatsapp_account_id' => $accountOne->id,
        ]);

        $this->assertDatabaseMissing(Message::class, [
            'id' => $messageId,
            'client_id' => $clientTwo->id,
            'whatsapp_account_id' => $accountTwo->id,
        ]);
    }

    public function test_me_endpoint_returns_bound_client_and_account_without_sensitive_fields(): void
    {
        [$plainToken, $credential, $client, $whatsappAccount] = $this->createCredential([
            'abilities' => ['messages:send', 'messages:read'],
        ]);

        $response = $this->withToken($plainToken)->getJson('/api/v1/me');

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'client' => $client->name,
                'whatsapp_account' => $whatsappAccount->name,
                'abilities' => $credential->abilities,
                'expires_at' => $credential->expires_at?->toDateString(),
            ])
            ->assertJsonMissingPath('token_hash');
    }

    public function test_me_endpoint_rejects_invalid_token(): void
    {
        $response = $this->withToken('yswg_invalid_token')->getJson('/api/v1/me');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Invalid API token.',
            ]);
    }

    public function test_api_credential_is_usable_only_when_whatsapp_account_belongs_to_same_client(): void
    {
        [, $validCredential] = $this->createCredential();

        $this->assertTrue($validCredential->fresh()->isUsable());

        $otherClient = Client::query()->create([
            'name' => 'Foreign Client',
            'slug' => 'foreign-client',
            'is_active' => true,
        ]);

        [, $invalidCredential] = $this->createCredential([
            'account_client_id' => $otherClient->id,
        ]);

        $this->assertFalse($invalidCredential->fresh()->isUsable());
    }

    public function test_messages_endpoint_rejects_inconsistent_token(): void
    {
        $otherClient = Client::query()->create([
            'name' => 'Foreign Message Client',
            'slug' => 'foreign-message-client',
            'is_active' => true,
        ]);

        [$plainToken] = $this->createCredential([
            'account_client_id' => $otherClient->id,
        ]);

        $response = $this->withToken($plainToken)
            ->postJson('/api/v1/messages', [
                'recipient' => '967777000000',
                'body' => 'Test message',
            ]);

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Invalid API token.',
            ]);

        $this->assertDatabaseCount(Message::class, 0);
    }

    public function test_me_endpoint_rejects_inconsistent_token(): void
    {
        $otherClient = Client::query()->create([
            'name' => 'Foreign Me Client',
            'slug' => 'foreign-me-client',
            'is_active' => true,
        ]);

        [$plainToken] = $this->createCredential([
            'account_client_id' => $otherClient->id,
        ]);

        $response = $this->withToken($plainToken)
            ->getJson('/api/v1/me');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Invalid API token.',
            ]);
    }

    /**
     * @return array{0:string,1:ApiCredential,2:Client,3:WhatsappAccount}
     */
    protected function createCredential(array $overrides = []): array
    {
        $client = Client::query()->create([
            'name' => $overrides['client_name'] ?? 'Test Client',
            'slug' => $overrides['client_slug'] ?? strtolower(str_replace(' ', '-', $overrides['client_name'] ?? 'test-client')) . '-' . str()->random(6),
            'contact_name' => 'API Owner',
            'phone' => '967700000000',
            'email' => strtolower(str_replace(' ', '.', $overrides['client_name'] ?? 'test.client')) . '@example.test',
            'is_active' => $overrides['client_active'] ?? true,
            'notes' => null,
        ]);

        $whatsappAccount = WhatsappAccount::query()->create([
            'client_id' => $overrides['account_client_id'] ?? $client->id,
            'name' => $overrides['account_name'] ?? 'Primary Account',
            'phone_number' => $overrides['phone_number'] ?? '967711111111',
            'session_name' => $overrides['session_name'] ?? 'session_' . str()->random(8),
            'status' => $overrides['account_status'] ?? WhatsappAccount::STATUS_CONNECTED,
            'last_seen_at' => null,
            'qr_expires_at' => null,
            'is_active' => $overrides['whatsapp_account_active'] ?? true,
            'notes' => null,
        ]);

        $plainToken = ApiCredential::generatePlainToken();

        $credential = ApiCredential::query()->create([
            'client_id' => $overrides['credential_client_id'] ?? $client->id,
            'whatsapp_account_id' => $overrides['credential_whatsapp_account_id'] ?? $whatsappAccount->id,
            'name' => $overrides['credential_name'] ?? 'Primary API Credential',
            'token_hash' => ApiCredential::hashToken($plainToken),
            'abilities' => $overrides['abilities'] ?? ['messages:send'],
            'last_used_at' => $overrides['last_used_at'] ?? null,
            'expires_at' => $overrides['expires_at'] ?? Carbon::today()->addDays(7)->toDateString(),
            'is_active' => $overrides['is_active'] ?? true,
        ]);

        return [$plainToken, $credential->fresh(), $client->fresh(), $whatsappAccount->fresh()];
    }
}
