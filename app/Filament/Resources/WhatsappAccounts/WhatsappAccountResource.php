<?php

namespace App\Filament\Resources\WhatsappAccounts;

use App\Filament\Resources\WhatsappAccounts\Pages\CreateWhatsappAccount;
use App\Filament\Resources\WhatsappAccounts\Pages\EditWhatsappAccount;
use App\Filament\Resources\WhatsappAccounts\Pages\ListWhatsappAccounts;
use App\Filament\Resources\WhatsappAccounts\Pages\ViewWhatsappAccount;
use App\Models\WhatsappAccount;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
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
        return $schema->components([
            Select::make('client_id')
                ->label('العميل')
                ->relationship('client', 'name')
                ->searchable()
                ->preload(),
            TextInput::make('name')
                ->label('اسم الرقم')
                ->required()
                ->maxLength(255),
            TextInput::make('phone_number')
                ->label('رقم الهاتف')
                ->tel()
                ->maxLength(255),
            TextInput::make('session_name')
                ->label('اسم الجلسة')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            Select::make('status')
                ->label('الحالة')
                ->options(WhatsappAccount::statusLabels())
                ->default(WhatsappAccount::STATUS_DISCONNECTED)
                ->required(),
            TextInput::make('last_seen_at')
                ->label('آخر ظهور')
                ->placeholder('يُحدّث لاحقًا عبر المحرك')
                ->disabled()
                ->dehydrated(false),
            TextInput::make('qr_expires_at')
                ->label('انتهاء QR')
                ->placeholder('يُحدّث لاحقًا عبر المحرك')
                ->disabled()
                ->dehydrated(false),
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
            TextEntry::make('status')
                ->label('الحالة')
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
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('اسم الرقم')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone_number')
                    ->label('رقم الهاتف')
                    ->searchable(),
                TextColumn::make('session_name')
                    ->label('اسم الجلسة')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('status')
                    ->label('الحالة')
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
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('client_id')
                    ->label('العميل')
                    ->relationship('client', 'name'),
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(WhatsappAccount::statusLabels()),
                TernaryFilter::make('is_active')->label('نشط'),
            ])
            ->recordActions([
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
            'create' => CreateWhatsappAccount::route('/create'),
            'view' => ViewWhatsappAccount::route('/{record}'),
            'edit' => EditWhatsappAccount::route('/{record}/edit'),
        ];
    }

    protected static function statusLabel(?string $state): string
    {
        return WhatsappAccount::statusLabels()[$state] ?? ($state ?: '-');
    }
}