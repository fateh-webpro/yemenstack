<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\CanUseDatabaseTransactions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * @property-read Schema $form
 */
class SiteSettings extends Page
{
    use CanUseDatabaseTransactions;

    protected string $view = 'filament-panels::pages.page';

    protected static string | \UnitEnum | null $navigationGroup = 'الإعدادات';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'إعدادات الموقع';

    protected static ?int $navigationSort = 100;

    protected static ?string $slug = 'site-settings';

    protected static ?string $title = 'إعدادات الموقع';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    #[Locked]
    public ?int $siteSettingId = null;

    public function mount(): void
    {
        $setting = SiteSetting::current();

        $this->siteSettingId = $setting->getKey();
        $this->form->fill($this->getFormFillData($setting));
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->model(SiteSetting::class)
            ->operation('edit')
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الهوية')
                ->schema([
                    $this->makeSiteNameInput(),
                    $this->makeBrandNameInput(),
                    $this->makeImageUpload('brand_logo', 'شعار الموقع'),
                    $this->makeImageUpload('login_logo', 'شعار تسجيل الدخول'),
                    $this->makeImageUpload('favicon', 'أيقونة الموقع'),
                ])
                ->columns(2),
            Section::make('بيانات التواصل')
                ->schema([
                    TextInput::make('phone')
                        ->label('رقم الجوال')
                        ->maxLength(50)
                        ->validationMessages([
                            'max' => 'يجب ألا يتجاوز رقم الجوال 50 حرفًا.',
                        ]),
                    TextInput::make('whatsapp')
                        ->label('رقم الواتساب')
                        ->maxLength(50)
                        ->validationMessages([
                            'max' => 'يجب ألا يتجاوز رقم الواتساب 50 حرفًا.',
                        ]),
                    TextInput::make('email')
                        ->label('البريد الإلكتروني')
                        ->email()
                        ->maxLength(255)
                        ->validationMessages([
                            'email' => 'يرجى إدخال بريد إلكتروني صحيح.',
                            'max' => 'يجب ألا يتجاوز البريد الإلكتروني 255 حرفًا.',
                        ]),
                    Textarea::make('address')
                        ->label('العنوان')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('SEO')
                ->schema([
                    TextInput::make('meta_title')
                        ->label('عنوان SEO')
                        ->maxLength(70)
                        ->validationMessages([
                            'max' => 'يجب ألا يتجاوز عنوان SEO عدد 70 حرفًا.',
                        ]),
                    Textarea::make('meta_description')
                        ->label('وصف SEO')
                        ->rows(4)
                        ->maxLength(160)
                        ->validationMessages([
                            'max' => 'يجب ألا يتجاوز وصف SEO عدد 160 حرفًا.',
                        ]),
                ])
                ->columns(2),
            Section::make('الألوان')
                ->schema([
                    $this->makeHexColorInput('primary_color', 'اللون الرئيسي'),
                    $this->makeHexColorInput('success_color', 'لون النجاح'),
                    $this->makeHexColorInput('danger_color', 'لون الخطر'),
                ])
                ->columns(3),
            Section::make('الحالة')
                ->schema([
                    Toggle::make('is_maintenance_mode')
                        ->label('تفعيل وضع الصيانة'),
                ]),
        ]);
    }

    public function save(): void
    {
        try {
            $this->beginDatabaseTransaction();

            $data = $this->form->getState();
            $record = $this->getRecord();

            $data = $this->normalizeMediaFields($data, $record);
            $record->update($data);
            $record->refresh();

            $this->form->fill($this->getFormFillData($record));

            $this->commitDatabaseTransaction();
        } catch (Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            throw $exception;
        }

        Notification::make()
            ->success()
            ->title('تم حفظ إعدادات الموقع بنجاح.')
            ->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
        ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->extraAttributes([
                'novalidate' => true,
            ])
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->fullWidth($this->hasFullWidthFormActions())
                    ->sticky($this->areFormActionsSticky())
                    ->key('form-actions'),
            ]);
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('حفظ الإعدادات')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    protected function hasFullWidthFormActions(): bool
    {
        return false;
    }

    protected function getRecord(): SiteSetting
    {
        return SiteSetting::query()->findOrFail($this->siteSettingId);
    }

    protected function makeSiteNameInput(): TextInput
    {
        return TextInput::make('site_name')
            ->label('اسم الموقع')
            ->rule('required')
            ->markAsRequired()
            ->maxLength(255)
            ->validationMessages([
                'required' => 'يرجى إدخال اسم الموقع.',
                'max' => 'يجب ألا يتجاوز اسم الموقع 255 حرفًا.',
            ]);
    }

    protected function makeBrandNameInput(): TextInput
    {
        return TextInput::make('brand_name')
            ->label('اسم البراند')
            ->rule('required')
            ->markAsRequired()
            ->maxLength(255)
            ->validationMessages([
                'required' => 'يرجى إدخال اسم البراند.',
                'max' => 'يجب ألا يتجاوز اسم البراند 255 حرفًا.',
            ]);
    }

    protected function makeHexColorInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->regex('/^#(?:[0-9A-Fa-f]{6}|[0-9A-Fa-f]{3})$/')
            ->validationMessages([
                'regex' => 'يرجى إدخال لون صحيح بصيغة HEX مثل #102B5F.',
            ]);
    }

    protected function makeImageUpload(string $name, string $label): FileUpload
    {
        return FileUpload::make($name)
            ->label($label)
            ->disk('public')
            ->directory('site-settings')
            ->visibility('public')
            ->fetchFileInformation(false)
            ->maxFiles(1)
            ->acceptedFileTypes([
                'image/png',
                'image/jpeg',
                'image/webp',
                'image/svg+xml',
            ])
            ->maxSize(2048)
            ->getUploadedFileUsing(function (FileUpload $component, string $file): ?array {
                return $this->resolveUploadedFile($component, $file);
            })
            ->validationMessages([
                'mimetypes' => 'يرجى رفع صورة صحيحة بصيغة PNG أو JPG أو WEBP أو SVG.',
                'max' => 'يجب ألا يتجاوز حجم الصورة 2 ميجابايت.',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getFormFillData(SiteSetting $setting): array
    {
        $data = $setting->attributesToArray();

        foreach (['brand_logo', 'login_logo', 'favicon'] as $field) {
            if (blank($data[$field] ?? null)) {
                $data[$field] = config("starter.{$field}");
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeMediaFields(array $data, SiteSetting $record): array
    {
        foreach (['brand_logo', 'login_logo', 'favicon'] as $field) {
            $value = $data[$field] ?? null;
            $fallback = config("starter.{$field}");
            $original = $record->getRawOriginal($field);

            if (is_array($value)) {
                $value = reset($value) ?: null;
            }

            if ($original === null && $value === $fallback) {
                $value = null;
            }

            $data[$field] = $value;
        }

        return $data;
    }

    protected function resolveUploadedFile(FileUpload $component, string $file): ?array
    {
        if (blank($file)) {
            return null;
        }

        if (str_starts_with($file, 'site-settings/')) {
            $disk = Storage::disk('public');

            if (! $disk->exists($file)) {
                return null;
            }

            return [
                'name' => basename($file),
                'size' => $disk->size($file),
                'type' => $disk->mimeType($file),
                'url' => $disk->url($file),
            ];
        }

        $path = public_path($file);

        if (! File::exists($path)) {
            return null;
        }

        return [
            'name' => basename($file),
            'size' => File::size($path),
            'type' => File::mimeType($path),
            'url' => asset($file),
        ];
    }
}