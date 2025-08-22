<?php

namespace App\Filament\Resources\Doctors\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DoctorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_name')
                    ->searchable(),
                TextColumn::make('first_name')
                    ->searchable(),
                TextColumn::make('second_name')
                    ->searchable(),
                TextColumn::make('experience')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('age')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('photo_src')
                    ->searchable(),
                TextColumn::make('diploma_src')
                    ->searchable(),
                TextColumn::make('status')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('age_admission_from')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('age_admission_to')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('sum_ratings')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('count_ratings')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('uuid')
                    ->label('UUID')
                    ->searchable(),
                TextColumn::make('review_link')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
