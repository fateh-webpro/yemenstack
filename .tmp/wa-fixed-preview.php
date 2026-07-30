<?php

namespace App\Filament\Resources\WhatsappAccounts;

use App\Filament\Resources\Messages\MessageResource;
use App\Filament\Resources\WhatsappAccounts\Pages\ListWhatsappAccounts;
use App\Filament\Resources\WhatsappAccounts\Pages\ViewWhatsappAccount;
use App\Models\WhatsappAccount;
use App\Models\WhatsappPairingToken;
use App\Services\Whatsapp\WhatsappPairingLinkService;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Js;
use UnitEnum;

class WhatsappAccountResource extends Resource
{
    protected static ?string $model = WhatsappAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'ط·آ¨ط¸ث†ط·آ§ط·آ¨ط·آ© ط·آ§ط¸â€‍ط¸ث†ط·آ§ط·ع¾ط·آ³ط·آ§ط·آ¨';

    public static function getNavigationLabel(): string
    {
        return 'ط·آ£ط·آ±ط¸â€ڑط·آ§ط¸â€¦ ط¸ث†ط·آ§ط·ع¾ط·آ³ط·آ§ط·آ¨';
    }

    public static function getModelLabel(): string
    {
        return 'ط·آ±ط¸â€ڑط¸â€¦ ط¸ث†ط·آ§ط·ع¾ط·آ³ط·آ§ط·آ¨';
    }

