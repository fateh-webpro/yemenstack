<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SiteSetting extends Model
{
    protected $fillable = [
        'site_name',
        'brand_name',
        'brand_logo',
        'login_logo',
        'favicon',
        'phone',
        'whatsapp',
        'email',
        'address',
        'meta_title',
        'meta_description',
        'primary_color',
        'success_color',
        'danger_color',
        'is_maintenance_mode',
    ];

    protected function casts(): array
    {
        return [
            'is_maintenance_mode' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create(static::defaultAttributes());
    }

    public static function currentOrFallback(): self
    {
        try {
            if (! Schema::hasTable((new static())->getTable())) {
                return static::fallback();
            }

            return static::current();
        } catch (Throwable) {
            return static::fallback();
        }
    }

    public static function defaultAttributes(): array
    {
        return [
            'site_name' => config('starter.name'),
            'brand_name' => config('starter.brand_name'),
            'brand_logo' => config('starter.brand_logo'),
            'login_logo' => config('starter.login_logo'),
            'favicon' => config('starter.favicon'),
            'phone' => null,
            'whatsapp' => null,
            'email' => null,
            'address' => null,
            'meta_title' => config('starter.name'),
            'meta_description' => null,
            'primary_color' => data_get(config('starter.colors'), 'primary.500'),
            'success_color' => data_get(config('starter.colors'), 'success.500'),
            'danger_color' => data_get(config('starter.colors'), 'danger.500'),
            'is_maintenance_mode' => false,
        ];
    }

    public static function fallback(): self
    {
        $setting = new static();
        $setting->forceFill(static::defaultAttributes());

        return $setting;
    }

    public function resolvedSiteName(): string
    {
        return $this->site_name ?: config('starter.name');
    }

    public function resolvedBrandName(): string
    {
        return $this->brand_name ?: config('starter.brand_name');
    }

    public function brandLogoUrl(): string
    {
        return $this->resolveMediaUrl($this->brand_logo, 'brand_logo');
    }

    public function loginLogoUrl(): string
    {
        return $this->resolveMediaUrl($this->login_logo, 'login_logo');
    }

    public function faviconUrl(): string
    {
        return $this->resolveMediaUrl($this->favicon, 'favicon');
    }

    protected function resolveMediaUrl(?string $value, string $fallbackKey): string
    {
        $path = filled($value) ? $value : config("starter.{$fallbackKey}");

        if (blank($path)) {
            return '';
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        if (str_starts_with($path, 'site-settings/')) {
            return asset('storage/' . ltrim($path, '/'));
        }

        return asset($path);
    }
}