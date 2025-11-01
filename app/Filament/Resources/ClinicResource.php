<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClinicResource\Pages;
use App\Filament\Resources\ClinicResource\RelationManagers;
use App\Models\Clinic;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClinicResource extends Resource
{
    protected static ?string $model = Clinic::class;

    protected static ?string $navigationLabel = 'Клиники';
    protected static ?string $pluralNavigationLabel = 'Клиника';
    protected static ?string $pluralLabel = 'Клиники';
    protected static ?string $label = 'Клиника';
    protected static ?string $navigationGroup = 'Клиники';

    protected static ?string $navigationIcon = 'heroicon-o-plus';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Получаем текущего пользователя
        $user = auth()->user();

        // Если пользователь с ролью 'partner' — показываем только их клинику
        if ($user->hasRole('partner')) {
            $query->where('id', $user->clinic_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основные данные')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required(),
                        TextInput::make('external_id')
                            ->label('External ID (GUID клиники)')
                            ->maxLength(191)
                            ->hidden(),
                        Select::make('cities')
                            ->label('Города')
                            ->relationship('cities', 'name')
                            ->preload()
                            ->multiple()
                            ->searchable(),
                        Select::make('status')
                            ->label('Статус')
                            ->options([
                                1 => 'Активный',
                                0 => 'Неактивный',
                            ])
                            ->default(1)
                            ->required(),
                        Select::make('slot_duration')
                            ->label('Длительность слота по умолчанию (минуты)')
                            ->options([
                                10 => '10 минут',
                                15 => '15 минут',
                                20 => '20 минут',
                                25 => '25 минут',
                                30 => '30 минут',
                                35 => '35 минут',
                                40 => '40 минут',
                                45 => '45 минут',
                                50 => '50 минут',
                                55 => '55 минут',
                                60 => '1 час',
                                90 => '1.5 часа',
                                120 => '2 часа',
                            ])
                            ->default(30)
                            ->helperText('Используется для всех филиалов, если у них не задана своя длительность')
                            ->required(),
                        Toggle::make('dashboard_calendar_enabled')
                            ->label('Виджет календаря заявок')
                            ->helperText('Управляет отображением календаря заявок и расписания для партнеров этой клиники.')
                            ->default(true)
                            ->visible(fn () => auth()->user()?->isSuperAdmin()),
                    ])->columns(2),
                Forms\Components\Section::make('CRM интеграция')
                    ->schema([
                        Select::make('crm_provider')
                            ->label('CRM-система')
                            ->options(function () {
                                return collect(config('crm.providers', []))
                                    ->mapWithKeys(fn ($provider, $key) => [$key => $provider['label'] ?? $key])
                                    ->toArray();
                            })
                            ->default('none')
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state === 'none') {
                                    $set('crm_settings', []);
                                }
                            }),
                        Forms\Components\Grid::make(2)
                            ->schema([
                                TextInput::make('crm_settings.webhook_url')
                                    ->label('Webhook URL')
                                    ->url()
                                    ->visible(fn (Forms\Get $get) => in_array($get('crm_provider'), ['bitrix24', 'albato', 'amo_crm', 'onec_crm']))
                                    ->required(fn (Forms\Get $get) => in_array($get('crm_provider'), ['bitrix24', 'albato', 'amo_crm', 'onec_crm']))
                                    ->dehydrated(fn (Forms\Get $get) => in_array($get('crm_provider'), ['bitrix24', 'albato', 'amo_crm', 'onec_crm'])),
                                TextInput::make('crm_settings.token')
                                    ->label('Token')
                                    ->visible(fn (Forms\Get $get) => in_array($get('crm_provider'), ['amo_crm', 'onec_crm']))
                                    ->required(fn (Forms\Get $get) => in_array($get('crm_provider'), ['amo_crm', 'onec_crm']))
                                    ->dehydrated(fn (Forms\Get $get) => in_array($get('crm_provider'), ['amo_crm', 'onec_crm'])),
                                TextInput::make('crm_settings.title_prefix')
                                    ->label('Префикс названия лида')
                                    ->visible(fn (Forms\Get $get) => $get('crm_provider') === 'bitrix24')
                                    ->default('Заявка')
                                    ->dehydrated(fn (Forms\Get $get) => $get('crm_provider') === 'bitrix24'),
                                TextInput::make('crm_settings.category_id')
                                    ->label('CATEGORY_ID (воронка)')
                                    ->visible(fn (Forms\Get $get) => $get('crm_provider') === 'bitrix24')
                                    ->dehydrated(fn (Forms\Get $get) => $get('crm_provider') === 'bitrix24'),
                                TextInput::make('crm_settings.stage_id')
                                    ->label('STAGE_ID (стадия)')
                                    ->visible(fn (Forms\Get $get) => $get('crm_provider') === 'bitrix24')
                                    ->dehydrated(fn (Forms\Get $get) => $get('crm_provider') === 'bitrix24'),
                                TextInput::make('crm_settings.lead_prefix')
                                    ->label('Префикс сделки')
                                    ->visible(fn (Forms\Get $get) => $get('crm_provider') === 'amo_crm')
                                    ->default('Заявка')
                                    ->dehydrated(fn (Forms\Get $get) => $get('crm_provider') === 'amo_crm'),
                                TextInput::make('crm_settings.status_id')
                                    ->label('ID статуса в AmoCRM')
                                    ->visible(fn (Forms\Get $get) => $get('crm_provider') === 'amo_crm')
                                    ->numeric()
                                    ->dehydrated(fn (Forms\Get $get) => $get('crm_provider') === 'amo_crm'),
                            ]),
                        Forms\Components\Textarea::make('crm_settings.notes')
                            ->label('Примечания')
                            ->rows(2)
                            ->dehydrated(fn (Forms\Get $get) => $get('crm_provider') !== 'none')
                            ->visible(fn (Forms\Get $get) => $get('crm_provider') !== 'none'),
                    ])->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable(),
                TextColumn::make('cities.name')
                    ->label('Города')
                    ->searchable(),
                TextColumn::make('branches_count')
                    ->label('Филиалов')
                    ->counts('branches')
                    ->sortable(),
                TextColumn::make('slot_duration')
                    ->label('Длительность слота')
                    ->formatStateUsing(fn ($state) => $state . ' мин')
                    ->sortable(),
                IconColumn::make('status')
                    ->label('Статус')
                    ->boolean(),
                IconColumn::make('dashboard_calendar_enabled')
                    ->label('Календарь')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn () => auth()->check() && (auth()->user()->hasRole('super_admin') || auth()->user()->hasRole('partner'))),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->check() && (auth()->user()->hasRole('super_admin') || auth()->user()->hasRole('partner'))),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\BranchesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClinics::route('/'),
            'create' => Pages\CreateClinic::route('/create'),
            'edit' => Pages\EditClinic::route('/{record}/edit'),
        ];
    }
}
