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

class WhatsappEngineAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_rejects_when_internal_token_is_not_configured(): void
    {
        config()->set('services.whatsapp_engine.internal_token', '');

        $response = $this->withToken('test-internal-token')
            ->getJson('/api/v1/whatsapp/engine/health');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);

        $this->assertNoApplicationDataWasChanged();
    }

    public function test_health_endpoint_rejects_requests_without_authorization_header(): void
    {
        config()->set('services.whatsapp_engine.internal_token', 'test-internal-token');

        $response = $this->getJson('/api/v1/whatsapp/engine/health');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);

        $this->assertNoApplicationDataWasChanged();
    }

    public function test_health_endpoint_rejects_invalid_internal_token(): void
    {
        config()->set('services.whatsapp_engine.internal_token', 'test-internal-token');

        $response = $this->withToken('wrong-internal-token')
            ->getJson('/api/v1/whatsapp/engine/health');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);

        $this->assertStringNotContainsString('test-internal-token', $response->getContent());
        $this->assertNoApplicationDataWasChanged();
    }

    public function test_health_endpoint_rejects_non_bearer_authorization_header(): void
    {
        config()->set('services.whatsapp_engine.internal_token', 'test-internal-token');

        $response = $this->withHeaders([
            'Authorization' => 'Token test-internal-token',
        ])->getJson('/api/v1/whatsapp/engine/health');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);

        $this->assertNoApplicationDataWasChanged();
    }

    public function test_health_endpoint_accepts_valid_internal_token(): void
    {
        config()->set('services.whatsapp_engine.internal_token', 'test-internal-token');

        $response = $this->withToken('test-internal-token')
            ->getJson('/api/v1/whatsapp/engine/health');

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'service' => 'whatsapp-engine',
                    'status' => 'ok',
                ],
            ]);

        $this->assertStringNotContainsString('test-internal-token', $response->getContent());
        $this->assertNoApplicationDataWasChanged();
    }

    public function test_internal_token_does_not_authenticate_existing_engine_message_routes(): void
    {
        config()->set('services.whatsapp_engine.internal_token', 'test-internal-token');

        $response = $this->withToken('test-internal-token')
            ->getJson('/api/v1/whatsapp/engine/messages/pending');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Invalid API token.',
            ]);
    }

    public function test_existing_engine_routes_still_work_with_api_credential_authentication(): void
    {
        config()->set('services.whatsapp_engine.internal_token', 'test-internal-token');

        [$plainToken, , $client, $account] = $this->createCredential([
            'abilities' => ['messages:read', 'messages:send'],
        ]);

        $message = Message::query()->create([
            'client_id' => $client->id,
            'whatsapp_account_id' => $account->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700400400',
            'sender' => $account->phone_number,
            'message_type' => Message::TYPE_TEXT,
            'body' => 'Compatibility test message',
            'payload' => ['source' => 'engine-auth-test'],
            'status' => Message::STATUS_PENDING,
            'external_message_id' => null,
            'scheduled_at' => null,
            'sent_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ]);

        $response = $this->withToken($plainToken)
            ->getJson('/api/v1/whatsapp/engine/messages/pending');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->id)
            ->assertJsonPath('data.0.status', Message::STATUS_PENDING);
    }

    /**
     * @return array{0:string,1:ApiCredential,2:Client,3:WhatsappAccount}
     */
    protected function createCredential(array $overrides = []): array
    {
        $client = Client::query()->create([
            'name' => $overrides['client_name'] ?? 'Engine Auth Client',
            'slug' => $overrides['client_slug'] ?? 'engine-auth-client-' . str()->random(6),
            'contact_name' => 'Engine Auth Contact',
            'phone' => '967700000000',
            'email' => 'engine-auth-' . str()->random(6) . '@example.test',
            'is_active' => $overrides['client_active'] ?? true,
            'notes' => null,
        ]);

        $whatsappAccount = WhatsappAccount::query()->create([
            'client_id' => $overrides['account_client_id'] ?? $client->id,
            'name' => $overrides['account_name'] ?? 'Engine Auth Account',
            'phone_number' => $overrides['phone_number'] ?? '967711111111',
            'session_name' => $overrides['session_name'] ?? 'engine_auth_session_' . str()->random(8),
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
            'name' => $overrides['credential_name'] ?? 'Engine Auth Credential',
            'token_hash' => ApiCredential::hashToken($plainToken),
            'abilities' => $overrides['abilities'] ?? ['messages:read', 'messages:send'],
            'last_used_at' => null,
            'expires_at' => $overrides['expires_at'] ?? Carbon::today()->addDays(7)->toDateString(),
            'is_active' => $overrides['is_active'] ?? true,
        ]);

        return [$plainToken, $credential->fresh(), $client->fresh(), $whatsappAccount->fresh()];
    }

    protected function assertNoApplicationDataWasChanged(): void
    {
        $this->assertDatabaseCount(Client::class, 0);
        $this->assertDatabaseCount(WhatsappAccount::class, 0);
        $this->assertDatabaseCount(Message::class, 0);
        $this->assertDatabaseCount(MessageAttempt::class, 0);
        $this->assertDatabaseCount(ApiCredential::class, 0);
    }
}