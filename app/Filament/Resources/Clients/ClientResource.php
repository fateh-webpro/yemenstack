<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'بوابة الواتساب';

    public static function getNavigationLabel(): string
    {
        return 'العملاء';
    }

    public static function getModelLabel(): string
    {
        return 'عميل';
    }

    public static function getPluralModelLabel(): string
    {
        return 'العملاء';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('اسم العميل')
                ->required()
                ->maxLength(255),
            TextInput::make('slug')
                ->label('المعرّف')
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            TextInput::make('contact_name')
                ->label('اسم مسؤول التواصل')
                ->maxLength(255),
            TextInput::make('phone')
                ->label('رقم الهاتف')
                ->tel()
                ->maxLength(255),
            TextInput::make('email')
                ->label('البريد الإلكتروني')
                ->email()
                ->maxLength(255),
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
            TextEntry::make('name')->label('اسم العميل'),
            TextEntry::make('slug')->label('المعرّف')->placeholder('-'),
            TextEntry::make('contact_name')->label('اسم مسؤول التواصل')->placeholder('-'),
            TextEntry::make('phone')->label('رقم الهاتف')->placeholder('-'),
            TextEntry::make('email')->label('البريد الإلكتروني')->placeholder('-'),
            IconEntry::make('is_active')->label('نشط')->boolean(),
            TextEntry::make('notes')->label('ملاحظات')->placeholder('-')->columnSpanFull(),
            TextEntry::make('created_at')->label('تاريخ الإنشاء')->dateTime('Y-m-d h:i A'),
            TextEntry::make('updated_at')->label('آخر تحديث')->dateTime('Y-m-d h:i A'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('اسم العميل')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contact_name')
                    ->label('اسم مسؤول التواصل')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('email')
                    ->label('البريد الإلكتروني')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('phone')
                    ->label('رقم الهاتف')
                    ->searchable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
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
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}