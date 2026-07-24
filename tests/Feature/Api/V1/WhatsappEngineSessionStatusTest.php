<?php

namespace Tests\Feature\Api\V1;

use App\Models\ApiCredential;
use App\Models\Client;
use App\Models\Message;
use App\Models\MessageAttempt;
use App\Models\WhatsappAccount;
use App\Models\WhatsappPairingToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhatsappEngineSessionStatusTest extends TestCase
{
    use RefreshDatabase;

    protected string $internalToken = 'test-internal-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp_engine.internal_token', $this->internalToken);
    }

    public function test_session_status_route_requires_internal_token(): void
    {
        $account = $this->createWhatsappAccount();

        $this->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/status", [
            'status' => WhatsappAccount::STATUS_CONNECTING,
        ])
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_session_status_route_rejects_invalid_internal_token(): void
    {
        $account = $this->createWhatsappAccount();

        $this->withToken('wrong-token')
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/status", [
                'status' => WhatsappAccount::STATUS_CONNECTING,
            ])
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_api_credential_token_cannot_access_central_session_status_route_or_update_last_used_at(): void
    {
        [$plainToken, $credential, , $account] = $this->createCredential();

        $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/status", [
                'status' => WhatsappAccount::STATUS_CONNECTING,
            ])
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);

        $this->assertNull($credential->fresh()->last_used_at);
    }

    public function test_connecting_and_qr_required_statuses_update_without_storing_qr_raw(): void
    {
        $account = $this->createWhatsappAccount();

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/status", [
                'status' => WhatsappAccount::STATUS_CONNECTING,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WhatsappAccount::STATUS_CONNECTING);

        $response = $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/status", [
                'status' => WhatsappAccount::STATUS_QR_REQUIRED,
                'qr' => 'RAW-QR-SHOULD-BE-IGNORED',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.whatsapp_account_id', $account->id)
            ->assertJsonPath('data.status', WhatsappAccount::STATUS_QR_REQUIRED)
            ->assertJsonMissingPath('data.qr');

        $account->refresh();

        $this->assertSame(WhatsappAccount::STATUS_QR_REQUIRED, $account->status);
        $this->assertNotNull($account->qr_expires_at);
        $this->assertStringNotContainsString('RAW-QR-SHOULD-BE-IGNORED', (string) $account->notes);
        $this->assertNoOperationalRecordsWereCreated();
    }

    public function test_authenticated_and_connected_statuses_update_expected_fields(): void
    {
        $account = $this->createWhatsappAccount();
        $qrExpiresAt = now()->addMinutes(2);
        $account->update([
            'status' => WhatsappAccount::STATUS_QR_REQUIRED,
            'qr_expires_at' => $qrExpiresAt,
        ]);

        $lastSeenAt = Carbon::parse('2026-07-24 12:30:00');

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/status", [
                'status' => 'authenticated',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'authenticated');

        $this->assertNull($account->fresh()->qr_expires_at);

        $response = $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/status", [
                'status' => WhatsappAccount::STATUS_CONNECTED,
                'phone_number' => '967733333333',
                'last_seen_at' => $lastSeenAt->toISOString(),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', WhatsappAccount::STATUS_CONNECTED)
            ->assertJsonPath('data.phone_number', '967733333333');

        $account->refresh();

        $this->assertSame(WhatsappAccount::STATUS_CONNECTED, $account->status);
        $this->assertSame('967733333333', $account->phone_number);
        $this->assertTrue($account->last_seen_at?->equalTo($lastSeenAt));
        $this->assertNull($account->qr_expires_at);
    }

    public function test_disconnected_and_error_statuses_append_sanitized_notes(): void
    {
        $account = $this->createWhatsappAccount();

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/status", [
                'status' => WhatsappAccount::STATUS_DISCONNECTED,
                'reason' => 'network drop',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WhatsappAccount::STATUS_DISCONNECTED);

        $response = $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/status", [
                'status' => WhatsappAccount::STATUS_ERROR,
                'error_code' => 'AUTH_FAILURE',
                'error_message' => 'Authentication failed.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', WhatsappAccount::STATUS_ERROR);

        $account->refresh();

        $this->assertSame(WhatsappAccount::STATUS_ERROR, $account->status);
        $this->assertNull($account->qr_expires_at);
        $this->assertStringContainsString('reason: network drop', (string) $account->notes);
        $this->assertStringContainsString('error_code: AUTH_FAILURE', (string) $account->notes);
        $this->assertStringContainsString('error_message: Authentication failed.', (string) $account->notes);
    }

    public function test_session_status_route_rejects_invalid_status_value(): void
    {
        $account = $this->createWhatsappAccount();

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/status", [
                'status' => 'invalid-status',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_legacy_account_status_endpoint_still_works_after_central_status_route_addition(): void
    {
        [$plainToken, , , $account] = $this->createCredential();

        $this->withToken($plainToken)
            ->postJson('/api/v1/whatsapp/engine/account/status', [
                'status' => WhatsappAccount::STATUS_CONNECTED,
            ])
            ->assertOk()
            ->assertJsonPath('data.whatsapp_account_id', $account->id)
            ->assertJsonPath('data.status', WhatsappAccount::STATUS_CONNECTED);
    }

    protected function createClient(bool $isActive = true): Client
    {
        return Client::query()->create([
            'name' => 'Central Status Client ' . str()->random(6),
            'slug' => 'central-status-client-' . str()->random(6),
            'contact_name' => 'Central Status Contact',
            'phone' => '967700000000',
            'email' => 'central-status-' . str()->random(6) . '@example.test',
            'is_active' => $isActive,
            'notes' => null,
        ]);
    }

    protected function createWhatsappAccount(?Client $client = null): WhatsappAccount
    {
        $client ??= $this->createClient();

        return WhatsappAccount::query()->create([
            'client_id' => $client->id,
            'name' => 'Central Status Account ' . str()->random(6),
            'phone_number' => '967722222222',
            'session_name' => 'wa_' . strtolower(str()->random(24)),
            'status' => WhatsappAccount::STATUS_DISCONNECTED,
            'last_seen_at' => null,
            'qr_expires_at' => null,
            'is_active' => true,
            'notes' => null,
        ]);
    }

    /**
     * @return array{0:string,1:ApiCredential,2:Client,3:WhatsappAccount}
     */
    protected function createCredential(): array
    {
        $client = $this->createClient();
        $whatsappAccount = $this->createWhatsappAccount($client);
        $plainToken = ApiCredential::generatePlainToken();

        $credential = ApiCredential::query()->create([
            'client_id' => $client->id,
            'whatsapp_account_id' => $whatsappAccount->id,
            'name' => 'Central Status Credential',
            'token_hash' => ApiCredential::hashToken($plainToken),
            'abilities' => ['messages:send', 'messages:read'],
            'last_used_at' => null,
            'expires_at' => Carbon::today()->addDays(7)->toDateString(),
            'is_active' => true,
        ]);

        return [$plainToken, $credential->fresh(), $client->fresh(), $whatsappAccount->fresh()];
    }

    protected function assertNoOperationalRecordsWereCreated(): void
    {
        $this->assertDatabaseCount(Message::class, 0);
        $this->assertDatabaseCount(MessageAttempt::class, 0);
        $this->assertDatabaseCount(WhatsappPairingToken::class, 0);
    }
}