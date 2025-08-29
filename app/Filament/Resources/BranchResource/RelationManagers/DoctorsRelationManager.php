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
                Forms\Components\TextInput::make('last_name')
                    ->label('Фамилия')
                    ->required()
                    ->maxLength(255),
                    
                Forms\Components\TextInput::make('first_name')
                    ->label('Имя')
                    ->required()
                    ->maxLength(255),
                    
                Forms\Components\TextInput::make('second_name')
                    ->label('Отчество')
                    ->maxLength(255),
                    
                Forms\Components\TextInput::make('experience')
                    ->label('Опыт работы (лет)')
                    ->required()
                    ->numeric()
                    ->minValue(0),
                    
                Forms\Components\TextInput::make('age')
                    ->label('Возраст')
                    ->required()
                    ->numeric()
                    ->minValue(18)
                    ->maxValue(100),
                    
                Forms\Components\TextInput::make('age_admission_from')
                    ->label('Прием от (лет)')
                    ->required()
                    ->numeric()
                    ->minValue(0),
                    
                Forms\Components\TextInput::make('age_admission_to')
                    ->label('Прием до (лет)')
                    ->required()
                    ->numeric()
                    ->minValue(0),

                Forms\Components\Select::make('status')
                    ->label('Статус')
                    ->options([
                        1 => 'Активный',
                        0 => 'Неактивный',
                    ])
                    ->default(1)
                    ->required(),
                    
                Forms\Components\FileUpload::make('photo_src')
                    ->label('Фото')
                    ->image()
                    ->directory('doctors/photos'),
                    
                Forms\Components\FileUpload::make('diploma_src')
                    ->label('Диплом')
                    ->image()
                    ->directory('doctors/diplomas'),
                    
                
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
