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

class WhatsappEngineSessionControlTest extends TestCase
{
    use RefreshDatabase;

    protected string $internalToken = 'test-internal-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp_engine.internal_token', $this->internalToken);
    }

    public function test_all_session_routes_require_internal_token(): void
    {
        $account = $this->createWhatsappAccount();

        $this->getJson('/api/v1/whatsapp/engine/sessions')->assertUnauthorized();
        $this->getJson("/api/v1/whatsapp/engine/sessions/{$account->id}")->assertUnauthorized();
        $this->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/start")->assertUnauthorized();
        $this->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/stop")->assertUnauthorized();
    }

    public function test_wrong_internal_token_is_rejected(): void
    {
        $account = $this->createWhatsappAccount();

        $this->withToken('wrong-token')
            ->getJson('/api/v1/whatsapp/engine/sessions')
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);

        $this->withToken('wrong-token')
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/start")
            ->assertUnauthorized();
    }

    public function test_api_credential_token_cannot_access_session_routes(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential();

        Message::query()->create([
            'client_id' => $client->id,
            'whatsapp_account_id' => $account->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700400400',
            'sender' => $account->phone_number,
            'message_type' => Message::TYPE_TEXT,
            'body' => 'Isolation message',
            'payload' => ['source' => 'session-control-test'],
            'status' => Message::STATUS_PENDING,
        ]);

        $this->withToken($plainToken)
            ->getJson('/api/v1/whatsapp/engine/sessions')
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_list_returns_only_allowed_fields(): void
    {
        $account = $this->createWhatsappAccount();
        WhatsappPairingToken::query()->create([
            'whatsapp_account_id' => $account->id,
            'token_hash' => WhatsappPairingToken::hashToken(WhatsappPairingToken::generatePlainToken()),
            'expires_at' => now()->addMinutes(30),
        ]);

        $response = $this->withToken($this->internalToken)
            ->getJson('/api/v1/whatsapp/engine/sessions');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $account->id)
            ->assertJsonPath('data.0.client_id', $account->client_id)
            ->assertJsonPath('data.0.session_name', $account->session_name)
            ->assertJsonPath('data.0.session_desired_state', WhatsappAccount::SESSION_DESIRED_STOPPED)
            ->assertJsonPath('data.0.status', $account->status)
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.token_hash')
            ->assertJsonMissingPath('data.0.pairing_tokens')
            ->assertJsonMissingPath('data.0.api_credentials');
    }

    public function test_list_filter_running_works(): void
    {
        $runningAccount = $this->createWhatsappAccount();
        $runningAccount->requestSessionStart();

        $stoppedAccount = $this->createWhatsappAccount(phoneNumber: '967711111112');

        $response = $this->withToken($this->internalToken)
            ->getJson('/api/v1/whatsapp/engine/sessions?desired_state=running');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $runningAccount->id);

        $this->assertNotSame($stoppedAccount->id, $response->json('data.0.id'));
    }

    public function test_list_filter_stopped_works(): void
    {
        $runningAccount = $this->createWhatsappAccount();
        $runningAccount->requestSessionStart();

        $stoppedAccount = $this->createWhatsappAccount(phoneNumber: '967711111113');

        $response = $this->withToken($this->internalToken)
            ->getJson('/api/v1/whatsapp/engine/sessions?desired_state=stopped');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $stoppedAccount->id);

        $this->assertNotSame($runningAccount->id, $response->json('data.0.id'));
    }

    public function test_list_filter_rejects_invalid_value(): void
    {
        $this->withToken($this->internalToken)
            ->getJson('/api/v1/whatsapp/engine/sessions?desired_state=invalid')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['desired_state']);
    }

    public function test_show_returns_the_requested_session(): void
    {
        $account = $this->createWhatsappAccount();

        $this->withToken($this->internalToken)
            ->getJson("/api/v1/whatsapp/engine/sessions/{$account->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $account->id)
            ->assertJsonPath('data.session_desired_state', WhatsappAccount::SESSION_DESIRED_STOPPED)
            ->assertJsonMissingPath('data.notes');
    }

    public function test_show_returns_404_for_missing_session(): void
    {
        $this->withToken($this->internalToken)
            ->getJson('/api/v1/whatsapp/engine/sessions/999999')
            ->assertNotFound();
    }

    public function test_start_requests_running_state_for_active_account_and_active_client(): void
    {
        $account = $this->createWhatsappAccount(status: WhatsappAccount::STATUS_DISCONNECTED);
        $originalStatus = $account->status;

        $response = $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/start");

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Session start requested.',
                'data' => [
                    'whatsapp_account_id' => $account->id,
                    'session_desired_state' => WhatsappAccount::SESSION_DESIRED_RUNNING,
                    'status' => $originalStatus,
                ],
            ]);

        $account->refresh();

        $this->assertSame(WhatsappAccount::SESSION_DESIRED_RUNNING, $account->session_desired_state);
        $this->assertNotNull($account->start_requested_at);
        $this->assertNull($account->stop_requested_at);
        $this->assertSame($originalStatus, $account->status);
        $this->assertNoOperationalRecordsWereCreated();
    }

    public function test_start_is_idempotent_and_does_not_refresh_timestamp_unnecessarily(): void
    {
        $initialTime = Carbon::parse('2026-07-23 18:00:00');
        $nextTime = Carbon::parse('2026-07-23 18:10:00');

        Carbon::setTestNow($initialTime);

        $account = $this->createWhatsappAccount();

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/start")
            ->assertOk();

        $firstStartRequestedAt = $account->fresh()->start_requested_at;

        Carbon::setTestNow($nextTime);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/start")
            ->assertOk();

        $this->assertTrue($account->fresh()->start_requested_at?->equalTo($firstStartRequestedAt));
        $this->assertNull($account->fresh()->stop_requested_at);
    }

    public function test_start_rejects_inactive_account(): void
    {
        $account = $this->createWhatsappAccount(isActive: false);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/start")
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'WhatsApp account is inactive.',
            ]);

        $this->assertSame(WhatsappAccount::SESSION_DESIRED_STOPPED, $account->fresh()->session_desired_state);
    }

    public function test_start_rejects_inactive_client(): void
    {
        $account = $this->createWhatsappAccount(client: $this->createClient(isActive: false));

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/start")
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'WhatsApp account client is inactive.',
            ]);

        $this->assertSame(WhatsappAccount::SESSION_DESIRED_STOPPED, $account->fresh()->session_desired_state);
    }

    public function test_start_rejects_account_without_client(): void
    {
        $account = $this->createWhatsappAccount();
        $account->update(['client_id' => null]);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/start")
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'WhatsApp account client is missing.',
            ]);

        $this->assertSame(WhatsappAccount::SESSION_DESIRED_STOPPED, $account->fresh()->session_desired_state);
    }

    public function test_stop_requests_stopped_state_without_changing_actual_status(): void
    {
        $account = $this->createWhatsappAccount(status: WhatsappAccount::STATUS_CONNECTED);
        $account->requestSessionStart();
        $originalStatus = $account->fresh()->status;

        $response = $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/stop");

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Session stop requested.',
                'data' => [
                    'whatsapp_account_id' => $account->id,
                    'session_desired_state' => WhatsappAccount::SESSION_DESIRED_STOPPED,
                    'status' => $originalStatus,
                ],
            ]);

        $account->refresh();

        $this->assertSame(WhatsappAccount::SESSION_DESIRED_STOPPED, $account->session_desired_state);
        $this->assertNotNull($account->stop_requested_at);
        $this->assertSame($originalStatus, $account->status);
        $this->assertNoOperationalRecordsWereCreated();
    }

    public function test_stop_allows_inactive_account_or_inactive_client(): void
    {
        $inactiveClient = $this->createClient(isActive: false);
        $account = $this->createWhatsappAccount(client: $inactiveClient, isActive: false);
        $account->requestSessionStart();

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/stop")
            ->assertOk();

        $this->assertSame(WhatsappAccount::SESSION_DESIRED_STOPPED, $account->fresh()->session_desired_state);
        $this->assertNotNull($account->fresh()->stop_requested_at);
    }

    public function test_stop_is_idempotent(): void
    {
        $initialTime = Carbon::parse('2026-07-23 19:00:00');
        $nextTime = Carbon::parse('2026-07-23 19:10:00');

        Carbon::setTestNow($initialTime);

        $account = $this->createWhatsappAccount();
        $account->requestSessionStart();

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/stop")
            ->assertOk();

        $firstStopRequestedAt = $account->fresh()->stop_requested_at;

        Carbon::setTestNow($nextTime);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/stop")
            ->assertOk();

        $this->assertTrue($account->fresh()->stop_requested_at?->equalTo($firstStopRequestedAt));
    }

    public function test_existing_engine_message_routes_still_work(): void
    {
        [$plainToken, , $client, $account] = $this->createCredential();

        $message = Message::query()->create([
            'client_id' => $client->id,
            'whatsapp_account_id' => $account->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700500500',
            'sender' => $account->phone_number,
            'message_type' => Message::TYPE_TEXT,
            'body' => 'Legacy route compatibility message',
            'payload' => ['source' => 'legacy-engine-route-test'],
            'status' => Message::STATUS_PENDING,
        ]);

        $this->withToken($plainToken)
            ->getJson('/api/v1/whatsapp/engine/messages/pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->id);
    }

    public function test_health_endpoint_still_works(): void
    {
        $this->withToken($this->internalToken)
            ->getJson('/api/v1/whatsapp/engine/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok');
    }

    protected function createClient(bool $isActive = true): Client
    {
        return Client::query()->create([
            'name' => 'Session Control Client ' . str()->random(6),
            'slug' => 'session-control-client-' . str()->random(6),
            'contact_name' => 'Session Control Contact',
            'phone' => '967700000000',
            'email' => 'session-control-' . str()->random(6) . '@example.test',
            'is_active' => $isActive,
            'notes' => null,
        ]);
    }

    protected function createWhatsappAccount(
        ?Client $client = null,
        bool $isActive = true,
        ?string $sessionName = null,
        string $phoneNumber = '967711111111',
        string $status = WhatsappAccount::STATUS_DISCONNECTED,
    ): WhatsappAccount {
        $client ??= $this->createClient();

        return WhatsappAccount::query()->create([
            'client_id' => $client->id,
            'name' => 'Session Control Account ' . str()->random(6),
            'phone_number' => $phoneNumber,
            'session_name' => $sessionName,
            'status' => $status,
            'last_seen_at' => null,
            'qr_expires_at' => null,
            'is_active' => $isActive,
            'notes' => 'Private note should not leak.',
        ]);
    }

    /**
     * @return array{0:string,1:ApiCredential,2:Client,3:WhatsappAccount}
     */
    protected function createCredential(array $overrides = []): array
    {
        $client = Client::query()->create([
            'name' => $overrides['client_name'] ?? 'Session Control Engine Client',
            'slug' => $overrides['client_slug'] ?? 'session-control-engine-client-' . str()->random(6),
            'contact_name' => 'Session Control Engine Contact',
            'phone' => '967700100100',
            'email' => 'session-control-engine-' . str()->random(6) . '@example.test',
            'is_active' => $overrides['client_active'] ?? true,
            'notes' => null,
        ]);

        $whatsappAccount = WhatsappAccount::query()->create([
            'client_id' => $overrides['account_client_id'] ?? $client->id,
            'name' => $overrides['account_name'] ?? 'Session Control Engine Account',
            'phone_number' => $overrides['phone_number'] ?? '967722222222',
            'session_name' => $overrides['session_name'] ?? 'session_control_engine_' . str()->random(8),
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
            'name' => $overrides['credential_name'] ?? 'Session Control Engine Credential',
            'token_hash' => ApiCredential::hashToken($plainToken),
            'abilities' => $overrides['abilities'] ?? ['messages:read', 'messages:send'],
            'last_used_at' => null,
            'expires_at' => $overrides['expires_at'] ?? Carbon::today()->addDays(7)->toDateString(),
            'is_active' => $overrides['is_active'] ?? true,
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