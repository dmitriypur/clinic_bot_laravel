<?php

namespace App\Filament\Resources\Applications\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('city.name')
                    ->searchable(),
                TextColumn::make('clinic.name')
                    ->searchable(),
                TextColumn::make('doctor.id')
                    ->searchable(),
                TextColumn::make('full_name_parent')
                    ->searchable(),
                TextColumn::make('full_name')
                    ->searchable(),
                TextColumn::make('birth_date')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('promo_code')
                    ->searchable(),
                TextColumn::make('tg_user_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('tg_chat_id')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('send_to_1c')
                    ->boolean(),
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
