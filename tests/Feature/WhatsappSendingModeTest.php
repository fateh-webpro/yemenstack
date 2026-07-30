<?php

namespace Tests\Feature;

use App\Filament\Resources\Messages\MessageResource;
use App\Models\Client;
use App\Models\Message;
use App\Models\MessageAttempt;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EngineMessageLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappSendingModeTest extends TestCase
{
    use RefreshDatabase;

    protected string $internalToken = 'sending-mode-internal-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp_engine.internal_token', $this->internalToken);
    }

    public function test_whatsapp_account_defaults_to_manual_sending(): void
    {
        $account = $this->createWhatsappAccount();

        $this->assertFalse($account->automatic_sending_enabled);
        $this->assertFalse($account->automaticSendingEnabled());
    }

    public function test_engine_sessions_payload_exposes_automatic_sending_enabled(): void
    {
        $account = $this->createWhatsappAccount();
        $account->forceFill(['automatic_sending_enabled' => true])->save();

        $this->withToken($this->internalToken)
            ->getJson('/api/v1/whatsapp/engine/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.automatic_sending_enabled', true);
    }

    public function test_automatic_claim_is_rejected_when_account_is_manual(): void
    {
        $account = $this->createWhatsappAccount();
        $message = $this->createMessage($account);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$message->id}/claim", [
                'mode' => 'automatic',
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_PENDING,
            'manual_send_requested' => false,
        ]);
        $this->assertDatabaseCount(MessageAttempt::class, 0);
    }

    public function test_manual_claim_endpoint_cannot_bypass_manual_request_guard(): void
    {
        $account = $this->createWhatsappAccount();
        $message = $this->createMessage($account);

        $this->withToken($this->internalToken)
            ->postJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/{$message->id}/claim", [
                'mode' => 'manual',
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_PENDING,
            'manual_send_requested' => false,
        ]);
    }

    public function test_automatic_queued_endpoint_returns_no_messages_when_account_is_manual(): void
    {
        $account = $this->createWhatsappAccount();
        $queued = $this->createMessage($account, [
            'status' => Message::STATUS_QUEUED,
            'manual_send_requested' => false,
        ]);

        $this->withToken($this->internalToken)
            ->getJson("/api/v1/whatsapp/engine/sessions/{$account->id}/messages/queued?mode=automatic")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas(Message::class, [
            'id' => $queued->id,
            'status' => Message::STATUS_QUEUED,
            'manual_send_requested' => false,
        ]);
    }

    public function test_request_manual_message_send_claims_one_pending_message_and_marks_it_manual(): void
    {
        $service = app(EngineMessageLifecycleService::class);
        $account = $this->createWhatsappAccount();
        $message = $this->createMessage($account);

        $result = $service->requestManualMessageSend($account, $message);

        $this->assertTrue($result['ok']);
        $this->assertSame('queued_for_manual_send', $result['code']);
        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_QUEUED,
            'manual_send_requested' => true,
        ]);
        $this->assertDatabaseHas(MessageAttempt::class, [
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_QUEUED,
        ]);
    }

    public function test_request_manual_message_send_rejects_accounts_with_automatic_sending_enabled(): void
    {
        $service = app(EngineMessageLifecycleService::class);
        $account = $this->createWhatsappAccount();
        $account->forceFill(['automatic_sending_enabled' => true])->save();
        $message = $this->createMessage($account);

        $result = $service->requestManualMessageSend($account->fresh(), $message);

        $this->assertFalse($result['ok']);
        $this->assertSame('automatic_enabled', $result['code']);
        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_PENDING,
            'manual_send_requested' => false,
        ]);
    }

    public function test_request_manual_message_send_rejects_non_connected_account_server_side(): void
    {
        $service = app(EngineMessageLifecycleService::class);
        $account = $this->createWhatsappAccount();
        $account->forceFill(['status' => WhatsappAccount::STATUS_DISCONNECTED])->save();
        $message = $this->createMessage($account);

        $result = $service->requestManualMessageSend($account->fresh(), $message);

        $this->assertFalse($result['ok']);
        $this->assertSame('session_not_connected', $result['code']);
        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_PENDING,
            'manual_send_requested' => false,
        ]);
        $this->assertDatabaseCount(MessageAttempt::class, 0);
    }

    public function test_request_manual_message_send_is_idempotent_on_double_click(): void
    {
        $service = app(EngineMessageLifecycleService::class);
        $account = $this->createWhatsappAccount();
        $message = $this->createMessage($account);

        $first = $service->requestManualMessageSend($account, $message);
        $second = $service->requestManualMessageSend($account->fresh(), $message->fresh());

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('already_requested', $second['code']);
        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_QUEUED,
            'manual_send_requested' => true,
        ]);
        $this->assertDatabaseCount(MessageAttempt::class, 1);
    }

    public function test_request_manual_message_send_marks_existing_queued_message_without_new_attempt(): void
    {
        $service = app(EngineMessageLifecycleService::class);
        $account = $this->createWhatsappAccount();
        $message = $this->createMessage($account, [
            'status' => Message::STATUS_QUEUED,
            'manual_send_requested' => false,
        ]);

        MessageAttempt::query()->create([
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_QUEUED,
            'response_payload' => null,
            'error_message' => null,
            'attempted_at' => now(),
        ]);

        $result = $service->requestManualMessageSend($account, $message);

        $this->assertTrue($result['ok']);
        $this->assertSame('queued_for_manual_send', $result['code']);
        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_QUEUED,
            'manual_send_requested' => true,
        ]);
        $this->assertDatabaseCount(MessageAttempt::class, 1);
    }

    public function test_manual_send_rejects_sent_delivered_and_read_messages(): void
    {
        $service = app(EngineMessageLifecycleService::class);
        $account = $this->createWhatsappAccount();

        foreach ([Message::STATUS_SENT, Message::STATUS_DELIVERED, Message::STATUS_READ] as $status) {
            $message = $this->createMessage($account, [
                'status' => $status,
                'manual_send_requested' => false,
            ]);

            $result = $service->requestManualMessageSend($account, $message);

            $this->assertFalse($result['ok']);
            $this->assertSame('already_sent', $result['code']);
            $this->assertDatabaseHas(Message::class, [
                'id' => $message->id,
                'status' => $status,
                'manual_send_requested' => false,
            ]);
        }
    }

    public function test_retry_failed_message_send_queues_a_single_manual_attempt(): void
    {
        $service = app(EngineMessageLifecycleService::class);
        $account = $this->createWhatsappAccount();
        $message = $this->createMessage($account, [
            'status' => Message::STATUS_FAILED,
            'failed_at' => now(),
            'error_message' => 'old failure',
        ]);

        MessageAttempt::query()->create([
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_FAILED,
            'response_payload' => null,
            'error_message' => 'old failure',
            'attempted_at' => now(),
        ]);

        $result = $service->retryFailedMessageSend($account, $message);

        $this->assertTrue($result['ok']);
        $this->assertSame('queued_for_manual_send', $result['code']);
        $this->assertDatabaseHas(Message::class, [
            'id' => $message->id,
            'status' => Message::STATUS_QUEUED,
            'manual_send_requested' => true,
            'failed_at' => null,
            'error_message' => null,
        ]);
        $this->assertDatabaseHas(MessageAttempt::class, [
            'message_id' => $message->id,
            'attempt_number' => 2,
            'status' => MessageAttempt::STATUS_QUEUED,
        ]);
    }

    public function test_mark_sent_and_mark_failed_reset_manual_send_requested_to_false(): void
    {
        $service = app(EngineMessageLifecycleService::class);
        $account = $this->createWhatsappAccount();

        $sentMessage = $this->createQueuedMessageWithAttempt($account, true);
        $failedMessage = $this->createQueuedMessageWithAttempt($account, true, 'failed-target');

        $service->markMessageSent($account, $sentMessage, [
            'mode' => 'live',
            'provider' => 'whatsapp-web.js',
            'external_message_id' => 'wamid.test.sent',
        ]);

        $service->markMessageFailed($account, $failedMessage, [
            'mode' => 'live',
            'provider' => 'whatsapp-web.js',
            'error_message' => 'send failed',
        ]);

        $this->assertDatabaseHas(Message::class, [
            'id' => $sentMessage->id,
            'status' => Message::STATUS_SENT,
            'manual_send_requested' => false,
        ]);
        $this->assertDatabaseHas(Message::class, [
            'id' => $failedMessage->id,
            'status' => Message::STATUS_FAILED,
            'manual_send_requested' => false,
        ]);
    }

    public function test_message_resource_query_can_scope_messages_to_requested_whatsapp_account(): void
    {
        $firstAccount = $this->createWhatsappAccount();
        $secondAccount = $this->createWhatsappAccount($firstAccount->client, 'Second Account', '967700000222');
        $firstMessage = $this->createMessage($firstAccount);
        $this->createMessage($secondAccount, ['recipient' => '967700000333']);

        request()->query->set('whatsapp_account_id', $firstAccount->id);

        $messageIds = MessageResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$firstMessage->id], $messageIds);
        $this->assertSame($firstAccount->id, MessageResource::selectedWhatsappAccountFromRequest()?->id);
    }

    protected function createClient(?string $name = null): Client
    {
        $name ??= 'Sending Mode Client ' . str()->random(6);

        return Client::query()->create([
            'name' => $name,
            'slug' => str($name)->slug() . '-' . str()->random(6),
            'contact_name' => 'Sending Mode Contact',
            'phone' => '967700100100',
            'email' => str($name)->slug() . '-' . str()->random(6) . '@example.test',
            'is_active' => true,
            'notes' => null,
        ]);
    }

    protected function createWhatsappAccount(?Client $client = null, ?string $name = null, string $phoneNumber = '967700000111'): WhatsappAccount
    {
        $client ??= $this->createClient();

        return WhatsappAccount::query()->create([
            'client_id' => $client->id,
            'name' => $name ?? ('Sending Mode Account ' . str()->random(6)),
            'phone_number' => $phoneNumber,
            'session_name' => 'wa_' . str()->lower(str()->random(24)),
            'session_desired_state' => WhatsappAccount::SESSION_DESIRED_RUNNING,
            'status' => WhatsappAccount::STATUS_CONNECTED,
            'last_seen_at' => null,
            'qr_expires_at' => null,
            'is_active' => true,
            'notes' => null,
        ]);
    }

    protected function createMessage(WhatsappAccount $account, array $overrides = []): Message
    {
        return Message::query()->create(array_merge([
            'client_id' => $account->client_id,
            'whatsapp_account_id' => $account->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'recipient' => '967700200200',
            'sender' => $account->phone_number,
            'message_type' => Message::TYPE_TEXT,
            'body' => 'Sending mode test message',
            'payload' => ['source' => 'sending-mode-feature-test'],
            'status' => Message::STATUS_PENDING,
            'manual_send_requested' => false,
            'external_message_id' => null,
            'scheduled_at' => null,
            'sent_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ], $overrides));
    }

    protected function createQueuedMessageWithAttempt(WhatsappAccount $account, bool $manualRequested, string $body = 'queued message'): Message
    {
        $message = $this->createMessage($account, [
            'status' => Message::STATUS_QUEUED,
            'manual_send_requested' => $manualRequested,
            'body' => $body,
        ]);

        MessageAttempt::query()->create([
            'message_id' => $message->id,
            'attempt_number' => 1,
            'status' => MessageAttempt::STATUS_QUEUED,
            'response_payload' => null,
            'error_message' => null,
            'attempted_at' => now(),
        ]);

        return $message;
    }
}