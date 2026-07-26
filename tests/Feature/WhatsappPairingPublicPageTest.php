<?php

namespace Tests\Feature;

use App\Livewire\Whatsapp\PairAccount;
use App\Models\ApiCredential;
use App\Models\Client;
use App\Models\Message;
use App\Models\MessageAttempt;
use App\Models\WhatsappAccount;
use App\Models\WhatsappPairingToken;
use App\Services\Whatsapp\WhatsappPairingQrCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class WhatsappPairingPublicPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pair_route_is_throttled_and_requests_start_for_the_correct_account(): void
    {
        $firstAccount = $this->createWhatsappAccount(status: WhatsappAccount::STATUS_DISCONNECTED);
        $secondAccount = $this->createWhatsappAccount(status: WhatsappAccount::STATUS_DISCONNECTED);
        [$plainToken] = WhatsappPairingToken::issueForWhatsappAccount($firstAccount, now()->addMinutes(30));
        [, $credential] = $this->createCredentialFor($firstAccount);

        $response = $this->get(route('whatsapp.pair.show', ['token' => $plainToken]));

        $response
            ->assertOk()
            ->assertSee('ربط حساب واتساب')
            ->assertSee('جارٍ تجهيز رمز الربط...');

        $route = app('router')->getRoutes()->getByName('whatsapp.pair.show');

        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
        $this->assertSame(WhatsappAccount::SESSION_DESIRED_RUNNING, $firstAccount->fresh()->session_desired_state);
        $this->assertSame(WhatsappAccount::SESSION_DESIRED_STOPPED, $secondAccount->fresh()->session_desired_state);
        $this->assertNull($credential->fresh()->last_used_at);
        $this->assertOperationalRecordsAreUntouched();
    }

    public function test_pair_page_hides_the_global_header_navigation_without_affecting_the_home_page(): void
    {
        $account = $this->createWhatsappAccount(status: WhatsappAccount::STATUS_DISCONNECTED);
        [$plainToken] = WhatsappPairingToken::issueForWhatsappAccount($account, now()->addMinutes(30));

        $pairResponse = $this->get(route('whatsapp.pair.show', ['token' => $plainToken]));

        $pairResponse
            ->assertOk()
            ->assertSee('ربط حساب واتساب')
            ->assertDontSee('لوحة الإدارة')
            ->assertDontSee('الرئيسية')
            ->assertDontSee('الخدمة الحالية')
            ->assertDontSee('المميزات');

        $this->get('/')
            ->assertOk()
            ->assertSee('لوحة الإدارة');
    }

    public function test_invalid_revoked_expired_and_used_tokens_render_a_generic_invalid_page(): void
    {
        $revokedAccount = $this->createWhatsappAccount();
        [$revokedPlainToken, $revokedToken] = WhatsappPairingToken::issueForWhatsappAccount($revokedAccount, now()->addMinutes(30));
        $revokedToken->revoke();

        $expiredAccount = $this->createWhatsappAccount();
        [$expiredPlainToken] = WhatsappPairingToken::issueForWhatsappAccount($expiredAccount, now()->subMinute());

        $usedAccount = $this->createWhatsappAccount();
        [$usedPlainToken, $usedToken] = WhatsappPairingToken::issueForWhatsappAccount($usedAccount, now()->addMinutes(30));
        $usedToken->markAsUsed();

        $dynamicAccount = $this->createWhatsappAccount();
        [$dynamicPlainToken, $dynamicToken] = WhatsappPairingToken::issueForWhatsappAccount($dynamicAccount, now()->addMinutes(30));

        $this->get(route('whatsapp.pair.show', ['token' => 'yspair_invalid_token']))
            ->assertOk()
            ->assertSee('رابط الربط غير صالح');

        $this->get(route('whatsapp.pair.show', ['token' => $revokedPlainToken]))
            ->assertOk()
            ->assertSee('رابط الربط غير صالح');

        $this->get(route('whatsapp.pair.show', ['token' => $expiredPlainToken]))
            ->assertOk()
            ->assertSee('رابط الربط غير صالح');

        $this->get(route('whatsapp.pair.show', ['token' => $usedPlainToken]))
            ->assertOk()
            ->assertSee('رابط الربط غير صالح');

        $component = Livewire::test(PairAccount::class, ['token' => $dynamicPlainToken]);
        $dynamicToken->update(['revoked_at' => now()]);
        $component->call('refreshSnapshot')->assertSet('state', 'revoked')->assertSet('shouldPoll', false);

        $dynamicToken->update(['revoked_at' => null, 'expires_at' => now()->subSecond()]);
        $component = Livewire::test(PairAccount::class, ['token' => $dynamicPlainToken]);
        $component->call('refreshSnapshot')->assertSet('state', 'expired')->assertSet('shouldPoll', false);
    }

    public function test_qr_required_state_renders_an_embedded_svg_image_with_bounded_responsive_size(): void
    {
        $account = $this->createWhatsappAccount(status: WhatsappAccount::STATUS_QR_REQUIRED, notes: 'internal-only-note');
        [$plainToken, $pairingToken] = WhatsappPairingToken::issueForWhatsappAccount($account, now()->addMinutes(30));

        app(WhatsappPairingQrCache::class)->put($account, 'RAW-QR-PUBLIC-PAGE-TEST');

        $response = $this->get(route('whatsapp.pair.show', ['token' => $plainToken]));

        $response
            ->assertOk()
            ->assertSee('<img', false)
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertSee('pairing-qr-wrapper', false)
            ->assertSee('width: min(88vw, 360px);', false)
            ->assertSee('max-width: 360px;', false)
            ->assertSee('width: 100% !important;', false)
            ->assertDontSee('RAW-QR-PUBLIC-PAGE-TEST', false)
            ->assertDontSee($pairingToken->token_hash, false)
            ->assertDontSee($account->session_name, false)
            ->assertDontSee('accountId', false)
            ->assertDontSee('internal-only-note', false);

        Livewire::test(PairAccount::class, ['token' => $plainToken])
            ->assertSet('state', WhatsappAccount::STATUS_QR_REQUIRED)
            ->assertSet('shouldPoll', true)
            ->assertSee('pairing-qr-wrapper', false)
            ->assertSee('بعدها امسح رمز QR الظاهر.');
    }

    public function test_authenticated_state_shows_waiting_message_without_rendering_qr(): void
    {
        $account = $this->createWhatsappAccount(status: WhatsappAccount::STATUS_AUTHENTICATED);
        [$plainToken] = WhatsappPairingToken::issueForWhatsappAccount($account, now()->addMinutes(30));
        app(WhatsappPairingQrCache::class)->put($account, 'RAW-QR-AUTHENTICATED-TEST');

        Livewire::test(PairAccount::class, ['token' => $plainToken])
            ->assertSet('state', WhatsappAccount::STATUS_AUTHENTICATED)
            ->assertSet('qrSvg', null)
            ->assertSee('تم مسح الرمز، جارٍ إكمال الاتصال...');
    }

    public function test_connected_state_consumes_the_token_and_clears_cached_qr(): void
    {
        $account = $this->createWhatsappAccount(status: WhatsappAccount::STATUS_CONNECTED);
        [$plainToken, $pairingToken] = WhatsappPairingToken::issueForWhatsappAccount($account, now()->addMinutes(30));
        app(WhatsappPairingQrCache::class)->put($account, 'RAW-QR-CONNECTED-TEST');

        Livewire::test(PairAccount::class, ['token' => $plainToken])
            ->assertSet('state', 'connected')
            ->assertSet('completed', true)
            ->assertSet('shouldPoll', false)
            ->assertSee('تم ربط حساب واتساب بنجاح.');

        $this->assertNotNull($pairingToken->fresh()->used_at);
        $this->assertNull(app(WhatsappPairingQrCache::class)->get($account));

        $this->get(route('whatsapp.pair.show', ['token' => $plainToken]))
            ->assertOk()
            ->assertSee('رابط الربط غير صالح');
    }

    public function test_inactive_account_or_client_are_rejected_as_invalid_links(): void
    {
        $inactiveAccount = $this->createWhatsappAccount(isActive: false);
        [$inactiveAccountToken] = WhatsappPairingToken::issueForWhatsappAccount($inactiveAccount, now()->addMinutes(30));

        $inactiveClient = $this->createClient(false);
        $inactiveClientAccount = $this->createWhatsappAccount(client: $inactiveClient);
        [$inactiveClientToken] = WhatsappPairingToken::issueForWhatsappAccount($inactiveClientAccount, now()->addMinutes(30));

        $this->get(route('whatsapp.pair.show', ['token' => $inactiveAccountToken]))
            ->assertOk()
            ->assertSee('رابط الربط غير صالح');

        $this->get(route('whatsapp.pair.show', ['token' => $inactiveClientToken]))
            ->assertOk()
            ->assertSee('رابط الربط غير صالح');
    }

    public function test_refresh_snapshot_revalidates_the_token_after_public_property_changes(): void
    {
        $firstAccount = $this->createWhatsappAccount(status: WhatsappAccount::STATUS_QR_REQUIRED);
        [$firstPlainToken] = WhatsappPairingToken::issueForWhatsappAccount($firstAccount, now()->addMinutes(30));
        app(WhatsappPairingQrCache::class)->put($firstAccount, 'RAW-QR-FIRST-TOKEN');

        $component = Livewire::test(PairAccount::class, ['token' => $firstPlainToken])
            ->assertSet('state', WhatsappAccount::STATUS_QR_REQUIRED);

        $component
            ->set('token', 'yspair_tampered_token')
            ->call('refreshSnapshot')
            ->assertSet('state', 'invalid')
            ->assertSet('shouldPoll', false);
    }

    protected function createClient(bool $isActive = true): Client
    {
        return Client::query()->create([
            'name' => 'Public Pair Client ' . str()->random(6),
            'slug' => 'public-pair-client-' . str()->random(6),
            'contact_name' => 'Public Pair Contact',
            'phone' => '967700000000',
            'email' => 'public-pair-' . str()->random(6) . '@example.test',
            'is_active' => $isActive,
            'notes' => null,
        ]);
    }

    protected function createWhatsappAccount(
        ?Client $client = null,
        string $status = WhatsappAccount::STATUS_DISCONNECTED,
        bool $isActive = true,
        ?string $notes = null,
    ): WhatsappAccount {
        $client ??= $this->createClient();

        return WhatsappAccount::query()->create([
            'client_id' => $client->id,
            'name' => 'Public Pair Account ' . str()->random(6),
            'phone_number' => '967711111111',
            'status' => $status,
            'last_seen_at' => null,
            'qr_expires_at' => null,
            'is_active' => $isActive,
            'notes' => $notes,
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
            'name' => 'Public Pair Credential',
            'token_hash' => ApiCredential::hashToken($plainToken),
            'abilities' => ['messages:send', 'messages:read'],
            'last_used_at' => null,
            'expires_at' => Carbon::today()->addDays(7)->toDateString(),
            'is_active' => true,
        ]);

        return [$plainToken, $credential->fresh()];
    }

    protected function assertOperationalRecordsAreUntouched(): void
    {
        $this->assertDatabaseCount(Message::class, 0);
        $this->assertDatabaseCount(MessageAttempt::class, 0);
    }
}