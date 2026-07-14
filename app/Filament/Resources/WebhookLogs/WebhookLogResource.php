<?php

namespace App\Filament\Resources\WebhookLogs;

use App\Filament\Resources\WebhookLogs\Pages\ListWebhookLogs;
use App\Filament\Resources\WebhookLogs\Pages\ViewWebhookLog;
use App\Models\WebhookLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class WebhookLogResource extends Resource
{
    protected static ?string $model = WebhookLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'بوابة الواتساب';

    public static function getNavigationLabel(): string
    {
        return 'سجلات Webhooks';
    }

    public static function getModelLabel(): string
    {
        return 'سجل Webhook';
    }

    public static function getPluralModelLabel(): string
    {
        return 'سجلات Webhooks';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('client.name')->label('العميل')->placeholder('-'),
            TextEntry::make('whatsappAccount.name')->label('رقم واتساب')->placeholder('-'),
            TextEntry::make('event')->label('الحدث'),
            TextEntry::make('status')
                ->label('الحالة')
                ->badge()
                ->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
            TextEntry::make('processed_at')->label('وقت المعالجة')->dateTime('Y-m-d h:i A')->placeholder('-'),
            TextEntry::make('error_message')->label('رسالة الخطأ')->placeholder('-')->columnSpanFull(),
            KeyValueEntry::make('payload')->label('البيانات')->columnSpanFull(),
            TextEntry::make('created_at')->label('تاريخ الإنشاء')->dateTime('Y-m-d h:i A')->placeholder('-'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client.name')
                    ->label('العميل')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('whatsappAccount.name')
                    ->label('رقم واتساب')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('event')
                    ->label('الحدث')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->sortable(),
                TextColumn::make('processed_at')
                    ->label('وقت المعالجة')
                    ->since()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(WebhookLog::statusLabels()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookLogs::route('/'),
            'view' => ViewWebhookLog::route('/{record}'),
        ];
    }

    protected static function statusLabel(?string $state): string
    {
        return WebhookLog::statusLabels()[$state] ?? ($state ?: '-');
    }
}