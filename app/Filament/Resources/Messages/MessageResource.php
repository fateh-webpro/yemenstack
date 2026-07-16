<?php

namespace App\Filament\Resources\Messages;

use App\Filament\Resources\Messages\Pages\ListMessages;
use App\Models\Message;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
        return $schema->columns(2)->components([
            TextEntry::make('id')->label('المعرف'),
            TextEntry::make('client.name')->label('العميل')->placeholder('-'),
            TextEntry::make('whatsappAccount.name')->label('رقم واتساب')->placeholder('-'),
            TextEntry::make('direction')
                ->label('الاتجاه')
                ->badge()
                ->color(fn (?string $state): string | array => self::directionColor($state))
                ->formatStateUsing(fn (?string $state): string => self::directionLabel($state)),
            TextEntry::make('recipient')->label('المستلم')->placeholder('-'),
            TextEntry::make('sender')->label('المرسل')->placeholder('-'),
            TextEntry::make('message_type')
                ->label('النوع')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => self::typeLabel($state)),
            TextEntry::make('status')
                ->label('الحالة')
                ->badge()
                ->color(fn (?string $state): string | array => self::statusColor($state))
                ->formatStateUsing(fn (?string $state): string => self::statusLabel($state)),
            TextEntry::make('body')
                ->label('نص الرسالة')
                ->placeholder('-')
                ->columnSpanFull(),
            TextEntry::make('payload')
                ->label('البيانات الخام')
                ->formatStateUsing(fn ($state): string => self::formatPayload($state))
                ->placeholder('-')
                ->columnSpanFull(),
            TextEntry::make('external_message_id')->label('رقم الرسالة الخارجي')->placeholder('-'),
            TextEntry::make('scheduled_at')->label('وقت الجدولة')->dateTime('Y-m-d h:i A')->placeholder('-'),
            TextEntry::make('sent_at')->label('وقت الإرسال')->dateTime('Y-m-d h:i A')->placeholder('-'),
            TextEntry::make('failed_at')->label('وقت الفشل')->dateTime('Y-m-d h:i A')->placeholder('-'),
            TextEntry::make('error_message')->label('رسالة الخطأ')->placeholder('-')->columnSpanFull(),
            TextEntry::make('created_at')->label('تاريخ الإنشاء')->dateTime('Y-m-d h:i A')->placeholder('-'),
            TextEntry::make('updated_at')->label('آخر تحديث')->dateTime('Y-m-d h:i A')->placeholder('-'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('المعرف')
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('whatsappAccount.name')
                    ->label('رقم واتساب')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('direction')
                    ->label('الاتجاه')
                    ->badge()
                    ->color(fn (?string $state): string | array => self::directionColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::directionLabel($state))
                    ->sortable(),
                TextColumn::make('recipient')
                    ->label('المستلم')
                    ->searchable(),
                TextColumn::make('sender')
                    ->label('المرسل')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('message_type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::typeLabel($state))
                    ->sortable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (?string $state): string | array => self::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->sortable(),
                TextColumn::make('body')
                    ->label('نص الرسالة')
                    ->searchable()
                    ->limit(80)
                    ->tooltip(fn (?string $state): ?string => filled($state) ? $state : null),
                TextColumn::make('scheduled_at')
                    ->label('وقت الجدولة')
                    ->dateTime('Y-m-d h:i A')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d h:i A')
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
                ViewAction::make()->slideOver(),
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

    protected static function directionColor(?string $state): string | array
    {
        return match ($state) {
            Message::DIRECTION_OUTBOUND => 'info',
            Message::DIRECTION_INBOUND => 'success',
            default => 'gray',
        };
    }

    protected static function statusColor(?string $state): string | array
    {
        return match ($state) {
            Message::STATUS_PENDING => 'warning',
            Message::STATUS_QUEUED => 'info',
            Message::STATUS_SENT, Message::STATUS_DELIVERED, Message::STATUS_READ => 'success',
            Message::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    protected static function formatPayload(mixed $state): string
    {
        if (blank($state)) {
            return '-';
        }

        return json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '-';
    }
}