    public static function getPluralModelLabel(): string
    {
        return 'ط·آ£ط·آ±ط¸â€ڑط·آ§ط¸â€¦ ط¸ث†ط·آ§ط·ع¾ط·آ³ط·آ§ط·آ¨';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('client_id')
                    ->label('ط·آ§ط¸â€‍ط·آ¹ط¸â€¦ط¸ظ¹ط¸â€‍')
                    ->relationship('client', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('name')
                    ->label('ط·آ§ط·آ³ط¸â€¦ ط·آ§ط¸â€‍ط·آ­ط·آ³ط·آ§ط·آ¨')
                    ->required()
                    ->maxLength(255),
                TextInput::make('phone_number')
                    ->label('ط·آ±ط¸â€ڑط¸â€¦ ط·آ§ط¸â€‍ط¸â€،ط·آ§ط·ع¾ط¸ظ¾')
                    ->helperText('ط¸ظ¹ط¸عˆط¸ئ’ط·ع¾ط·آ¨ ط·آ§ط¸â€‍ط·آ±ط¸â€ڑط¸â€¦ ط·آ¨ط·آµط¸ظ¹ط·ط›ط·آ© ط·آ¯ط¸ث†ط¸â€‍ط¸ظ¹ط·آ© ط¸â€ڑط·آ¯ط·آ± ط·آ§ط¸â€‍ط·آ¥ط¸â€¦ط¸ئ’ط·آ§ط¸â€ ط·إ’ ط¸â€¦ط·آ«ط¸â€‍: 967777000000')
                    ->required()
                    ->tel()
                    ->maxLength(255),
                Placeholder::make('session_name_preview')
                    ->label('ط·آ§ط·آ³ط¸â€¦ ط·آ§ط¸â€‍ط·آ¬ط¸â€‍ط·آ³ط·آ©')
                    ->content(fn (?WhatsappAccount $record): string => $record?->session_name
                        ?? 'ط·آ³ط¸ظ¹ط¸عˆط¸ث†ط¸â€‍ط¸â€کط¸عکط·آ¯ ط·ع¾ط¸â€‍ط¸â€ڑط·آ§ط·آ¦ط¸ظ¹ط¸â€¹ط·آ§ ط·آ¹ط¸â€ ط·آ¯ ط·آ¥ط¸â€ ط·آ´ط·آ§ط·طŒ ط·آ§ط¸â€‍ط·آ­ط·آ³ط·آ§ط·آ¨ط·إ’ ط¸ث†ط¸â€‍ط·آ§ ط¸ظ¹ط¸عˆط·آ¹ط·آ¯ط¸â€کط¸عکط¸â€‍ ط¸ظ¹ط·آ¯ط¸ث†ط¸ظ¹ط¸â€¹ط·آ§.'),
                Select::make('status')
                    ->label('ط·آ§ط¸â€‍ط·آ­ط·آ§ط¸â€‍ط·آ© ط·آ§ط¸â€‍ط¸ظ¾ط·آ¹ط¸â€‍ط¸ظ¹ط·آ©')
                    ->options(WhatsappAccount::statusLabels())
                    ->default(WhatsappAccount::STATUS_DISCONNECTED)
                    ->required(),
                Toggle::make('is_active')
                    ->label('ط¸â€ ط·آ´ط·آ·')
                    ->default(true),
                Textarea::make('notes')
                    ->label('ط¸â€¦ط¸â€‍ط·آ§ط·آ­ط·آ¸ط·آ§ط·ع¾')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('client.name')->label('ط·آ§ط¸â€‍ط·آ¹ط¸â€¦ط¸ظ¹ط¸â€‍')->placeholder('-'),
            TextEntry::make('name')->label('ط·آ§ط·آ³ط¸â€¦ ط·آ§ط¸â€‍ط·آ­ط·آ³ط·آ§ط·آ¨'),
            TextEntry::make('phone_number')->label('ط·آ±ط¸â€ڑط¸â€¦ ط·آ§ط¸â€‍ط¸â€،ط·آ§ط·ع¾ط¸ظ¾')->placeholder('-'),
            TextEntry::make('session_name')->label('ط·آ§ط·آ³ط¸â€¦ ط·آ§ط¸â€‍ط·آ¬ط¸â€‍ط·آ³ط·آ©'),
            TextEntry::make('session_desired_state')
                ->label('ط·آ­ط·آ§ط¸â€‍ط·آ© ط·آ§ط¸â€‍ط·ع¾ط·آ´ط·ط›ط¸ظ¹ط¸â€‍ ط·آ§ط¸â€‍ط¸â€¦ط·آ·ط¸â€‍ط¸ث†ط·آ¨ط·آ©')
                ->badge()
                ->color(fn (?string $state): string => self::desiredStateColor($state))
                ->formatStateUsing(fn (?string $state): string => self::desiredStateLabelForDisplay($state)),
            TextEntry::make('automatic_sending_enabled')
                ->label('ظˆط¶ط¹ ط§ظ„ط¥ط±ط³ط§ظ„')
                ->badge()
                ->color(fn (?bool $state): string => self::sendingModeColor($state))
                ->formatStateUsing(fn (?bool $state): string => self::sendingModeLabel($state)),
            TextEntry::make('status')
                ->label('ط·آ§ط¸â€‍ط·آ­ط·آ§ط¸â€‍ط·آ© ط·آ§ط¸â€‍ط¸ظ¾ط·آ¹ط¸â€‍ط¸ظ¹ط·آ©')
                ->badge()
                ->color(fn (?string $state): string => self::statusColor($state))
                ->formatStateUsing(fn (?string $state): string => self::statusLabelForDisplay($state)),
            TextEntry::make('pairing_link_status')
                ->label('ط·آ­ط·آ§ط¸â€‍ط·آ© ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ§ط¸â€‍ط·آ±ط·آ¨ط·آ·')
                ->badge()
                ->color(fn (WhatsappAccount $record): string => self::pairingStatusColor(self::pairingStatusKey($record)))
                ->state(fn (WhatsappAccount $record): string => self::pairingStatusLabelFromKey(self::pairingStatusKey($record))),
            TextEntry::make('latestPairingToken.expires_at')
                ->label('ط·آ§ط¸â€ ط·ع¾ط¸â€،ط·آ§ط·طŒ ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ§ط¸â€‍ط·آ±ط·آ¨ط·آ·')
                ->dateTime('Y-m-d h:i A')
                ->placeholder('-'),
            TextEntry::make('latestPairingToken.createdBy.name')
                ->label('ط·آ£ط¸â€ ط·آ´ط·آ£ ط·آ§ط¸â€‍ط·آ±ط·آ§ط·آ¨ط·آ·')
                ->placeholder('-'),
            TextEntry::make('pairing_link_notice')
                ->label('ط¸â€¦ط·آ¹ط¸â€‍ط¸ث†ط¸â€¦ط·آ© ط¸â€¦ط¸â€،ط¸â€¦ط·آ©')
                ->state('ط¸ظ¹ط·آ¸ط¸â€،ط·آ± ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ§ط¸â€‍ط·آ±ط·آ¨ط·آ· ط¸â€¦ط·آ±ط·آ© ط¸ث†ط·آ§ط·آ­ط·آ¯ط·آ© ط¸ظ¾ط¸â€ڑط·آ· ط·آ¹ط¸â€ ط·آ¯ ط·آ¥ط¸â€ ط·آ´ط·آ§ط·آ¦ط¸â€،. ط·آ¹ط¸â€ ط·آ¯ ط¸ظ¾ط¸â€ڑط·آ¯ط·آ§ط¸â€ ط¸â€، ط¸ظ¹ط·آ¬ط·آ¨ ط·آ¥ط¸â€ ط·آ´ط·آ§ط·طŒ ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ¬ط·آ¯ط¸ظ¹ط·آ¯.')
                ->columnSpanFull(),
            IconEntry::make('is_active')->label('ط¸â€ ط·آ´ط·آ·')->boolean(),
            TextEntry::make('last_seen_at')->label('ط·آ¢ط·آ®ط·آ± ط·آ¸ط¸â€،ط¸ث†ط·آ±')->dateTime('Y-m-d h:i A')->placeholder('-'),
            TextEntry::make('qr_expires_at')->label('ط·آ§ط¸â€ ط·ع¾ط¸â€،ط·آ§ط·طŒ QR')->dateTime('Y-m-d h:i A')->placeholder('-'),
            TextEntry::make('notes')->label('ط¸â€¦ط¸â€‍ط·آ§ط·آ­ط·آ¸ط·آ§ط·ع¾')->placeholder('-')->columnSpanFull(),
            TextEntry::make('created_at')->label('ط·ع¾ط·آ§ط·آ±ط¸ظ¹ط·آ® ط·آ§ط¸â€‍ط·آ¥ط¸â€ ط·آ´ط·آ§ط·طŒ')->dateTime('Y-m-d h:i A'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['client', 'latestPairingToken.createdBy']))
            ->columns([
                TextColumn::make('client.name')
                    ->label('ط·آ§ط¸â€‍ط·آ¹ط¸â€¦ط¸ظ¹ط¸â€‍')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('ط·آ§ط·آ³ط¸â€¦ ط·آ§ط¸â€‍ط·آ­ط·آ³ط·آ§ط·آ¨')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone_number')
                    ->label('ط·آ±ط¸â€ڑط¸â€¦ ط·آ§ط¸â€‍ط¸â€،ط·آ§ط·ع¾ط¸ظ¾')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('session_name')
                    ->label('ط·آ§ط·آ³ط¸â€¦ ط·آ§ط¸â€‍ط·آ¬ط¸â€‍ط·آ³ط·آ©')
                    ->searchable()
                    ->toggleable()
                    ->copyable()
                    ->copyMessage('ط·ع¾ط¸â€¦ ط¸â€ ط·آ³ط·آ® ط·آ§ط·آ³ط¸â€¦ ط·آ§ط¸â€‍ط·آ¬ط¸â€‍ط·آ³ط·آ©'),
                TextColumn::make('pairing_link_status')
                    ->label('ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ§ط¸â€‍ط·آ±ط·آ¨ط·آ·')
                    ->badge()
                    ->state(fn (WhatsappAccount $record): string => self::pairingStatusLabelFromKey(self::pairingStatusKey($record)))
                    ->color(fn (WhatsappAccount $record): string => self::pairingStatusColor(self::pairingStatusKey($record))),
                TextColumn::make('latestPairingToken.expires_at')
                    ->label('ط·آ§ط¸â€ ط·ع¾ط¸â€،ط·آ§ط·طŒ ط·آ§ط¸â€‍ط·آ±ط·آ§ط·آ¨ط·آ·')
                    ->dateTime('Y-m-d h:i A')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('session_desired_state')
                    ->label('ط·آ§ط¸â€‍ط·ع¾ط·آ´ط·ط›ط¸ظ¹ط¸â€‍ ط·آ§ط¸â€‍ط¸â€¦ط·آ·ط¸â€‍ط¸ث†ط·آ¨')
                    ->badge()
                    ->color(fn (?string $state): string => self::desiredStateColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::desiredStateLabelForDisplay($state))
                    ->sortable(),
                TextColumn::make('automatic_sending_enabled')
                    ->label('ظˆط¶ط¹ ط§ظ„ط¥ط±ط³ط§ظ„')
                    ->badge()
                    ->color(fn (?bool $state): string => self::sendingModeColor($state))
                    ->formatStateUsing(fn (?bool $state): string => self::sendingModeLabel($state))
                    ->sortable(),
                TextColumn::make('status')
                    ->label('ط·آ§ط¸â€‍ط·آ­ط·آ§ط¸â€‍ط·آ© ط·آ§ط¸â€‍ط¸ظ¾ط·آ¹ط¸â€‍ط¸ظ¹ط·آ©')
                    ->badge()
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::statusLabelForDisplay($state))
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('ط¸â€ ط·آ´ط·آ·')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('ط·آ¢ط·آ®ط·آ± ط·آ¸ط¸â€،ط¸ث†ط·آ±')
                    ->since()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('ط·ع¾ط·آ§ط·آ±ط¸ظ¹ط·آ® ط·آ§ط¸â€‍ط·آ¥ط¸â€ ط·آ´ط·آ§ط·طŒ')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('client_id')
                    ->label('ط·آ§ط¸â€‍ط·آ¹ط¸â€¦ط¸ظ¹ط¸â€‍')
                    ->relationship('client', 'name'),
                SelectFilter::make('session_desired_state')
                    ->label('ط·آ§ط¸â€‍ط·ع¾ط·آ´ط·ط›ط¸ظ¹ط¸â€‍ ط·آ§ط¸â€‍ط¸â€¦ط·آ·ط¸â€‍ط¸ث†ط·آ¨')
                    ->options(WhatsappAccount::desiredStateLabels()),
                SelectFilter::make('status')
                    ->label('ط·آ§ط¸â€‍ط·آ­ط·آ§ط¸â€‍ط·آ© ط·آ§ط¸â€‍ط¸ظ¾ط·آ¹ط¸â€‍ط¸ظ¹ط·آ©')
                    ->options(WhatsappAccount::statusLabels()),
                TernaryFilter::make('is_active')->label('ط¸â€ ط·آ´ط·آ·'),
            ])
            ->recordActions([
                self::makeEnableAutomaticSendingAction(),
                self::makeDisableAutomaticSendingAction(),
                self::makeViewMessagesAction(),
                self::makeGeneratePairingTokenAction(),
                self::makeRevokePairingTokenAction(),
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

    public static function makeEnableAutomaticSendingAction(): Action
    {
        return Action::make('enable_automatic_sending')
            ->label('طھط´ط؛ظٹظ„ ط§ظ„ط¥ط±ط³ط§ظ„ ط§ظ„طھظ„ظ‚ط§ط¦ظٹ')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->visible(fn (WhatsappAccount $record): bool => $record->is_active && (bool) $record->client?->is_active && ! $record->automaticSendingEnabled())
            ->requiresConfirmation()
            ->modalDescription('ط³ظٹط¨ط¯ط£ ط§ظ„ظ†ط¸ط§ظ… ط¨ط¥ط±ط³ط§ظ„ ط§ظ„ط±ط³ط§ط¦ظ„ ط§ظ„ظ…ط¹ظ„ظ‚ط© ظ„ظ‡ط°ط§ ط§ظ„ط­ط³ط§ط¨ طھظ„ظ‚ط§ط¦ظٹظ‹ط§طŒ ط¨ظپط§طµظ„ ط¹ط´ظˆط§ط¦ظٹ ظ…ظ† 15 ط¥ظ„ظ‰ 30 ط«ط§ظ†ظٹط© ط¨ظٹظ† ظƒظ„ ط±ط³ط§ظ„ط© ظˆط£ط®ط±ظ‰.')
            ->action(function (WhatsappAccount $record): void {
                if (! $record->is_active || ! $record->client?->is_active) {
                    Notification::make()
                        ->title('ظ„ط§ ظٹظ…ظƒظ† طھط´ط؛ظٹظ„ ط§ظ„ط¥ط±ط³ط§ظ„ ط§ظ„طھظ„ظ‚ط§ط¦ظٹ ظ„ط£ظ† ط§ظ„ط­ط³ط§ط¨ ط؛ظٹط± ظ†ط´ط·.')
                        ->warning()
                        ->send();

                    return;
                }

                $record->forceFill(['automatic_sending_enabled' => true])->save();

                Notification::make()
                    ->success()
                    ->title('طھظ… طھط´ط؛ظٹظ„ ط§ظ„ط¥ط±ط³ط§ظ„ ط§ظ„طھظ„ظ‚ط§ط¦ظٹ ظ„ظ‡ط°ط§ ط§ظ„ط­ط³ط§ط¨.')
                    ->body('ط³ظٹطھظ… ط¥ط±ط³ط§ظ„ ط§ظ„ط±ط³ط§ط¦ظ„ ط¨ظپط§طµظ„ ط¹ط´ظˆط§ط¦ظٹ ظ…ظ† 15 ط¥ظ„ظ‰ 30 ط«ط§ظ†ظٹط©.')
                    ->send();
            });
    }

    public static function makeDisableAutomaticSendingAction(): Action
    {
        return Action::make('disable_automatic_sending')
            ->label('ط¥ظٹظ‚ط§ظپ ط§ظ„ط¥ط±ط³ط§ظ„ ط§ظ„طھظ„ظ‚ط§ط¦ظٹ')
            ->icon(Heroicon::OutlinedPause)
            ->color('warning')
            ->visible(fn (WhatsappAccount $record): bool => $record->automaticSendingEnabled())
            ->requiresConfirmation()
            ->modalDescription('ط³ظٹطھظˆظ‚ظپ ط§ظ„ط¥ط±ط³ط§ظ„ ط§ظ„طھظ„ظ‚ط§ط¦ظٹ ط¨ط¹ط¯ ط§ظ†طھظ‡ط§ط، ط§ظ„ظ…ط­ط§ظˆظ„ط© ط§ظ„ط­ط§ظ„ظٹط©طŒ ظˆط³طھط¨ظ‚ظ‰ ط¨ظ‚ظٹط© ط§ظ„ط±ط³ط§ط¦ظ„ ظ…ط¹ظ„ظ‚ط© ظ„ظ„ط¥ط±ط³ط§ظ„ ط§ظ„ظٹط¯ظˆظٹ ط£ظˆ ظ„ظ„طھط´ط؛ظٹظ„ ظ„ط§ط­ظ‚ظ‹ط§.')
            ->action(function (WhatsappAccount $record): void {
                $record->forceFill(['automatic_sending_enabled' => false])->save();

                Notification::make()
                    ->success()
                    ->title('طھظ… ط¥ظٹظ‚ط§ظپ ط§ظ„ط¥ط±ط³ط§ظ„ ط§ظ„طھظ„ظ‚ط§ط¦ظٹ ظ„ظ‡ط°ط§ ط§ظ„ط­ط³ط§ط¨.')
                    ->body('ط³طھط¨ظ‚ظ‰ ط§ظ„ط±ط³ط§ط¦ظ„ ط؛ظٹط± ط§ظ„ظ…ط±ط³ظ„ط© ظ…ط¹ظ„ظ‚ط©طŒ ظˆظٹظ…ظƒظ† ط¥ط±ط³ط§ظ„ظ‡ط§ ظٹط¯ظˆظٹظ‹ط§.')
                    ->send();
            });
    }

    public static function makeViewMessagesAction(): Action
    {
        return Action::make('view_messages')
            ->label('ط¹ط±ط¶ ط§ظ„ط±ط³ط§ط¦ظ„')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->url(fn (WhatsappAccount $record): string => MessageResource::getUrl('index', ['whatsapp_account_id' => $record->getKey()]));
    }

    public static function makeGeneratePairingTokenAction(): Action
    {
        return Action::make('generate_pairing_token')
            ->label('ط·ع¾ط¸ث†ط¸â€‍ط¸ظ¹ط·آ¯ ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ§ط¸â€‍ط·آ±ط·آ¨ط·آ·')
            ->icon(Heroicon::OutlinedKey)
            ->color('success')
            ->visible(fn (WhatsappAccount $record): bool => $record->is_active && (bool) $record->client?->is_active)
            ->schema([
                TextInput::make('expires_in_minutes')
                    ->label('ط¸â€¦ط·آ¯ط·آ© ط·آ§ط¸â€‍ط·آµط¸â€‍ط·آ§ط·آ­ط¸ظ¹ط·آ© ط·آ¨ط·آ§ط¸â€‍ط·آ¯ط¸â€ڑط·آ§ط·آ¦ط¸â€ڑ')
                    ->helperText('ط·آ§ط¸â€‍ط·آ±ط·آ§ط·آ¨ط·آ· ط¸â€¦ط·آ¤ط¸â€ڑط·ع¾ ط¸ث†ط¸ظ¹ط¸عˆط·آ³ط·ع¾ط·آ®ط·آ¯ط¸â€¦ ط¸â€¦ط·آ±ط·آ© ط¸ث†ط·آ§ط·آ­ط·آ¯ط·آ© ط¸ظ¾ط¸â€ڑط·آ·. ط·آ¥ط·آµط·آ¯ط·آ§ط·آ± ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ¬ط·آ¯ط¸ظ¹ط·آ¯ ط¸ظ¹ط¸عˆط¸â€‍ط·ط›ط¸ظ¹ ط·آ§ط¸â€‍ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ§ط¸â€‍ط·آ³ط·آ§ط·آ¨ط¸â€ڑ ط¸â€‍ط¸â€‍ط·آ­ط·آ³ط·آ§ط·آ¨ ط¸â€ ط¸ظ¾ط·آ³ط¸â€،.')
                    ->numeric()
                    ->minValue(5)
                    ->maxValue(1440)
                    ->default(15)
                    ->required(),
            ])
            ->modalDescription('ط·آ³ط¸ظ¹ط·آ¸ط¸â€،ط·آ± ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ§ط¸â€‍ط·آ±ط·آ¨ط·آ· ط¸â€¦ط·آ±ط·آ© ط¸ث†ط·آ§ط·آ­ط·آ¯ط·آ© ط¸ظ¾ط¸â€ڑط·آ· ط·آ¨ط·آ¹ط·آ¯ ط·آ§ط¸â€‍ط·آ¥ط¸â€ ط·آ´ط·آ§ط·طŒط·إ’ ط¸ث†ط¸â€‍ط¸â€  ط¸ظ¹ط¸â€¦ط¸ئ’ط¸â€  ط·آ§ط·آ³ط·ع¾ط·آ¹ط·آ§ط·آ¯ط·ع¾ط¸â€، ط¸â€‍ط·آ§ط·آ­ط¸â€ڑط¸â€¹ط·آ§ ط¸â€¦ط¸â€  ط·آ§ط¸â€‍ط¸â€‍ط¸ث†ط·آ­ط·آ©.')
            ->action(function (WhatsappAccount $record, array $data, WhatsappPairingLinkService $pairingLinkService): void {
                $result = $pairingLinkService->issueLink(
                    $record,
                    (int) $data['expires_in_minutes'],
                    Auth::id(),
                );

                $pairingUrl = $result['pairing_url'];
                $pairingToken = $result['pairing_token'];
                $clipboardScript = '(async () => { if (window.navigator?.clipboard) { await window.navigator.clipboard.writeText(' . Js::from($pairingUrl) . '); } })()';

                Notification::make()
                    ->success()
                    ->persistent()
                    ->title('ط·ع¾ط¸â€¦ ط·ع¾ط¸ث†ط¸â€‍ط¸ظ¹ط·آ¯ ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ§ط¸â€‍ط·آ±ط·آ¨ط·آ·')
                    ->body("���� ���� ����� ��� ����� ��ء ���� ������ ��� ����� ���� ����.\n\n{$pairingUrl}\n\n����� ��: {$pairingToken->expires_at?->format('Y-m-d H:i:s')}")
                    ->actions([
                        Action::make('copy_pairing_link')
                            ->label('ط¸â€ ط·آ³ط·آ® ط·آ§ط¸â€‍ط·آ±ط·آ§ط·آ¨ط·آ·')
                            ->color('success')
                            ->alpineClickHandler($clipboardScript)
                            ->close(false),
                        Action::make('open_pairing_link')
                            ->label('ط¸ظ¾ط·ع¾ط·آ­ ط·آµط¸ظ¾ط·آ­ط·آ© ط·آ§ط¸â€‍ط·آ±ط·آ¨ط·آ·')
                            ->color('gray')
                            ->url($pairingUrl, shouldOpenInNewTab: true),
                    ])
                    ->send();
            });
    }

    public static function makeRevokePairingTokenAction(): Action
    {
        return Action::make('revoke_pairing_token')
            ->label('ط·آ¥ط¸â€‍ط·ط›ط·آ§ط·طŒ ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ§ط¸â€‍ط·آ±ط·آ¨ط·آ·')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (WhatsappAccount $record): bool => $record->pairingTokens()->usable()->exists())
            ->requiresConfirmation()
            ->modalDescription('ط·آ³ط¸ظ¹ط¸عˆط¸â€‍ط·ط›ط¸â€° ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ§ط¸â€‍ط·آ±ط·آ¨ط·آ· ط·آ§ط¸â€‍ط·آ­ط·آ§ط¸â€‍ط¸ظ¹ ط¸ظ¾ط¸ث†ط·آ±ط¸â€¹ط·آ§ ط·آ¯ط¸ث†ط¸â€  ط·آ­ط·آ°ط¸ظ¾ ط·آ§ط¸â€‍ط·آ³ط·آ¬ط¸â€‍ط·إ’ ط¸ث†ط·آ¯ط¸ث†ط¸â€  ط¸ظ¾ط·آµط¸â€‍ ط·آ¬ط¸â€‍ط·آ³ط·آ© ط¸ث†ط·آ§ط·ع¾ط·آ³ط·آ§ط·آ¨ ط·آ§ط¸â€‍ط¸â€¦ط·ع¾ط·آµط¸â€‍ط·آ© ط·آ¥ط¸â€  ط¸ث†ط¸عˆط·آ¬ط·آ¯ط·ع¾.')
            ->action(function (WhatsappAccount $record, WhatsappPairingLinkService $pairingLinkService): void {
                $statusBefore = $record->status;
                $desiredStateBefore = $record->session_desired_state;
                $revokedCount = $pairingLinkService->revokeLink($record);
                $record->refresh();

                Notification::make()
                    ->success()
                    ->title('ط·ع¾ط¸â€¦ ط·آ¥ط¸â€‍ط·ط›ط·آ§ط·طŒ ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ§ط¸â€‍ط·آ±ط·آ¨ط·آ·')
                    ->body($revokedCount > 0
                        ? 'ط·ع¾ط¸ث†ط¸â€ڑط¸ظ¾ ط·آ§ط¸â€‍ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ§ط¸â€‍ط·آ­ط·آ§ط¸â€‍ط¸ظ¹ ط¸â€‍ط¸â€،ط·آ°ط·آ§ ط·آ§ط¸â€‍ط·آ­ط·آ³ط·آ§ط·آ¨ ط¸ظ¾ط¸ث†ط·آ±ط¸â€¹ط·آ§ط·إ’ ط¸ث†ط¸ظ¹ط¸â€¦ط¸ئ’ط¸â€  ط·آ¥ط¸â€ ط·آ´ط·آ§ط·طŒ ط·آ±ط·آ§ط·آ¨ط·آ· ط·آ¬ط·آ¯ط¸ظ¹ط·آ¯ ط·آ¹ط¸â€ ط·آ¯ ط·آ§ط¸â€‍ط·آ­ط·آ§ط·آ¬ط·آ©.'
                        : 'ط¸â€‍ط·آ§ ط¸ظ¹ط¸ث†ط·آ¬ط·آ¯ ط·آ±ط·آ§ط·آ¨ط·آ· ط·آµط·آ§ط¸â€‍ط·آ­ ط·آ­ط·آ§ط¸â€‍ط¸ظ¹ط¸â€¹ط·آ§ ط¸â€‍ط·آ¥ط¸â€‍ط·ط›ط·آ§ط·آ¦ط¸â€،.')
                    ->send();

                if ($record->status !== $statusBefore || $record->session_desired_state !== $desiredStateBefore) {
                    $record->forceFill([
                        'status' => $statusBefore,
                        'session_desired_state' => $desiredStateBefore,
                    ])->saveQuietly();
                }
            });
    }

    public static function statusLabelForDisplay(?string $state): string
    {
        return match ($state) {
            'pending' => 'قيد الإعداد',
            WhatsappAccount::STATUS_CONNECTING => 'جارٍ الاتصال',
            WhatsappAccount::STATUS_QR_REQUIRED => 'بانتظار مسح رمز QR',
            WhatsappAccount::STATUS_AUTHENTICATED => 'تم التحقق من الحساب',
            WhatsappAccount::STATUS_CONNECTED => 'متصل',
            WhatsappAccount::STATUS_DISCONNECTED => 'غير متصل',
            WhatsappAccount::STATUS_ERROR => 'خطأ في الاتصال',
            WhatsappAccount::STATUS_LOGGED_OUT => 'تم تسجيل الخروج',
            'stopped' => 'متوقف',
            default => 'غير معروف',
        };
    }

    public static function desiredStateLabelForDisplay(?string $state): string
    {
        return match ($state) {
            WhatsappAccount::SESSION_DESIRED_RUNNING => 'مطلوب التشغيل',
            WhatsappAccount::SESSION_DESIRED_STOPPED => 'متوقف إداريًا',
            default => 'غير معروف',
        };
    }

    public static function pairingStatusKey(WhatsappAccount $record): string
    {
        $token = $record->latestPairingToken;

        if (! $token instanceof WhatsappPairingToken) {
            return 'none';
        }

        if ($token->used_at !== null) {
            return 'used';
        }

        if ($token->revoked_at !== null) {
            return 'revoked';
        }

        if ($token->expires_at?->isPast()) {
            return 'expired';
        }

        return 'usable';
    }

    public static function pairingStatusLabelFromKey(string $status): string
    {
        return match ($status) {
            'usable' => 'صالح',
            'used' => 'مستخدم',
            'revoked' => 'ملغى',
            'expired' => 'منتهي',
            default => 'لا يوجد رابط',
        };
    }

    public static function sendingModeLabel(?bool $state): string
    {
        return $state
            ? 'طھظ„ظ‚ط§ط¦ظٹ - ظ…ظ† 15 ط¥ظ„ظ‰ 30 ط«ط§ظ†ظٹط©'
            : 'ظٹط¯ظˆظٹ';
    }

    protected static function sendingModeColor(?bool $state): string
    {
        return $state ? 'success' : 'gray';
    }

    protected static function statusColor(?string $state): string
    {
        return match ($state) {
            WhatsappAccount::STATUS_CONNECTED => 'success',
            WhatsappAccount::STATUS_QR_REQUIRED => 'warning',
            WhatsappAccount::STATUS_CONNECTING,
            WhatsappAccount::STATUS_AUTHENTICATED => 'info',
            WhatsappAccount::STATUS_ERROR,
            WhatsappAccount::STATUS_LOGGED_OUT => 'danger',
            WhatsappAccount::STATUS_DISCONNECTED => 'gray',
            default => 'gray',
        };
    }

    protected static function desiredStateColor(?string $state): string
    {
        return match ($state) {
            WhatsappAccount::SESSION_DESIRED_RUNNING => 'success',
            WhatsappAccount::SESSION_DESIRED_STOPPED => 'gray',
            default => 'gray',
        };
    }

    protected static function pairingStatusColor(string $status): string
    {
        return match ($status) {
            'usable' => 'success',
            'used' => 'info',
            'revoked' => 'danger',
            'expired' => 'warning',
            default => 'gray',
        };
    }
}