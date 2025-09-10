<?php

namespace App\Filament\Resources;

use App\Filament\Filters\CabinetFilters;
use App\Filament\Resources\CabinetResource\Pages;
use App\Filament\Resources\CabinetResource\RelationManagers;
use App\Models\Cabinet;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;

/**
 * Filament ресурс для управления кабинетами
 * 
 * Предоставляет CRUD интерфейс для управления кабинетами в админ-панели.
 * Включает фильтрацию по ролям пользователей (super_admin, partner, doctor).
 * Партнеры видят только кабинеты своих клиник, врачи - только кабинеты филиалов где работают.
 */
class CabinetResource extends Resource
{
    protected static ?string $model = Cabinet::class;

    // Настройки интерфейса Filament
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';  // Иконка в навигации
    protected static ?string $modelLabel = 'Кабинет';  // Единственное число
    protected static ?string $pluralModelLabel = 'Кабинеты';  // Множественное число
    protected static ?string $navigationLabel = 'Кабинеты';  // Название в навигации
    protected static ?string $pluralLabel = 'Кабинеты';  // Альтернативное множественное число
    protected static ?string $label = 'Кабинет';  // Альтернативное единственное число
    protected static ?int $navigationSort = 3;  // Порядок в навигации

    /**
     * Форма для создания/редактирования кабинета
     * Включает выбор филиала, название кабинета и статус
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Выбор филиала с фильтрацией по ролям
                Select::make('branch_id')
                    ->label('Филиал')
                    ->required()
                    ->searchable()
                    ->options(function () {
                        $user = auth()->user();
                        // Партнеры видят только филиалы своих клиник
                        if ($user && $user->hasRole('partner')) {
                            return \App\Models\Branch::with('clinic')
                                ->where('clinic_id', $user->clinic_id)
                                ->get()
                                ->mapWithKeys(function ($branch) {
                                    return [$branch->id => $branch->clinic->name . ' - ' . $branch->name];
                                })
                                ->toArray();
                        }
                        // Super admin видит все филиалы
                        return \App\Models\Branch::with('clinic')
                            ->get()
                            ->mapWithKeys(function ($branch) {
                                return [$branch->id => $branch->clinic->name . ' - ' . $branch->name];
                            })
                            ->toArray();
                    }),
                // Название кабинета
                TextInput::make('name')
                    ->required()
                    ->label('Название кабинета')
                    ->maxLength(500),
                // Статус кабинета
                Select::make('status')
                    ->label('Статус')
                    ->options([
                        1 => 'Активный',
                        0 => 'Неактивный',
                    ])
                    ->required()
                    ->default(1),
            ]);
    }

    /**
     * Таблица для отображения списка кабинетов
     * Показывает филиал, название и статус с цветовой индикацией
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Название филиала с клиникой
                TextColumn::make('branch.name')
                    ->label('Филиал')
                    ->formatStateUsing(function ($record) {
                        return $record->branch->name . ' (' . $record->branch->clinic->name . ')';
                    }),
                // Название кабинета
                TextColumn::make('name')
                    ->label('Название кабинета'),
                // Статус с цветовой индикацией
                BadgeColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        '1' => 'Активный',
                        '0' => 'Неактивный',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        '1' => 'success',  // Зеленый для активных
                        '0' => 'danger',   // Красный для неактивных
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                CabinetFilters::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),    // Редактирование
                Tables\Actions\DeleteAction::make(),  // Удаление
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),  // Массовое удаление
                ]),
            ]);
    }

    /**
     * Фильтрация запросов по ролям пользователей
     * Партнеры видят только кабинеты своих клиник, врачи - только кабинеты филиалов где работают
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        
        $query = parent::getEloquentQuery();
        
        // Добавляем eager loading для оптимизации запросов
        $query->with(['branch.clinic']);
        
        // Фильтрация по ролям
        if ($user->isPartner()) {
            // Партнеры видят только кабинеты филиалов своих клиник
            $query->whereHas('branch', function($q) use ($user) {
                $q->where('clinic_id', $user->clinic_id);
            });
        } elseif ($user->isDoctor()) {
            // Врачи видят только кабинеты филиалов где работают
            $query->whereHas('branch.doctors', function($q) use ($user) {
                $q->where('doctor_id', $user->doctor_id);
            });
        }
        // super_admin видит все кабинеты без ограничений
        
        return $query;
    }

    /**
     * Связанные ресурсы (relation managers)
     * Пока не используются
     */
    public static function getRelations(): array
    {
        return [
            // Связанные ресурсы можно добавить здесь
        ];
    }

    /**
     * Страницы ресурса
     * Определяет маршруты для CRUD операций
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCabinets::route('/'),           // Список кабинетов
            'create' => Pages\CreateCabinet::route('/create'),   // Создание кабинета
            'view' => Pages\ViewCabinet::route('/{record}'),     // Просмотр кабинета (с календарем)
            'edit' => Pages\EditCabinet::route('/{record}/edit'), // Редактирование кабинета
        ];
    }
}
