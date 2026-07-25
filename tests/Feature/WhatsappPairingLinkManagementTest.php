<?php

namespace Tests\Feature;

use App\Filament\Resources\WhatsappAccounts\WhatsappAccountResource;
use App\Models\ApiCredential;
use App\Models\Client;
use App\Models\Message;
use App\Models\MessageAttempt;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappPairingToken;
use App\Services\Whatsapp\WhatsappPairingLinkService;
use App\Services\Whatsapp\WhatsappPairingQrCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhatsappPairingLinkManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_link_returns_full_public_route_and_stores_only_the_hash(): void
    {
        $account = $this->createWhatsappAccount();
        $admin = User::factory()->create();
        [, $credential] = $this->createCredentialFor($account);

        $result = app(WhatsappPairingLinkService::class)->issueLink($account, 15, $admin->id);

        $this->assertArrayHasKey('plain_token', $result);
        $this->assertArrayHasKey('pairing_url', $result);
        $this->assertStringStartsWith(WhatsappPairingToken::TOKEN_PREFIX, $result['plain_token']);
        $this->assertSame(route('whatsapp.pair.show', ['token' => $result['plain_token']]), $result['pairing_url']);
        $this->assertStringContainsString('/whatsapp/pair/', $result['pairing_url']);
        $this->assertStringContainsString($result['plain_token'], $result['pairing_url']);
        $this->assertStringNotContainsString($result['pairing_token']->token_hash, $result['pairing_url']);
        $this->assertNotSame($result['plain_token'], $result['pairing_token']->token_hash);
        $this->assertSame(['source' => 'filament'], $result['pairing_token']->metadata);
        $this->assertSame($admin->id, $result['pairing_token']->created_by);
        $this->assertNull($credential->fresh()->last_used_at);
        $this->assertOperationalRecordsUntouched();
    }

    public function test_issuing_a_new_link_revokes_only_previous_links_for_the_same_account(): void
    {
        $firstAccount = $this->createWhatsappAccount();
        $secondAccount = $this->createWhatsappAccount(phoneNumber: '967722222222');

        $firstResult = app(WhatsappPairingLinkService::class)->issueLink($firstAccount, 15);
        $secondResult = app(WhatsappPairingLinkService::class)->issueLink($secondAccount, 15);
        $replacementResult = app(WhatsappPairingLinkService::class)->issueLink($firstAccount, 20);

        $this->assertNotNull($firstResult['pairing_token']->fresh()->revoked_at);
        $this->assertNull($secondResult['pairing_token']->fresh()->revoked_at);
        $this->assertNull($replacementResult['pairing_token']->fresh()->revoked_at);
        $this->assertTrue($replacementResult['pairing_token']->fresh()->isUsable());
        $this->assertTrue($secondResult['pairing_token']->fresh()->isUsable());
        $this->assertSame(1, WhatsappPairingToken::query()->where('whatsapp_account_id', $firstAccount->id)->usable()->count());
        $this->assertSame(1, WhatsappPairingToken::query()->where('whatsapp_account_id', $secondAccount->id)->usable()->count());
    }

    public function test_revoke_link_clears_qr_cache_without_changing_status_or_desired_state(): void
    {
        $account = $this->createWhatsappAccount(status: WhatsappAccount::STATUS_CONNECTED);
        $account->requestSessionStart();
        [, $pairingToken] = WhatsappPairingToken::issueForWhatsappAccount($account, now()->addMinutes(15));
        [, $credential] = $this->createCredentialFor($account);
        app(WhatsappPairingQrCache::class)->put($account, 'RAW-QR-FILAMENT-REVOKE-TEST');

        $statusBefore = $account->fresh()->status;
        $desiredStateBefore = $account->fresh()->session_desired_state;

        $revokedCount = app(WhatsappPairingLinkService::class)->revokeLink($account->fresh());

        $this->assertSame(1, $revokedCount);
        $this->assertNotNull($pairingToken->fresh()->revoked_at);
        $this->assertNull(app(WhatsappPairingQrCache::class)->get($account));
        $this->assertSame($statusBefore, $account->fresh()->status);
        $this->assertSame($desiredStateBefore, $account->fresh()->session_desired_state);
        $this->assertNull($credential->fresh()->last_used_at);
        $this->assertOperationalRecordsUntouched();
    }

    public function test_generate_action_visibility_depends_on_account_and_client_activity(): void
    {
        $activeAccount = $this->createWhatsappAccount();
        $inactiveAccount = $this->createWhatsappAccount(isActive: false, phoneNumber: '967733333333');
        $inactiveClient = $this->createClient(isActive: false);
        $inactiveClientAccount = $this->createWhatsappAccount(client: $inactiveClient, phoneNumber: '967744444444');

        $this->assertTrue(WhatsappAccountResource::makeGeneratePairingTokenAction()->record($activeAccount)->isVisible());
        $this->assertFalse(WhatsappAccountResource::makeGeneratePairingTokenAction()->record($inactiveAccount)->isVisible());
        $this->assertFalse(WhatsappAccountResource::makeGeneratePairingTokenAction()->record($inactiveClientAccount)->isVisible());
    }

    public function test_revoke_action_visibility_depends_on_a_usable_token_only(): void
    {
        $account = $this->createWhatsappAccount();

        $this->assertFalse(WhatsappAccountResource::makeRevokePairingTokenAction()->record($account)->isVisible());

        [, $usableToken] = WhatsappPairingToken::issueForWhatsappAccount($account, now()->addMinutes(15));
        $this->assertTrue(WhatsappAccountResource::makeRevokePairingTokenAction()->record($account->fresh())->isVisible());

        $usableToken->revoke();
        $this->assertFalse(WhatsappAccountResource::makeRevokePairingTokenAction()->record($account->fresh())->isVisible());
    }

    public function test_display_labels_and_pairing_status_labels_are_translated_for_filament(): void
    {
        $account = $this->createWhatsappAccount();

        $this->assertSame('متصل', WhatsappAccountResource::statusLabelForDisplay(WhatsappAccount::STATUS_CONNECTED));
        $this->assertSame('بانتظار مسح رمز QR', WhatsappAccountResource::statusLabelForDisplay(WhatsappAccount::STATUS_QR_REQUIRED));
        $this->assertSame('قيد الإعداد', WhatsappAccountResource::statusLabelForDisplay('pending'));
        $this->assertSame('غير معروف', WhatsappAccountResource::statusLabelForDisplay('mystery'));
        $this->assertSame('مطلوب التشغيل', WhatsappAccountResource::desiredStateLabelForDisplay(WhatsappAccount::SESSION_DESIRED_RUNNING));
        $this->assertSame('متوقف إداريًا', WhatsappAccountResource::desiredStateLabelForDisplay(WhatsappAccount::SESSION_DESIRED_STOPPED));
        $this->assertSame('لا يوجد رابط', WhatsappAccountResource::pairingStatusLabelFromKey(WhatsappAccountResource::pairingStatusKey($account)));

        [, $usableToken] = WhatsappPairingToken::issueForWhatsappAccount($account, now()->addMinutes(15));
        $this->assertSame('صالح', WhatsappAccountResource::pairingStatusLabelFromKey(WhatsappAccountResource::pairingStatusKey($account->fresh('latestPairingToken'))));

        $usableToken->markAsUsed();
        $this->assertSame('مستخدم', WhatsappAccountResource::pairingStatusLabelFromKey(WhatsappAccountResource::pairingStatusKey($account->fresh('latestPairingToken'))));
    }

    public function test_new_accounts_keep_independent_session_names_and_links(): void
    {
        $firstAccount = $this->createWhatsappAccount(sessionName: null);
        $secondAccount = $this->createWhatsappAccount(sessionName: null, phoneNumber: '967755555555');

        $this->assertNotSame($firstAccount->session_name, $secondAccount->session_name);
        $this->assertMatchesRegularExpression('/^wa_[a-f0-9]+$/', $firstAccount->session_name);
        $this->assertMatchesRegularExpression('/^wa_[a-f0-9]+$/', $secondAccount->session_name);

        $firstLink = app(WhatsappPairingLinkService::class)->issueLink($firstAccount, 15);
        $secondLink = app(WhatsappPairingLinkService::class)->issueLink($secondAccount, 15);

        $this->assertNotSame($firstLink['pairing_url'], $secondLink['pairing_url']);
        $this->assertNull($firstLink['pairing_token']->fresh()->revoked_at);
        $this->assertNull($secondLink['pairing_token']->fresh()->revoked_at);
    }

    protected function createClient(bool $isActive = true): Client
    {
        return Client::query()->create([
            'name' => 'Filament Pair Client ' . str()->random(6),
            'slug' => 'filament-pair-client-' . str()->random(6),
            'contact_name' => 'Filament Pair Contact',
            'phone' => '967700000000',
            'email' => 'filament-pair-' . str()->random(6) . '@example.test',
            'is_active' => $isActive,
            'notes' => null,
        ]);
    }

    protected function createWhatsappAccount(
        ?Client $client = null,
        string $status = WhatsappAccount::STATUS_DISCONNECTED,
        bool $isActive = true,
        ?string $sessionName = null,
        string $phoneNumber = '967711111111',
    ): WhatsappAccount {
        $client ??= $this->createClient();

        return WhatsappAccount::query()->create([
            'client_id' => $client->id,
            'name' => 'Filament Pair Account ' . str()->random(6),
            'phone_number' => $phoneNumber,
            'session_name' => $sessionName,
            'status' => $status,
            'last_seen_at' => null,
            'qr_expires_at' => null,
            'is_active' => $isActive,
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
            'name' => 'Filament Pair Credential',
            'token_hash' => ApiCredential::hashToken($plainToken),
            'abilities' => ['messages:send', 'messages:read'],
            'last_used_at' => null,
            'expires_at' => Carbon::today()->addDays(7)->toDateString(),
            'is_active' => true,
        ]);

        return [$plainToken, $credential->fresh()];
    }

    protected function assertOperationalRecordsUntouched(): void
    {
        $this->assertDatabaseCount(Message::class, 0);
        $this->assertDatabaseCount(MessageAttempt::class, 0);
    }
}