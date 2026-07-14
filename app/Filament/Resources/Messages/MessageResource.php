<?php

namespace App\Filament\Resources\Messages;

use App\Filament\Resources\Messages\Pages\ListMessages;
use App\Filament\Resources\Messages\Pages\ViewMessage;
use App\Models\Message;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class MessageResource extends Resource
{
    protected static ?string $model = Message::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'بوابة الواتساب';

    public static function getNavigationLabel(): string
    {
        return 'الرسائل';
    }

    public static function getModelLabel(): string
    {
        return 'رسالة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الرسائل';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('direction')
                ->label('الاتجاه')
                ->options(Message::directionLabels())
                ->disabled(),
            Select::make('status')
                ->label('الحالة')
                ->options(Message::statusLabels())
                ->disabled(),
            Textarea::make('body')
                ->label('نص الرسالة')
                ->disabled()
                ->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('client.name')->label('العميل')->placeholder('-'),
            TextEntry::make('whatsappAccount.name')->label('رقم واتساب')->placeholder('-'),
            TextEntry::make('direction')
                ->label('الاتجاه')
                ->badge()
                ->formatStateUsing(fn (string $state): string => self::directionLabel($state)),
            TextEntry::make('status')
                ->label('الحالة')
                ->badge()
                ->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
            TextEntry::make('message_type')
                ->label('نوع الرسالة')
                ->formatStateUsing(fn (?string $state): string => self::typeLabel($state)),
            TextEntry::make('sender')->label('المرسل')->placeholder('-'),
            TextEntry::make('recipient')->label('المستلم')->placeholder('-'),
            TextEntry::make('external_message_id')->label('رقم الرسالة الخارجي')->placeholder('-'),
            TextEntry::make('scheduled_at')->label('وقت الجدولة')->dateTime('Y-m-d h:i A')->placeholder('-'),
            TextEntry::make('sent_at')->label('وقت الإرسال')->dateTime('Y-m-d h:i A')->placeholder('-'),
            TextEntry::make('failed_at')->label('وقت الفشل')->dateTime('Y-m-d h:i A')->placeholder('-'),
            TextEntry::make('error_message')->label('رسالة الخطأ')->placeholder('-')->columnSpanFull(),
            TextEntry::make('body')->label('نص الرسالة')->placeholder('-')->columnSpanFull(),
            KeyValueEntry::make('payload')->label('البيانات الخام')->columnSpanFull(),
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
                TextColumn::make('direction')
                    ->label('الاتجاه')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::directionLabel($state))
                    ->sortable(),
                TextColumn::make('sender')
                    ->label('المرسل')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('recipient')
                    ->label('المستلم')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('message_type')
                    ->label('نوع الرسالة')
                    ->formatStateUsing(fn (?string $state): string => self::typeLabel($state))
                    ->sortable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->sortable(),
                TextColumn::make('external_message_id')
                    ->label('رقم الرسالة الخارجي')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('sent_at')
                    ->label('وقت الإرسال')
                    ->since()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('failed_at')
                    ->label('وقت الفشل')
                    ->since()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->label('الاتجاه')
                    ->options(Message::directionLabels()),
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(Message::statusLabels()),
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
            'index' => ListMessages::route('/'),
            'view' => ViewMessage::route('/{record}'),
        ];
    }

    protected static function directionLabel(?string $state): string
    {
        return Message::directionLabels()[$state] ?? ($state ?: '-');
    }

    protected static function statusLabel(?string $state): string
    {
        return Message::statusLabels()[$state] ?? ($state ?: '-');
    }

    protected static function typeLabel(?string $state): string
    {
        return Message::typeLabels()[$state] ?? ($state ?: '-');
    }
}