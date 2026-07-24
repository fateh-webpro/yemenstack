<?php

namespace App\Filament\Resources\WhatsappAccounts;

use App\Filament\Resources\WhatsappAccounts\Pages\ListWhatsappAccounts;
use App\Filament\Resources\WhatsappAccounts\Pages\ViewWhatsappAccount;
use App\Models\WhatsappAccount;
use App\Models\WhatsappPairingToken;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class WhatsappAccountResource extends Resource
{
    protected static ?string $model = WhatsappAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'بوابة الواتساب';

    public static function getNavigationLabel(): string
    {
        return 'أرقام واتساب';
    }

    public static function getModelLabel(): string
    {
        return 'رقم واتساب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أرقام واتساب';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('client_id')
                    ->label('العميل')
                    ->relationship('client', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('name')
                    ->label('اسم الرقم')
                    ->required()
                    ->maxLength(255),
                TextInput::make('phone_number')
                    ->label('رقم الهاتف')
                    ->helperText('اكتب الرقم بصيغة دولية بدون مسافات قدر الإمكان، مثال: 967777000000')
                    ->required()
                    ->tel()
                    ->maxLength(255),
                Placeholder::make('session_name_preview')
                    ->label('اسم الجلسة')
                    ->content(fn (?WhatsappAccount $record): string => $record?->session_name
                        ?? 'سيتم توليده تلقائيًا عند إنشاء الحساب، ولا يمكن تعديله يدويًا.'),
                Select::make('status')
                    ->label('الحالة')
                    ->options(WhatsappAccount::statusLabels())
                    ->default(WhatsappAccount::STATUS_DISCONNECTED)
                    ->required(),
                Toggle::make('is_active')
                    ->label('نشط')
                    ->default(true),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('client.name')->label('العميل')->placeholder('-'),
            TextEntry::make('name')->label('اسم الرقم'),
            TextEntry::make('phone_number')->label('رقم الهاتف')->placeholder('-'),
            TextEntry::make('session_name')->label('اسم الجلسة'),
            TextEntry::make('session_desired_state')
                ->label('حالة التشغيل المطلوبة')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => self::desiredStateLabel($state)),
            TextEntry::make('status')
                ->label('الحالة الفعلية')
                ->badge()
                ->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
            IconEntry::make('is_active')->label('نشط')->boolean(),
            TextEntry::make('last_seen_at')->label('آخر ظهور')->dateTime('Y-m-d h:i A')->placeholder('-'),
            TextEntry::make('qr_expires_at')->label('انتهاء QR')->dateTime('Y-m-d h:i A')->placeholder('-'),
            TextEntry::make('notes')->label('ملاحظات')->placeholder('-')->columnSpanFull(),
            TextEntry::make('created_at')->label('تاريخ الإنشاء')->dateTime('Y-m-d h:i A'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client.name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('اسم الرقم')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone_number')
                    ->label('رقم الهاتف')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('session_name')
                    ->label('اسم الجلسة')
                    ->searchable()
                    ->toggleable()
                    ->copyable(),
                TextColumn::make('session_desired_state')
                    ->label('حالة التشغيل المطلوبة')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::desiredStateLabel($state))
                    ->sortable(),
                TextColumn::make('status')
                    ->label('الحالة الفعلية')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('آخر ظهور')
                    ->since()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('client_id')
                    ->label('العميل')
                    ->relationship('client', 'name'),
                SelectFilter::make('session_desired_state')
                    ->label('حالة التشغيل المطلوبة')
                    ->options(WhatsappAccount::desiredStateLabels()),
                SelectFilter::make('status')
                    ->label('الحالة الفعلية')
                    ->options(WhatsappAccount::statusLabels()),
                TernaryFilter::make('is_active')->label('نشط'),
            ])
            ->recordActions([
                self::generatePairingTokenAction(),
                self::revokePairingTokenAction(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsappAccounts::route('/'),
            'view' => ViewWhatsappAccount::route('/{record}'),
        ];
    }

    protected static function statusLabel(?string $state): string
    {
        return WhatsappAccount::statusLabels()[$state] ?? ($state ?: '-');
    }

    protected static function desiredStateLabel(?string $state): string
    {
        return WhatsappAccount::desiredStateLabels()[$state] ?? ($state ?: '-');
    }

    protected static function generatePairingTokenAction(): Action
    {
        return Action::make('generate_pairing_token')
            ->label('توليد رمز الربط')
            ->icon(Heroicon::OutlinedKey)
            ->color('success')
            ->visible(fn (WhatsappAccount $record): bool => $record->is_active && (bool) $record->client?->is_active)
            ->schema([
                TextInput::make('expires_in_minutes')
                    ->label('مدة الصلاحية بالدقائق')
                    ->numeric()
                    ->minValue(5)
                    ->maxValue(1440)
                    ->default(30)
                    ->required(),
            ])
            ->action(function (WhatsappAccount $record, array $data): void {
                [$plainToken, $pairingToken] = WhatsappPairingToken::issueForWhatsappAccount(
                    $record,
                    now()->addMinutes((int) $data['expires_in_minutes']),
                    Auth::id(),
                    ['source' => 'filament']
                );

                Notification::make()
                    ->success()
                    ->persistent()
                    ->title('تم توليد رمز الربط')
                    ->body("انسخ هذا الرمز الآن، ولن يظهر مرة أخرى:\n{$plainToken}\n\nينتهي في: {$pairingToken->expires_at?->format('Y-m-d H:i:s')}")
                    ->send();
            });
    }

    protected static function revokePairingTokenAction(): Action
    {
        return Action::make('revoke_pairing_token')
            ->label('إلغاء رمز الربط')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (WhatsappAccount $record): bool => $record->pairingTokens()->usable()->exists())
            ->requiresConfirmation()
            ->modalDescription('سيتم إلغاء أي رمز ربط صالح لهذا الحساب دون حذف السجل من قاعدة البيانات.')
            ->action(function (WhatsappAccount $record): void {
                $revokedCount = WhatsappPairingToken::revokeUsableForWhatsappAccount($record);

                Notification::make()
                    ->success()
                    ->title('تم إلغاء رمز الربط')
                    ->body($revokedCount > 0 ? 'تم إلغاء الرموز الصالحة الحالية لهذا الحساب.' : 'لا يوجد رمز صالح لإلغائه.')
                    ->send();
            });
    }
}