<?php

namespace App\Filament\Resources;

use App\Enums\IntegrationMode;
use App\Filament\Resources\BranchResource\Pages;
use App\Filament\Resources\BranchResource\RelationManagers;
use App\Models\Branch;
use App\Models\IntegrationEndpoint;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $modelLabel = 'Филиал';

    protected static ?string $pluralModelLabel = 'Филиалы';

    protected static ?string $navigationLabel = 'Филиалы';

    protected static ?string $pluralLabel = 'Филиалы';

    protected static ?string $label = 'Филиал';

    protected static ?string $navigationGroup = 'Клиники';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Получаем текущего пользователя
        $user = Auth::user();

        // Если пользователь с ролью 'doctor' — не показываем ничего
        if ($user && $user->hasRole('doctor')) {
            $query->whereRaw('1 = 0'); // Всегда false
        }
        // Если пользователь с ролью 'partner' — показываем только филиалы их клиники
        elseif ($user && $user->hasRole('partner')) {
            $query->where('clinic_id', $user->clinic_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $user = Auth::user();
        $isPartner = $user && $user->hasRole('partner');

        return $form
            ->schema([
                Select::make('clinic_id')
                    ->relationship(
                        'clinic',
                        'name',
                        fn ($query) => $isPartner ? $query->where('id', $user->clinic_id) : $query
                    )
                    ->required()
                    ->label('Клиника')
                    ->searchable()
                    ->preload()
                    ->reactive()
                    ->default($isPartner ? $user->clinic_id : null)
                    ->dehydrated() // keep the value when the field is disabled for partners
                    ->disabled($isPartner)
                    ->afterStateUpdated(fn (callable $set) => $set('city_id', null)),
                Select::make('city_id')
                    ->label('Город')
                    ->required()
                    ->searchable()
                    ->options(function (callable $get) {
                        $clinicId = $get('clinic_id');
                        if (! $clinicId) {
                            return [];
                        }

                        return \App\Models\Clinic::find($clinicId)
                            ?->cities()
                            ->pluck('name', 'id')
                            ->toArray() ?? [];
                    })
                    ->disabled(fn (callable $get): bool => ! $get('clinic_id')),
                TextInput::make('name')
                    ->required()
                    ->label('Название филиала')
                    ->maxLength(500),
                Forms\Components\Textarea::make('address')
                    ->label('Адрес')
                    ->columnSpanFull(),
                TextInput::make('phone')
                    ->label('Телефон')
                    ->tel()
                    ->maxLength(50),
                TextInput::make('external_id')
                    ->label('External ID (GUID филиала в 1С)')
                    ->helperText('Используется для сопоставления филиала с 1С.')
                    ->maxLength(191),
                Select::make('status')
                    ->label('Статус')
                    ->options([
                        1 => 'Активный',
                        0 => 'Неактивный',
                    ])
                    ->required()
                    ->default(1),
                Select::make('slot_duration')
                    ->label('Длительность слота (минуты)')
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
                    ->helperText('Если не указано, будет использоваться настройка клиники')
                    ->nullable(),
                Select::make('integration_mode')
                    ->label('Режим интеграции')
                    ->options(IntegrationMode::options())
                    ->placeholder('Как у клиники')
                    ->nullable()
                    ->helperText('Оставьте пустым, чтобы использовать режим клиники.'),
                Section::make('Интеграция с 1С')
                    ->description('Настройки подключения филиала к своей 1С.')
                    ->relationship('integrationEndpoint')
                    ->collapsible()
                    ->schema([
                        Hidden::make('type')->default(IntegrationEndpoint::TYPE_ONEC),
                        Toggle::make('is_active')->label('Интеграция включена')->default(true),
                        TextInput::make('base_url')
                            ->label('Базовый URL API 1С')
                            ->url()
                            ->placeholder('https://web.1c-luxoptic.ru/nUNF/hs/integration/')
                            ->required(fn (Get $get) => (bool) $get('is_active')),
                        TextInput::make('credentials.manual_booking_path')
                            ->label('Путь для записи')
                            ->placeholder('events?action=newrecord')
                            ->default('events?action=newrecord')
                            ->required(fn (Get $get) => (bool) $get('is_active'))
                            ->helperText('Добавляется к базовому URL. Обычно: events?action=newrecord'),
                        TextInput::make('credentials.cancel_booking_path')
                            ->label('Путь для отмены записи')
                            ->placeholder('events?action=cancelrecord')
                            ->default('events?action=cancelrecord')
                            ->required(fn (Get $get) => (bool) $get('is_active'))
                            ->helperText('Добавляется к базовому URL. Обычно: events?action=cancelrecord'),
                        TextInput::make('credentials.manual_booking_authorization')
                            ->label('Значение заголовка Authorization')
                            ->placeholder('123543543')
                            ->required(fn (Get $get) => (bool) $get('is_active'))
                            ->helperText('Полное значение заголовка Authorization, выданное 1С.'),
                        TextInput::make('credentials.timeout')
                            ->numeric()
                            ->label('Таймаут запроса (сек.)')
                            ->default(15),
                        Toggle::make('credentials.verify_ssl')
                            ->label('Проверять SSL сертификат')
                            ->default(true),
                        TextInput::make('credentials.webhook_secret')
                            ->label('Секрет для вебхуков')
                            ->helperText('Используется для проверки запросов от 1С.'),
                        Placeholder::make('last_success_at')
                            ->label('Последняя успешная синхронизация')
                            ->content(fn (?IntegrationEndpoint $record) => $record?->last_success_at?->format('d.m.Y H:i') ?? '—'),
                        Placeholder::make('last_error_at')
                            ->label('Последняя ошибка')
                            ->content(fn (?IntegrationEndpoint $record) => $record?->last_error_at?->format('d.m.Y H:i') ?? '—'),
                        Placeholder::make('last_error_message')
                            ->label('Сообщение об ошибке')
                            ->content(fn (?IntegrationEndpoint $record) => $record?->last_error_message ?? '—'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('clinic.name')
                    ->label('Клиника')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('city.name')
                    ->label('Город')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Название филиала')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('address')
                    ->label('Адрес')
                    ->limit(50)
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable(),
                Tables\Columns\TextColumn::make('integration_mode')
                    ->label('Режим')
                    ->badge()
                    ->formatStateUsing(function ($state) {
                        $mode = $state instanceof IntegrationMode
                            ? $state
                            : IntegrationMode::tryFrom((string) $state);

                        return $mode?->label() ?? 'Как у клиники';
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('clinic_id')
                    ->relationship('clinic', 'name')
                    ->label('Клиника')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('city_id')
                    ->relationship('city', 'name')
                    ->label('Город')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        1 => 'Активный',
                        0 => 'Неактивный',
                    ]),
                Tables\Filters\SelectFilter::make('integration_mode')
                    ->label('Режим интеграции')
                    ->options(IntegrationMode::options()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CabinetsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        $user = auth()->user();

        $pages = [
            'index' => Pages\ListBranches::route('/'),
        ];

        // Только не-doctor пользователи могут создавать и редактировать филиалы
        if (! $user || ! $user->hasRole('doctor')) {
            $pages['create'] = Pages\CreateBranch::route('/create');
            $pages['edit'] = Pages\EditBranch::route('/{record}/edit');
        }

        return $pages;
    }
}
