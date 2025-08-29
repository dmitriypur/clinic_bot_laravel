<?php

namespace App\Filament\Resources\BranchResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DoctorsRelationManager extends RelationManager
{
    protected static string $relationship = 'doctors';

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
                Tables\Columns\TextColumn::make('full_name')
                    ->label('ФИО')
                    ->searchable(['last_name', 'first_name', 'second_name']),
                    
                Tables\Columns\TextColumn::make('experience')
                    ->label('Опыт')
                    ->suffix(' лет'),
                    
                Tables\Columns\TextColumn::make('age_admission_from')
                    ->label('Прием от')
                    ->suffix(' лет'),
                    
                Tables\Columns\TextColumn::make('age_admission_to')
                    ->label('Прием до')
                    ->suffix(' лет'),
                    
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
                // Только просмотр - нет действий редактирования/удаления
            ])
            ->bulkActions([
                // Только просмотр - нет массовых действий
            ]);
    }
}
