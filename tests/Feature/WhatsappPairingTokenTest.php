<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Message;
use App\Models\MessageAttempt;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappPairingToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhatsappPairingTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_plain_token_uses_expected_prefix_and_is_random(): void
    {
        $firstToken = WhatsappPairingToken::generatePlainToken();
        $secondToken = WhatsappPairingToken::generatePlainToken();

        $this->assertStringStartsWith(WhatsappPairingToken::TOKEN_PREFIX, $firstToken);
        $this->assertStringStartsWith(WhatsappPairingToken::TOKEN_PREFIX, $secondToken);
        $this->assertNotSame($firstToken, $secondToken);
    }

    public function test_hash_token_is_stable_and_find_by_plain_token_returns_the_correct_record(): void
    {
        $account = $this->createWhatsappAccount();
        $plainToken = WhatsappPairingToken::generatePlainToken();

        $pairingToken = WhatsappPairingToken::query()->create([
            'whatsapp_account_id' => $account->id,
            'token_hash' => WhatsappPairingToken::hashToken($plainToken),
            'expires_at' => now()->addMinutes(30),
            'metadata' => ['source' => 'test'],
        ]);

        $this->assertNotSame($plainToken, $pairingToken->token_hash);
        $this->assertSame(
            WhatsappPairingToken::hashToken($plainToken),
            WhatsappPairingToken::hashToken($plainToken)
        );

        $resolvedToken = WhatsappPairingToken::findByPlainToken($plainToken);

        $this->assertNotNull($resolvedToken);
        $this->assertTrue($resolvedToken->is($pairingToken));
    }

    public function test_pairing_token_is_usable_for_active_account_and_active_client(): void
    {
        $account = $this->createWhatsappAccount();

        $pairingToken = WhatsappPairingToken::query()->create([
            'whatsapp_account_id' => $account->id,
            'token_hash' => WhatsappPairingToken::hashToken(WhatsappPairingToken::generatePlainToken()),
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->assertTrue($pairingToken->fresh()->isUsable());
    }

    public function test_pairing_token_is_not_usable_when_expired_used_or_revoked(): void
    {
        $account = $this->createWhatsappAccount();

        $expiredToken = WhatsappPairingToken::query()->create([
            'whatsapp_account_id' => $account->id,
            'token_hash' => WhatsappPairingToken::hashToken(WhatsappPairingToken::generatePlainToken()),
            'expires_at' => now()->subMinute(),
        ]);

        $usedToken = WhatsappPairingToken::query()->create([
            'whatsapp_account_id' => $account->id,
            'token_hash' => WhatsappPairingToken::hashToken(WhatsappPairingToken::generatePlainToken()),
            'expires_at' => now()->addMinutes(30),
            'used_at' => now(),
        ]);

        $revokedToken = WhatsappPairingToken::query()->create([
            'whatsapp_account_id' => $account->id,
            'token_hash' => WhatsappPairingToken::hashToken(WhatsappPairingToken::generatePlainToken()),
            'expires_at' => now()->addMinutes(30),
            'revoked_at' => now(),
        ]);

        $this->assertFalse($expiredToken->fresh()->isUsable());
        $this->assertFalse($usedToken->fresh()->isUsable());
        $this->assertFalse($revokedToken->fresh()->isUsable());
    }

    public function test_pairing_token_is_not_usable_when_account_or_client_is_inactive(): void
    {
        $inactiveAccount = $this->createWhatsappAccount(isActive: false);
        $inactiveClientAccount = $this->createWhatsappAccount(
            client: $this->createClient(isActive: false)
        );

        $inactiveAccountToken = WhatsappPairingToken::query()->create([
            'whatsapp_account_id' => $inactiveAccount->id,
            'token_hash' => WhatsappPairingToken::hashToken(WhatsappPairingToken::generatePlainToken()),
            'expires_at' => now()->addMinutes(30),
        ]);

        $inactiveClientToken = WhatsappPairingToken::query()->create([
            'whatsapp_account_id' => $inactiveClientAccount->id,
            'token_hash' => WhatsappPairingToken::hashToken(WhatsappPairingToken::generatePlainToken()),
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->assertFalse($inactiveAccountToken->fresh()->isUsable());
        $this->assertFalse($inactiveClientToken->fresh()->isUsable());
    }

    public function test_mark_as_used_and_revoke_update_the_expected_columns(): void
    {
        $account = $this->createWhatsappAccount();

        $pairingToken = WhatsappPairingToken::query()->create([
            'whatsapp_account_id' => $account->id,
            'token_hash' => WhatsappPairingToken::hashToken(WhatsappPairingToken::generatePlainToken()),
            'expires_at' => now()->addMinutes(30),
        ]);

        $usedAt = now()->addMinute();
        $revokedAt = now()->addMinutes(2);

        $pairingToken->markAsUsed($usedAt);
        $this->assertSame($usedAt->format('Y-m-d H:i:s'), $pairingToken->fresh()->used_at?->format('Y-m-d H:i:s'));
        $this->assertFalse($pairingToken->fresh()->isUsable());

        $pairingToken->revoke($revokedAt);
        $this->assertSame($revokedAt->format('Y-m-d H:i:s'), $pairingToken->fresh()->revoked_at?->format('Y-m-d H:i:s'));
        $this->assertFalse($pairingToken->fresh()->isUsable());
    }

    public function test_session_name_is_generated_automatically_and_does_not_change_on_update(): void
    {
        $firstAccount = $this->createWhatsappAccount(sessionName: null);
        $secondAccount = $this->createWhatsappAccount(sessionName: null, phoneNumber: '967722222222');

        $this->assertMatchesRegularExpression('/^wa_[a-f0-9]+$/', $firstAccount->session_name);
        $this->assertMatchesRegularExpression('/^wa_[a-f0-9]+$/', $secondAccount->session_name);
        $this->assertNotSame($firstAccount->session_name, $secondAccount->session_name);

        $originalSessionName = $firstAccount->session_name;

        $firstAccount->update([
            'name' => 'Updated Account Name',
        ]);

        $this->assertSame($originalSessionName, $firstAccount->fresh()->session_name);
    }

    public function test_existing_session_name_is_preserved_when_provided(): void
    {
        $account = $this->createWhatsappAccount(sessionName: 'existing_session_name');

        $this->assertSame('existing_session_name', $account->session_name);
    }

    public function test_issuing_a_new_pairing_token_revokes_previous_usable_tokens_and_stores_created_by(): void
    {
        Carbon::setTestNow('2026-07-23 15:00:00');

        $account = $this->createWhatsappAccount();
        $admin = User::factory()->create();

        $oldToken = WhatsappPairingToken::query()->create([
            'whatsapp_account_id' => $account->id,
            'token_hash' => WhatsappPairingToken::hashToken(WhatsappPairingToken::generatePlainToken()),
            'expires_at' => now()->addMinutes(20),
        ]);

        [$plainToken, $newToken] = WhatsappPairingToken::issueForWhatsappAccount(
            $account,
            now()->addMinutes(30),
            $admin->id,
            ['source' => 'feature-test']
        );

        $this->assertStringStartsWith(WhatsappPairingToken::TOKEN_PREFIX, $plainToken);
        $this->assertSame($admin->id, $newToken->created_by);
        $this->assertSame(['source' => 'feature-test'], $newToken->metadata);
        $this->assertNotNull($oldToken->fresh()->revoked_at);
        $this->assertTrue($newToken->fresh()->isUsable());
        $this->assertSame(
            1,
            WhatsappPairingToken::query()->where('whatsapp_account_id', $account->id)->usable()->count()
        );
        $this->assertDatabaseCount(Message::class, 0);
        $this->assertDatabaseCount(MessageAttempt::class, 0);
        $this->assertSame(WhatsappAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_revoke_usable_for_whatsapp_account_revokes_only_current_usable_tokens(): void
    {
        Carbon::setTestNow('2026-07-23 16:00:00');

        $account = $this->createWhatsappAccount();
        $otherAccount = $this->createWhatsappAccount(phoneNumber: '967733333333');

        $usableToken = WhatsappPairingToken::query()->create([
            'whatsapp_account_id' => $account->id,
            'token_hash' => WhatsappPairingToken::hashToken(WhatsappPairingToken::generatePlainToken()),
            'expires_at' => now()->addMinutes(30),
        ]);

        $usedToken = WhatsappPairingToken::query()->create([
            'whatsapp_account_id' => $account->id,
            'token_hash' => WhatsappPairingToken::hashToken(WhatsappPairingToken::generatePlainToken()),
            'expires_at' => now()->addMinutes(30),
            'used_at' => now()->subMinute(),
        ]);

        $otherAccountToken = WhatsappPairingToken::query()->create([
            'whatsapp_account_id' => $otherAccount->id,
            'token_hash' => WhatsappPairingToken::hashToken(WhatsappPairingToken::generatePlainToken()),
            'expires_at' => now()->addMinutes(30),
        ]);

        $revokedCount = WhatsappPairingToken::revokeUsableForWhatsappAccount($account);

        $this->assertSame(1, $revokedCount);
        $this->assertNotNull($usableToken->fresh()->revoked_at);
        $this->assertNull($usedToken->fresh()->revoked_at);
        $this->assertNull($otherAccountToken->fresh()->revoked_at);
    }

    protected function createClient(bool $isActive = true): Client
    {
        return Client::query()->create([
            'name' => 'Pairing Client ' . str()->random(6),
            'slug' => 'pairing-client-' . str()->random(6),
            'contact_name' => 'Client Contact',
            'phone' => '967700000000',
            'email' => 'pairing-' . str()->random(6) . '@example.test',
            'is_active' => $isActive,
            'notes' => null,
        ]);
    }

    protected function createWhatsappAccount(
        ?Client $client = null,
        bool $isActive = true,
        ?string $sessionName = null,
        string $phoneNumber = '967711111111',
    ): WhatsappAccount {
        $client ??= $this->createClient();

        return WhatsappAccount::query()->create([
            'client_id' => $client->id,
            'name' => 'Pairing Account ' . str()->random(6),
            'phone_number' => $phoneNumber,
            'session_name' => $sessionName,
            'status' => WhatsappAccount::STATUS_CONNECTED,
            'last_seen_at' => null,
            'qr_expires_at' => null,
            'is_active' => $isActive,
            'notes' => null,
        ]);
    }
}