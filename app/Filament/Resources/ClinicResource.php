<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClinicResource\Pages;
use App\Filament\Resources\ClinicResource\RelationManagers;
use App\Models\Clinic;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\Toggle;

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
                TextInput::make('name')
                    ->label('Название')
                    ->required(),
                Select::make('cities')
                    ->label('Города')
                    ->relationship('cities', 'name')
                    ->preload()
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
