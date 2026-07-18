<?php

namespace App\Filament\Resources\Messages;

use App\Filament\Resources\Messages\Pages\ListMessages;
use App\Models\Message;
use App\Models\MessageAttempt;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['client', 'whatsappAccount', 'attempts']);
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
            Section::make('معلومات الرسالة')
                ->schema([
                    TextEntry::make('id')->label('Message ID'),
                    TextEntry::make('client.name')->label('العميل')->placeholder('-'),
                    TextEntry::make('whatsappAccount.name')->label('رقم واتساب')->placeholder('-'),
                    TextEntry::make('direction')
                        ->label('الاتجاه')
                        ->badge()
                        ->color(fn (?string $state): string | array => self::directionColor($state))
                        ->formatStateUsing(fn (?string $state): string => self::directionLabel($state)),
                    TextEntry::make('message_type')
                        ->label('نوع الرسالة')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => self::typeLabel($state)),
                    TextEntry::make('status')
                        ->label('الحالة')
                        ->badge()
                        ->color(fn (?string $state): string | array => self::statusColor($state))
                        ->formatStateUsing(fn (?string $state): string => self::statusLabel($state)),
                    TextEntry::make('recipient')->label('المستلم')->placeholder('-'),
                    TextEntry::make('sender')->label('المرسل')->placeholder('-'),
                ])
                ->columns(2),
            Section::make('محتوى الرسالة')
                ->schema([
                    TextEntry::make('body')
                        ->label('نص الرسالة')
                        ->placeholder('-')
                        ->columnSpanFull(),
                    TextEntry::make('payload')
                        ->label('البيانات الخام')
                        ->formatStateUsing(fn (mixed $state): string => self::formatJsonValue($state))
                        ->placeholder('-')
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->columnSpanFull(),
                ])
                ->columns(1),
            Section::make('تتبع دورة الرسالة')
                ->schema([
                    TextEntry::make('scheduled_at')->label('وقت الجدولة')->dateTime('Y-m-d h:i A')->placeholder('-'),
                    TextEntry::make('created_at')->label('تاريخ الإنشاء')->dateTime('Y-m-d h:i A')->placeholder('-'),
                    TextEntry::make('updated_at')->label('آخر تحديث')->dateTime('Y-m-d h:i A')->placeholder('-'),
                    TextEntry::make('sent_at')->label('وقت الإرسال')->dateTime('Y-m-d h:i A')->placeholder('-'),
                    TextEntry::make('failed_at')->label('وقت الفشل')->dateTime('Y-m-d h:i A')->placeholder('-'),
                    TextEntry::make('external_message_id')->label('رقم الرسالة الخارجي')->placeholder('-'),
                    TextEntry::make('error_message')->label('رسالة الخطأ')->placeholder('-')->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('محاولات المعالجة')
                ->schema([
                    RepeatableEntry::make('attempts')
                        ->label('Message Attempts')
                        ->contained(false)
                        ->schema([
                            TextEntry::make('id')->label('المعرف'),
                            TextEntry::make('attempt_number')->label('رقم المحاولة'),
                            TextEntry::make('status')
                                ->label('الحالة')
                                ->badge()
                                ->color(fn (?string $state): string | array => self::attemptStatusColor($state))
                                ->formatStateUsing(fn (?string $state): string => self::attemptStatusLabel($state)),
                            TextEntry::make('attempted_at')->label('وقت المعالجة')->dateTime('Y-m-d h:i A')->placeholder('-'),
                            TextEntry::make('response_payload_formatted')
                                ->label('Response Payload')
                                ->state(fn (MessageAttempt $record): string => self::formatJsonValue($record->response_payload))
                                ->placeholder('-')
                                ->fontFamily(FontFamily::Mono)
                                ->copyable()
                                ->columnSpanFull(),
                            TextEntry::make('error_message')->label('رسالة الخطأ')->placeholder('-')->columnSpanFull(),
                            TextEntry::make('created_at')->label('تاريخ الإنشاء')->dateTime('Y-m-d h:i A')->placeholder('-'),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ]),
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
                TextColumn::make('sent_at')
                    ->label('وقت الإرسال')
                    ->dateTime('Y-m-d h:i A')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('failed_at')
                    ->label('وقت الفشل')
                    ->dateTime('Y-m-d h:i A')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('external_message_id')
                    ->label('رقم الرسالة الخارجي')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),
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
                Action::make('retry')
                    ->label('إعادة الإرسال')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->visible(fn (Message $record): bool => $record->status === Message::STATUS_FAILED)
                    ->requiresConfirmation()
                    ->modalDescription('هل تريد إعادة إرسال هذه الرسالة؟')
                    ->action(function (Message $record): void {
                        $returnedToPending = DB::transaction(function () use ($record): bool {
                            /** @var Message|null $message */
                            $message = Message::query()
                                ->whereKey($record->getKey())
                                ->lockForUpdate()
                                ->first();

                            if (! $message || $message->status !== Message::STATUS_FAILED) {
                                return false;
                            }

                            $message->forceFill([
                                'status' => Message::STATUS_PENDING,
                                'failed_at' => null,
                                'error_message' => null,
                                'sent_at' => null,
                                'external_message_id' => null,
                            ])->save();

                            return true;
                        });

                        if (! $returnedToPending) {
                            Notification::make()
                                ->title('لا يمكن إعادة إرسال هذه الرسالة لأن حالتها تغيرت.')
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('تمت إعادة الرسالة إلى قائمة الانتظار بنجاح.')
                            ->success()
                            ->send();
                    }),
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

    protected static function attemptStatusLabel(?string $state): string
    {
        return MessageAttempt::statusLabels()[$state] ?? ($state ?: '-');
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

    protected static function attemptStatusColor(?string $state): string | array
    {
        return match ($state) {
            MessageAttempt::STATUS_PENDING => 'warning',
            MessageAttempt::STATUS_QUEUED => 'info',
            MessageAttempt::STATUS_SENT => 'success',
            MessageAttempt::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    protected static function formatJsonValue(mixed $state): string
    {
        if ($state === null || $state === '') {
            return '-';
        }

        if (is_string($state)) {
            $decoded = json_decode($state, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-';
            }

            return $state;
        }

        if (is_array($state) || is_object($state)) {
            return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-';
        }

        return (string) $state;
    }
}