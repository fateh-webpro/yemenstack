<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappAccount;
use App\Models\WhatsappPairingToken;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\DB;
use Throwable;

class WhatsappPairingSessionService
{
    public function __construct(
        protected WhatsappPairingQrCache $pairingQrCache,
        protected WhatsappSessionStartService $sessionStartService,
    ) {}

    /**
     * @return array<string, bool|string|null>
     */
    public function buildSnapshot(string $plainToken, bool $requestStart = false): array
    {
        $pairingToken = WhatsappPairingToken::findByPlainToken($plainToken);

        if (! $pairingToken) {
            return $this->invalidSnapshot('invalid');
        }

        $pairingToken->loadMissing('whatsappAccount.client');

        if ($pairingToken->isUsed()) {
            return $this->invalidSnapshot('used');
        }

        if ($pairingToken->isRevoked()) {
            return $this->invalidSnapshot('revoked');
        }

        if ($pairingToken->isExpired()) {
            return $this->invalidSnapshot('expired');
        }

        $whatsappAccount = $pairingToken->whatsappAccount;

        if (! $whatsappAccount instanceof WhatsappAccount || ! $pairingToken->isUsable()) {
            return $this->invalidSnapshot('invalid');
        }

        if ($this->sessionStartService->validationError($whatsappAccount) !== null) {
            return $this->invalidSnapshot('invalid');
        }

        if ($requestStart && ! $whatsappAccount->wantsSessionRunning()) {
            $this->sessionStartService->requestStart($whatsappAccount);
            $whatsappAccount->refresh();
        }

        $whatsappAccount->refresh();

        if ($whatsappAccount->status === WhatsappAccount::STATUS_CONNECTED) {
            $this->markTokenAsUsed($pairingToken);
            $this->forgetQr($whatsappAccount);

            return [
                'state' => 'connected',
                'message' => 'تم ربط حساب واتساب بنجاح.',
                'expires_at' => $pairingToken->expires_at?->toIso8601String(),
                'qr_svg' => null,
                'should_poll' => false,
            ];
        }

        return [
            'state' => $whatsappAccount->status,
            'message' => $this->messageForStatus($whatsappAccount),
            'expires_at' => $pairingToken->expires_at?->toIso8601String(),
            'qr_svg' => $this->resolveQrSvg($whatsappAccount),
            'should_poll' => true,
        ];
    }

    protected function resolveQrSvg(WhatsappAccount $whatsappAccount): ?string
    {
        if ($whatsappAccount->status !== WhatsappAccount::STATUS_QR_REQUIRED) {
            return null;
        }

        $qr = $this->pairingQrCache->get($whatsappAccount);

        if (! is_string($qr) || $qr === '') {
            return null;
        }

        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel' => QRCode::ECC_L,
            'scale' => 8,
            'addQuietzone' => true,
        ]);

        return (new QRCode($options))->render($qr);
    }

    protected function markTokenAsUsed(WhatsappPairingToken $pairingToken): void
    {
        DB::transaction(function () use ($pairingToken): void {
            $lockedToken = WhatsappPairingToken::query()
                ->lockForUpdate()
                ->find($pairingToken->getKey());

            if (! $lockedToken || $lockedToken->used_at !== null) {
                return;
            }

            $lockedToken->markAsUsed();
        });
    }

    protected function forgetQr(WhatsappAccount $whatsappAccount): void
    {
        try {
            $this->pairingQrCache->forget($whatsappAccount);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    protected function messageForStatus(WhatsappAccount $whatsappAccount): string
    {
        return match ($whatsappAccount->status) {
            WhatsappAccount::STATUS_QR_REQUIRED => 'افتح واتساب على الهاتف، ثم انتقل إلى الإعدادات ثم الأجهزة المرتبطة ثم ربط جهاز، وبعدها امسح رمز QR الظاهر.',
            WhatsappAccount::STATUS_AUTHENTICATED => 'تم مسح الرمز، جارٍ إكمال الاتصال...',
            WhatsappAccount::STATUS_CONNECTING => 'جارٍ تجهيز رمز الربط...',
            WhatsappAccount::STATUS_DISCONNECTED => 'جارٍ تجهيز رمز الربط...',
            WhatsappAccount::STATUS_ERROR => 'تعذر إكمال الربط حاليًا. ما زالت المحاولة جارية، وإن استمرت المشكلة فيلزم طلب رابط جديد.',
            WhatsappAccount::STATUS_LOGGED_OUT => 'انتهت جلسة واتساب الحالية، وجارٍ تجهيز ربط جديد إذا بقي الرابط صالحًا.',
            default => 'جارٍ تجهيز رمز الربط...',
        };
    }

    /**
     * @return array<string, bool|string|null>
     */
    protected function invalidSnapshot(string $state): array
    {
        return [
            'state' => $state,
            'message' => 'رابط الربط غير صالح أو انتهت صلاحيته. يرجى طلب رابط جديد.',
            'expires_at' => null,
            'qr_svg' => null,
            'should_poll' => false,
        ];
    }
}