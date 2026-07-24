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

class WhatsappEngineSessionMessageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected string $internalToken = 'test-internal-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp_engine.internal_token', $this->internalToken);
    }

    public function test_all_new_session_message_routes_require_internal_token(): void
    {
        $account = $this->createWhatsappAccount();
        $message = $this->createMessage($account->client, $account);

        $this->getJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/pending")->assertUnauthorized();
        $this->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$message->id}/claim")->assertUnauthorized();
        $this->getJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/queued")->assertUnauthorized();
        $this->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$message->id}/mark-sent")->assertUnauthorized();
        $this->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$message->id}/mark-failed")->assertUnauthorized();
    }

    public function test_wrong_internal_token_and_api_credential_token_are_rejected(): void
    {
        [$plainToken, $credential, $client, $account] = $this->createCredential();
        $message = $this->createMessage($client, $account);

        $this->withToken('wrong-token')
            ->getJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/pending")
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);

        $this->withToken($plainToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$message->id}/claim")
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);

        $this->assertNull($credential->fresh()->last_used_at);
    }

    public function test_pending_returns_only_due_outbound_messages_for_the_requested_account(): void
    {
        $client = $this->createClient('Central Pending Client');
        $account = $this->createWhatsappAccount($client, 'Central Pending Account', '967730000001');
        $sameClientOtherAccount = $this->createWhatsappAccount($client, 'Central Other Account', '967730000002');
        $otherClient = $this->createClient('Another Client');
        $otherAccount = $this->createWhatsappAccount($otherClient, 'Another Account', '967730000003');

        $firstPending = $this->createMessage($client, $account, [
            'recipient' => '967731000001',
            'scheduled_at' => null,
        ]);

        $secondPending = $this->createMessage($client, $account, [
            'recipient' => '967731000002',
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->createMessage($client, $account, [
            'recipient' => '967731000003',
            'scheduled_at' => now()->addHour(),
        ]);

        $this->createMessage($client, $account, [
            'recipient' => '967731000004',
            'status' => Message::STATUS_QUEUED,
        ]);

        $this->createMessage($client, $account, [
            'recipient' => '967731000005',
            'direction' => Message::DIRECTION_INBOUND,
        ]);

        $this->createMessage($client, $sameClientOtherAccount, [
            'recipient' => '967731000006',
        ]);

        $this->createMessage($otherClient, $otherAccount, [
            'recipient' => '967731000007',
        ]);

        $response = $this->withToken($this->internalToken)
            ->getJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/pending?limit=1");

        $response
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('meta.limit', 1)
            ->assertJsonPath('data.0.id', $firstPending->id)
            ->assertJsonPath('data.0.status', Message::STATUS_PENDING)
            ->assertJsonMissingPath('data.0.client_id')
            ->assertJsonMissingPath('data.0.whatsapp_account_id');

        $this->assertSame(Message::STATUS_PENDING, $firstPending->fresh()->status);
        $this->assertSame(Message::STATUS_PENDING, $secondPending->fresh()->status);
    }

    public function test_pending_rejects_inactive_account_and_inactive_client(): void
    {
        $inactiveAccount = $this->createWhatsappAccount($this->createClient('Inactive Account Client'), 'Inactive Account', '967730000011', false);

        $this->withToken($this->internalToken)
            ->getJson("/api/v1/whatsapp/engine/sessions/{$inactiveAccount->id}/messages/pending")
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'WhatsApp account is inactive.',
            ]);

        $inactiveClient = $this->createClient('Inactive Client', false);
        $inactiveClientAccount = $this->createWhatsappAccount($inactiveClient, 'Inactive Client Account', '967730000012');

        $this->withToken($this->internalToken)
            ->getJson("/api/v1/whatsapp/engine/sessions/{$inactiveClientAccount->id}/messages/pending")
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'WhatsApp account client is inactive.',
            ]);
    }

    public function test_claim_moves_pending_message_to_queued_and_creates_attempt(): void
    {
        $client = $this->createClient('Claim Client');
        $account = $this->createWhatsappAccount($client, 'Claim Account', '967730000021');
        $message = $this->createMessage($client, $account, [
            'recipient' => '967731000021',
            'body' => 'Central claim message',
        ]);

        $response = $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$message->id}/claim");

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

        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_QUEUED,
            'client_id' => $client->id,
            'whatsapp_account_id' => $account->id,
        ]);
        $this->assertDatabaseHas(MessageAttempt::class, [
            'id' => $attemptId,
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_QUEUED,
        ]);
    }

    public function test_claim_returns_404_for_message_from_another_account_and_409_for_non_claimable_message(): void
    {
        $client = $this->createClient('Claim Scope Client');
        $account = $this->createWhatsappAccount($client, 'Claim Scope Account', '967730000031');
        $otherAccount = $this->createWhatsappAccount($client, 'Other Scope Account', '967730000032');

        $foreignMessage = $this->createMessage($client, $otherAccount, [
            'recipient' => '967731000031',
        ]);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$foreignMessage->id}/claim")
            ->assertNotFound()
            ->assertJson([
                'success' => false,
                'message' => 'Message not found.',
            ]);

        $queuedMessage = $this->createMessage($client, $account, [
            'recipient' => '967731000032',
            'status' => Message::STATUS_QUEUED,
        ]);

        MessageAttempt::query()->create([
            'message_id' => $queuedMessage->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_QUEUED,
            'response_payload' => null,
            'error_message' => null,
            'attempted_at' => now(),
        ]);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$queuedMessage->id}/claim")
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Message is not claimable.',
            ]);

        $this->assertDatabaseCount(MessageAttempt::class, 1);
    }

    public function test_queued_returns_only_scoped_queued_messages(): void
    {
        $client = $this->createClient('Queued Scope Client');
        $account = $this->createWhatsappAccount($client, 'Queued Scope Account', '967730000041');
        $sameClientOtherAccount = $this->createWhatsappAccount($client, 'Queued Scope Account 2', '967730000042');

        $firstQueued = $this->createMessage($client, $account, [
            'recipient' => '967731000041',
            'status' => Message::STATUS_QUEUED,
        ]);

        $this->createMessage($client, $account, [
            'recipient' => '967731000042',
            'status' => Message::STATUS_PENDING,
        ]);

        $this->createMessage($client, $sameClientOtherAccount, [
            'recipient' => '967731000043',
            'status' => Message::STATUS_QUEUED,
        ]);

        $response = $this->withToken($this->internalToken)
            ->getJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/queued?limit=1");

        $response
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', $firstQueued->id)
            ->assertJsonMissingPath('data.0.client_id')
            ->assertJsonMissingPath('data.0.whatsapp_account_id');
    }

    public function test_mark_sent_updates_scoped_message_and_attempt(): void
    {
        $client = $this->createClient('Mark Sent Client');
        $account = $this->createWhatsappAccount($client, 'Mark Sent Account', '967730000051');
        $message = $this->createMessage($client, $account, [
            'status' => Message::STATUS_QUEUED,
            'recipient' => '967731000051',
        ]);

        $attempt = MessageAttempt::query()->create([
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_QUEUED,
            'response_payload' => null,
            'error_message' => null,
            'attempted_at' => now()->subMinute(),
        ]);

        $response = $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$message->id}/mark-sent", [
                'external_message_id' => 'central-sent-message-1',
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Message marked as sent.',
                'data' => [
                    'message_id' => $message->id,
                    'status' => Message::STATUS_SENT,
                    'external_message_id' => 'central-sent-message-1',
                    'attempt_id' => $attempt->id,
                    'attempt_status' => MessageAttempt::STATUS_SENT,
                ],
            ]);

        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_SENT,
            'external_message_id' => 'central-sent-message-1',
        ]);
        $this->assertDatabaseHas(MessageAttempt::class, [
            'id' => $attempt->id,
            'status' => MessageAttempt::STATUS_SENT,
        ]);
    }

    public function test_mark_sent_rejects_foreign_message_and_non_queued_message(): void
    {
        $client = $this->createClient('Mark Sent Scope Client');
        $account = $this->createWhatsappAccount($client, 'Mark Sent Scope Account', '967730000061');
        $otherAccount = $this->createWhatsappAccount($client, 'Mark Sent Scope Account 2', '967730000062');

        $foreignMessage = $this->createMessage($client, $otherAccount, [
            'status' => Message::STATUS_QUEUED,
        ]);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$foreignMessage->id}/mark-sent", [
                'external_message_id' => 'central-foreign-sent',
            ])
            ->assertNotFound();

        $pendingMessage = $this->createMessage($client, $account, [
            'status' => Message::STATUS_PENDING,
        ]);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$pendingMessage->id}/mark-sent", [
                'external_message_id' => 'central-non-queued-sent',
            ])
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Message is not sendable.',
            ]);
    }

    public function test_mark_failed_updates_scoped_message_and_attempt(): void
    {
        $client = $this->createClient('Mark Failed Client');
        $account = $this->createWhatsappAccount($client, 'Mark Failed Account', '967730000071');
        $message = $this->createMessage($client, $account, [
            'status' => Message::STATUS_QUEUED,
        ]);

        $attempt = MessageAttempt::query()->create([
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_QUEUED,
            'response_payload' => null,
            'error_message' => null,
            'attempted_at' => now()->subMinute(),
        ]);

        $response = $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$message->id}/mark-failed", [
                'error_message' => 'Central failure message.',
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Message marked as failed.',
                'data' => [
                    'message_id' => $message->id,
                    'status' => Message::STATUS_FAILED,
                    'attempt_id' => $attempt->id,
                    'attempt_status' => MessageAttempt::STATUS_FAILED,
                ],
            ]);

        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_FAILED,
            'error_message' => 'Central failure message.',
        ]);
        $this->assertDatabaseHas(MessageAttempt::class, [
            'id' => $attempt->id,
            'status' => MessageAttempt::STATUS_FAILED,
            'error_message' => 'Central failure message.',
        ]);
    }

    public function test_mark_failed_rejects_foreign_message_and_non_queued_message(): void
    {
        $client = $this->createClient('Mark Failed Scope Client');
        $account = $this->createWhatsappAccount($client, 'Mark Failed Scope Account', '967730000081');
        $otherAccount = $this->createWhatsappAccount($client, 'Mark Failed Scope Account 2', '967730000082');

        $foreignMessage = $this->createMessage($client, $otherAccount, [
            'status' => Message::STATUS_QUEUED,
        ]);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$foreignMessage->id}/mark-failed", [
                'error_message' => 'Should not update.',
            ])
            ->assertNotFound();

        $sentMessage = $this->createMessage($client, $account, [
            'status' => Message::STATUS_SENT,
        ]);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$sentMessage->id}/mark-failed", [
                'error_message' => 'Should not update.',
            ])
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Message is not sendable.',
            ]);
    }

    public function test_full_success_lifecycle_runs_through_central_routes(): void
    {
        $client = $this->createClient('Central Success Client');
        $account = $this->createWhatsappAccount($client, 'Central Success Account', '967730000091');
        $message = $this->createMessage($client, $account, [
            'recipient' => '967731000091',
        ]);

        $this->withToken($this->internalToken)
            ->getJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/pending")
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->id);

        $claimResponse = $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$message->id}/claim")
            ->assertOk();

        $attemptId = $claimResponse->json('data.attempt_id');

        $this->withToken($this->internalToken)
            ->getJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/queued")
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->id);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$message->id}/mark-sent", [
                'external_message_id' => 'central-lifecycle-sent',
            ])
            ->assertOk()
            ->assertJsonPath('data.attempt_id', $attemptId);

        $this->withToken($this->internalToken)
            ->getJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/pending")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($this->internalToken)
            ->getJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/queued")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_full_failure_lifecycle_runs_through_central_routes(): void
    {
        $client = $this->createClient('Central Failure Client');
        $account = $this->createWhatsappAccount($client, 'Central Failure Account', '967730000101');
        $message = $this->createMessage($client, $account);

        $claimResponse = $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$message->id}/claim")
            ->assertOk();

        $attemptId = $claimResponse->json('data.attempt_id');

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$message->id}/mark-failed", [
                'error_message' => 'Central lifecycle failure.',
            ])
            ->assertOk()
            ->assertJsonPath('data.attempt_id', $attemptId);

        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_FAILED,
            'error_message' => 'Central lifecycle failure.',
        ]);
    }

    public function test_mark_sent_still_works_after_account_is_disabled_and_mark_failed_still_works_after_client_is_disabled(): void
    {
        $sentClient = $this->createClient('Late Disable Client Sent');
        $sentAccount = $this->createWhatsappAccount($sentClient, 'Late Disable Account Sent', '967730000111');
        $sentMessage = $this->createMessage($sentClient, $sentAccount);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$sentAccount->id}/messages/{$sentMessage->id}/claim")
            ->assertOk();

        $sentAccount->update(['is_active' => false]);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$sentAccount->id}/messages/{$sentMessage->id}/mark-sent", [
                'external_message_id' => 'central-disabled-account-sent',
            ])
            ->assertOk();

        $failedClient = $this->createClient('Late Disable Client Failed');
        $failedAccount = $this->createWhatsappAccount($failedClient, 'Late Disable Account Failed', '967730000112');
        $failedMessage = $this->createMessage($failedClient, $failedAccount);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$failedAccount->id}/messages/{$failedMessage->id}/claim")
            ->assertOk();

        $failedClient->update(['is_active' => false]);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$failedAccount->id}/messages/{$failedMessage->id}/mark-failed", [
                'error_message' => 'Client disabled after claim.',
            ])
            ->assertOk();
    }

    public function test_internal_session_message_routes_do_not_update_api_credential_usage_or_create_unrelated_records(): void
    {
        [$plainToken, $credential, $client, $account] = $this->createCredential();
        $message = $this->createMessage($client, $account);

        $this->assertNull($credential->last_used_at);
        $this->assertSame(0, WhatsappPairingToken::query()->count());

        $this->withToken($this->internalToken)
            ->getJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/pending")
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->id);

        $this->assertNull($credential->fresh()->last_used_at);
        $this->assertSame(ApiCredential::hashToken($plainToken), $credential->fresh()->token_hash);
        $this->assertDatabaseCount(WhatsappPairingToken::class, 0);
    }

    protected function createClient(string $name, bool $isActive = true): Client
    {
        return Client::query()->create([
            'name' => $name,
            'slug' => str($name)->slug() . '-' . str()->random(6),
            'contact_name' => 'Central Engine Contact',
            'phone' => '967730100100',
            'email' => str($name)->slug() . '-' . str()->random(6) . '@example.test',
            'is_active' => $isActive,
            'notes' => null,
        ]);
    }

    protected function createWhatsappAccount(
        ?Client $client = null,
        ?string $name = null,
        string $phoneNumber = '967730200201',
        bool $isActive = true,
        string $status = WhatsappAccount::STATUS_CONNECTED,
    ): WhatsappAccount {
        $client ??= $this->createClient('Central Default Account Client');

        return WhatsappAccount::query()->create([
            'client_id' => $client->id,
            'name' => $name ?? ('Central Account ' . str()->random(6)),
            'phone_number' => $phoneNumber,
            'session_name' => 'central_session_' . str()->random(8),
            'status' => $status,
            'last_seen_at' => null,
            'qr_expires_at' => null,
            'is_active' => $isActive,
            'notes' => null,
        ]);
    }

    /**
     * @return array{0:string,1:ApiCredential,2:Client,3:WhatsappAccount}
     */
    protected function createCredential(array $overrides = []): array
    {
        $client = $this->createClient($overrides['client_name'] ?? 'Central Credential Client', $overrides['client_active'] ?? true);

        $whatsappAccount = $this->createWhatsappAccount(
            $client,
            $overrides['account_name'] ?? 'Central Credential Account',
            $overrides['phone_number'] ?? '967730200200',
            $overrides['whatsapp_account_active'] ?? true,
            $overrides['account_status'] ?? WhatsappAccount::STATUS_CONNECTED,
        );

        $plainToken = ApiCredential::generatePlainToken();

        $credential = ApiCredential::query()->create([
            'client_id' => $overrides['credential_client_id'] ?? $client->id,
            'whatsapp_account_id' => $overrides['credential_whatsapp_account_id'] ?? $whatsappAccount->id,
            'name' => $overrides['credential_name'] ?? 'Central Credential',
            'token_hash' => ApiCredential::hashToken($plainToken),
            'abilities' => $overrides['abilities'] ?? ['messages:read', 'messages:send'],
            'last_used_at' => null,
            'expires_at' => $overrides['expires_at'] ?? Carbon::today()->addDays(7)->toDateString(),
            'is_active' => $overrides['is_active'] ?? true,
        ]);

        return [$plainToken, $credential->fresh(), $client->fresh(), $whatsappAccount->fresh()];
    }

    protected function createMessage(Client $client, WhatsappAccount $account, array $overrides = []): Message
    {
        return Message::query()->create(array_merge([
            'client_id' => $client->id,
            'whatsapp_account_id' => $account->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967730300300',
            'sender' => $account->phone_number,
            'message_type' => Message::TYPE_TEXT,
            'body' => 'Central engine lifecycle test message',
            'payload' => ['source' => 'central-engine-feature-test'],
            'status' => Message::STATUS_PENDING,
            'external_message_id' => null,
            'scheduled_at' => null,
            'sent_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ], $overrides));
    }
}