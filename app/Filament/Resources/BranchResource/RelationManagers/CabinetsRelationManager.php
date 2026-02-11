<?php

namespace App\Filament\Resources\BranchResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CabinetsRelationManager extends RelationManager
{
    protected static string $relationship = 'cabinets';

    protected static ?string $title = 'Кабинеты';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // Форма не используется для read-only связи
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Название'),

                Tables\Columns\IconColumn::make('status')
                    ->label('Статус')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Только просмотр - нет действий создания
            ])
            ->actions([
                Tables\Actions\Action::make('edit')
                    ->label('Редактировать')
                    ->icon('heroicon-o-pencil')
                    ->url(fn ($record) => route('filament.admin.resources.cabinets.edit', $record))
                    ->openUrlInNewTab(false),
            ])
            ->bulkActions([
                // Только просмотр - нет массовых действий
            ]);
    }
}
