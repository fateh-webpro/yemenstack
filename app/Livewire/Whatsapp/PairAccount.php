<?php

namespace App\Livewire\Whatsapp;

use App\Services\Whatsapp\WhatsappPairingSessionService;
use Livewire\Component;

class PairAccount extends Component
{
    public string $token = '';

    public string $state = 'connecting';

    public ?string $qrSvg = null;

    public ?string $message = null;

    public ?string $expiresAt = null;

    public bool $shouldPoll = true;

    public bool $completed = false;

    public bool $startRequested = false;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->refreshSnapshot();
    }

    public function updatedToken(): void
    {
        $this->completed = false;
        $this->startRequested = false;
        $this->shouldPoll = true;
    }

    public function refreshSnapshot(): void
    {
        if ($this->completed || (! $this->shouldPoll && $this->startRequested)) {
            return;
        }

        $snapshot = $this->pairingSessionService()->buildSnapshot(
            $this->token,
            requestStart: ! $this->startRequested,
        );

        $this->startRequested = true;
        $this->state = (string) $snapshot['state'];
        $this->message = $snapshot['message'];
        $this->expiresAt = $snapshot['expires_at'];
        $this->qrSvg = $snapshot['qr_svg'];
        $this->shouldPoll = (bool) $snapshot['should_poll'];
        $this->completed = $this->state === 'connected';
    }

    public function render()
    {
        return view('livewire.whatsapp.pair-account')
            ->layout('layouts.front', [
                'title' => 'ربط حساب واتساب',
                'showHeader' => false,
            ]);
    }

    protected function pairingSessionService(): WhatsappPairingSessionService
    {
        return app(WhatsappPairingSessionService::class);
    }
}