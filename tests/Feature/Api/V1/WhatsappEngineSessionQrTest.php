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
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WhatsappEngineSessionQrTest extends TestCase
{
    use RefreshDatabase;

    protected string $internalToken = 'test-internal-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp_engine.internal_token', $this->internalToken);
    }

    public function test_qr_route_requires_internal_token(): void
    {
        $account = $this->createWhatsappAccount();

        $this->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/qr", [
            'qr' => 'RAW-QR-TEST-ACCOUNT-1',
        ])
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_qr_route_rejects_invalid_internal_token_and_plain_api_credential(): void
    {
        $account = $this->createWhatsappAccount();
        [$plainToken, $credential] = $this->createCredentialFor($account);

        $this->withToken('wrong-token')
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/qr", [
                'qr' => 'RAW-QR-TEST-ACCOUNT-1',
            ])
            ->assertUnauthorized();

        $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/qr", [
                'qr' => 'RAW-QR-TEST-ACCOUNT-1',
            ])
            ->assertUnauthorized();

        $this->assertNull($credential->fresh()->last_used_at);
    }

    public function test_qr_route_requires_qr_payload(): void
    {
        $account = $this->createWhatsappAccount();

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/qr", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['qr']);
    }

    public function test_qr_route_stores_qr_in_cache_only_and_hides_raw_qr_from_response(): void
    {
        $account = $this->createWhatsappAccount();
        $expiresAt = now()->addSeconds(75);

        $response = $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/qr", [
                'qr' => 'RAW-QR-TEST-ACCOUNT-1',
                'expires_at' => $expiresAt->toISOString(),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.whatsapp_account_id', $account->id)
            ->assertJsonPath('data.status', WhatsappAccount::STATUS_QR_REQUIRED)
            ->assertJsonMissingPath('data.qr');

        $account->refresh();

        $this->assertSame('RAW-QR-TEST-ACCOUNT-1', Cache::get('whatsapp:pairing-qr:' . $account->id));
        $this->assertSame(WhatsappAccount::STATUS_QR_REQUIRED, $account->status);
        $this->assertNotNull($account->qr_expires_at);
        $this->assertSame($expiresAt->format('Y-m-d H:i:s'), $account->qr_expires_at?->format('Y-m-d H:i:s'));
        $this->assertStringNotContainsString('RAW-QR-TEST-ACCOUNT-1', (string) $account->notes);
        $this->assertNoOperationalRecordsWereCreated();
    }

    public function test_qr_route_rejects_inactive_account_or_client(): void
    {
        $inactiveAccount = $this->createWhatsappAccount();
        $inactiveAccount->update(['is_active' => false]);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$inactiveAccount->id}/qr", [
                'qr' => 'RAW-QR-INACTIVE-ACCOUNT',
            ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'WhatsApp account is inactive.',
            ]);

        $inactiveClient = $this->createClient(false);
        $clientAccount = $this->createWhatsappAccount($inactiveClient);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$clientAccount->id}/qr", [
                'qr' => 'RAW-QR-INACTIVE-CLIENT',
            ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'WhatsApp account client is inactive.',
            ]);
    }

    public function test_qr_cache_is_isolated_per_account_and_cleared_on_state_updates(): void
    {
        $firstAccount = $this->createWhatsappAccount();
        $secondAccount = $this->createWhatsappAccount();

        $this->withToken($this->internalToken)->postJson("/api/v1/whatsapp/engine/sessions/{$firstAccount->id}/qr", [
            'qr' => 'RAW-QR-FIRST',
        ])->assertOk();

        $this->withToken($this->internalToken)->postJson("/api/v1/whatsapp/engine/sessions/{$secondAccount->id}/qr", [
            'qr' => 'RAW-QR-SECOND',
        ])->assertOk();

        $this->assertSame('RAW-QR-FIRST', Cache::get('whatsapp:pairing-qr:' . $firstAccount->id));
        $this->assertSame('RAW-QR-SECOND', Cache::get('whatsapp:pairing-qr:' . $secondAccount->id));

        $this->withToken($this->internalToken)->postJson("/api/v1/whatsapp/engine/sessions/{$firstAccount->id}/status", [
            'status' => WhatsappAccount::STATUS_AUTHENTICATED,
        ])->assertOk();

        $this->assertNull(Cache::get('whatsapp:pairing-qr:' . $firstAccount->id));
        $this->assertSame('RAW-QR-SECOND', Cache::get('whatsapp:pairing-qr:' . $secondAccount->id));

        $this->withToken($this->internalToken)->postJson("/api/v1/whatsapp/engine/sessions/{$secondAccount->id}/status", [
            'status' => WhatsappAccount::STATUS_CONNECTED,
        ])->assertOk();

        $this->assertNull(Cache::get('whatsapp:pairing-qr:' . $secondAccount->id));
    }

    public function test_disconnected_and_error_statuses_clear_cached_qr(): void
    {
        $account = $this->createWhatsappAccount();

        $this->withToken($this->internalToken)->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/qr", [
            'qr' => 'RAW-QR-DISCONNECTED',
        ])->assertOk();

        $this->withToken($this->internalToken)->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/status", [
            'status' => WhatsappAccount::STATUS_DISCONNECTED,
            'reason' => 'network drop',
        ])->assertOk();

        $this->assertNull(Cache::get('whatsapp:pairing-qr:' . $account->id));

        $this->withToken($this->internalToken)->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/qr", [
            'qr' => 'RAW-QR-ERROR',
        ])->assertOk();

        $this->withToken($this->internalToken)->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/status", [
            'status' => WhatsappAccount::STATUS_ERROR,
            'error_message' => 'socket failed',
        ])->assertOk();

        $this->assertNull(Cache::get('whatsapp:pairing-qr:' . $account->id));
    }

    protected function createClient(bool $isActive = true): Client
    {
        return Client::query()->create([
            'name' => 'Session QR Client ' . str()->random(6),
            'slug' => 'session-qr-client-' . str()->random(6),
            'contact_name' => 'Session QR Contact',
            'phone' => '967700000000',
            'email' => 'session-qr-' . str()->random(6) . '@example.test',
            'is_active' => $isActive,
            'notes' => null,
        ]);
    }

    protected function createWhatsappAccount(?Client $client = null): WhatsappAccount
    {
        $client ??= $this->createClient();

        return WhatsappAccount::query()->create([
            'client_id' => $client->id,
            'name' => 'Session QR Account ' . str()->random(6),
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
     * @return array{0:string,1:ApiCredential}
     */
    protected function createCredentialFor(WhatsappAccount $whatsappAccount): array
    {
        $plainToken = ApiCredential::generatePlainToken();

        $credential = ApiCredential::query()->create([
            'client_id' => $whatsappAccount->client_id,
            'whatsapp_account_id' => $whatsappAccount->id,
            'name' => 'Session QR Credential',
            'token_hash' => ApiCredential::hashToken($plainToken),
            'abilities' => ['messages:send', 'messages:read'],
            'last_used_at' => null,
            'expires_at' => Carbon::today()->addDays(7)->toDateString(),
            'is_active' => true,
        ]);

        return [$plainToken, $credential->fresh()];
    }

    protected function assertNoOperationalRecordsWereCreated(): void
    {
        $this->assertDatabaseCount(Message::class, 0);
        $this->assertDatabaseCount(MessageAttempt::class, 0);
        $this->assertDatabaseCount(WhatsappPairingToken::class, 0);
    }
}