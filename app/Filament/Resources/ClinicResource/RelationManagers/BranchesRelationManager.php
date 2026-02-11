<?php

namespace App\Filament\Resources\ClinicResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class BranchesRelationManager extends RelationManager
{
    protected static string $relationship = 'branches';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('clinic_id')
                    ->default(fn ($livewire) => $livewire->ownerRecord->id),
                Forms\Components\Select::make('city_id')
                    ->label('Город')
                    ->required()
                    ->searchable()
                    ->options(function ($livewire) {
                        return $livewire->ownerRecord
                            ->cities()
                            ->pluck('name', 'id')
                            ->toArray();
                    }),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->label('Название филиала')
                    ->maxLength(500),
                Forms\Components\Textarea::make('address')
                    ->label('Адрес')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('phone')
                    ->label('Телефон')
                    ->tel()
                    ->maxLength(50),
                Forms\Components\Select::make('status')
                    ->label('Статус')
                    ->options([
                        1 => 'Активный',
                        0 => 'Неактивный',
                    ])
                    ->required()
                    ->default(1),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('city.name')
                    ->label('Город')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('address')
                    ->label('Адрес')
                    ->limit(50)
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable(),
                Tables\Columns\BadgeColumn::make('status')
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
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
