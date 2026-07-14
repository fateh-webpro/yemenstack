<?php

namespace App\Filament\Resources\ApiCredentials;

use App\Filament\Resources\ApiCredentials\Pages\CreateApiCredential;
use App\Filament\Resources\ApiCredentials\Pages\EditApiCredential;
use App\Filament\Resources\ApiCredentials\Pages\ListApiCredentials;
use App\Filament\Resources\ApiCredentials\Pages\ViewApiCredential;
use App\Models\ApiCredential;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
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

class ApiCredentialResource extends Resource
{
    protected static ?string $model = ApiCredential::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'بوابة الواتساب';

    public static function getNavigationLabel(): string
    {
        return 'مفاتيح API';
    }

    public static function getModelLabel(): string
    {
        return 'مفتاح API';
    }

    public static function getPluralModelLabel(): string
    {
        return 'مفاتيح API';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('client_id')
                ->label('العميل')
                ->relationship('client', 'name')
                ->searchable()
                ->preload(),
            Select::make('whatsapp_account_id')
                ->label('رقم واتساب')
                ->relationship('whatsappAccount', 'name')
                ->searchable()
                ->preload(),
            TextInput::make('name')
                ->label('اسم المفتاح')
                ->required()
                ->maxLength(255),
            TextInput::make('token_hash')
                ->label('بصمة المفتاح')
                ->required()
                ->password()
                ->revealable()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            KeyValue::make('abilities')
                ->label('الصلاحيات')
                ->keyLabel('المفتاح')
                ->valueLabel('القيمة')
                ->columnSpanFull(),
            TextInput::make('last_used_at')
                ->label('آخر استخدام')
                ->placeholder('يُحدّث لاحقًا')
                ->disabled()
                ->dehydrated(false),
            DateTimePicker::make('expires_at')
                ->label('تاريخ الانتهاء')
                ->seconds(false),
            Toggle::make('is_active')
                ->label('نشط')
                ->default(true),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('client.name')->label('العميل')->placeholder('-'),
            TextEntry::make('whatsappAccount.name')->label('رقم واتساب')->placeholder('-'),
            TextEntry::make('name')->label('اسم المفتاح'),
            TextEntry::make('token_hash')
                ->label('بصمة المفتاح')
                ->formatStateUsing(fn (?string $state): string => self::maskTokenHash($state)),
            KeyValueEntry::make('abilities')->label('الصلاحيات')->columnSpanFull(),
            IconEntry::make('is_active')->label('نشط')->boolean(),
            TextEntry::make('last_used_at')->label('آخر استخدام')->dateTime('Y-m-d h:i A')->placeholder('-'),
            TextEntry::make('expires_at')->label('تاريخ الانتهاء')->dateTime('Y-m-d h:i A')->placeholder('-'),
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
                TextColumn::make('name')
                    ->label('اسم المفتاح')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('token_hash')
                    ->label('بصمة المفتاح')
                    ->formatStateUsing(fn (?string $state): string => self::maskTokenHash($state))
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('last_used_at')
                    ->label('آخر استخدام')
                    ->since()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('تاريخ الانتهاء')
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
                SelectFilter::make('whatsapp_account_id')
                    ->label('رقم واتساب')
                    ->relationship('whatsappAccount', 'name'),
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
            'index' => ListApiCredentials::route('/'),
            'create' => CreateApiCredential::route('/create'),
            'view' => ViewApiCredential::route('/{record}'),
            'edit' => EditApiCredential::route('/{record}/edit'),
        ];
    }

    protected static function maskTokenHash(?string $state): string
    {
        if (blank($state)) {
            return '-';
        }

        return substr($state, 0, 8) . '...';
    }
}