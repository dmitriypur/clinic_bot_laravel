<?php

namespace App\Filament\Resources;

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

class CabinetResource extends Resource
{
    protected static ?string $model = Cabinet::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $modelLabel = 'Кабинет';
    protected static ?string $pluralModelLabel = 'Кабинеты';
    protected static ?string $navigationLabel = 'Кабинеты';
    protected static ?string $pluralLabel = 'Кабинеты';
    protected static ?string $label = 'Кабинет';
    protected static ?int $navigationSort = 3;


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('branch_id')
                    ->label('Филиал')
                    ->required()
                    ->searchable()
                    ->options(function () {
                        $user = auth()->user();
                        if ($user && $user->hasRole('partner')) {
                            return \App\Models\Branch::with('clinic')
                                ->where('clinic_id', $user->clinic_id)
                                ->get()
                                ->mapWithKeys(function ($branch) {
                                    return [$branch->id => $branch->clinic->name . ' - ' . $branch->name];
                                })
                                ->toArray();
                        }
                        return \App\Models\Branch::with('clinic')
                            ->get()
                            ->mapWithKeys(function ($branch) {
                                return [$branch->id => $branch->clinic->name . ' - ' . $branch->name];
                            })
                            ->toArray();
                    }),
                TextInput::make('name')
                    ->required()
                    ->label('Название кабинета')
                    ->maxLength(500),
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('branch.name')
                    ->label('Филиал'),
                TextColumn::make('name')
                    ->label('Название кабинета'),
                BadgeColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        '1' => 'Активный',
                        '0' => 'Неактивный',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        '1' => 'success',
                        '0' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            //
        ];
    }

    

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCabinets::route('/'),
            'create' => Pages\CreateCabinet::route('/create'),
            'view' => Pages\ViewCabinet::route('/{record}'),
            'edit' => Pages\EditCabinet::route('/{record}/edit'),
        ];
    }
}
