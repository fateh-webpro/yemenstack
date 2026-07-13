<?php

namespace App\Livewire\Front;

use Livewire\Component;

class HomePage extends Component
{
    public function render()
    {
        return view('livewire.front.home-page')
            ->layout('layouts.front', [
                'title' => 'يمن ستاك | حلول رقمية متكاملة',
            ]);
    }
}