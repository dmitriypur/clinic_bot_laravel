<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApplicationStatusResource\Pages;
use App\Filament\Resources\ApplicationStatusResource\RelationManagers;
use App\Models\ApplicationStatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ApplicationStatusResource extends Resource
{
    protected static ?string $model = ApplicationStatus::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';
    
    protected static ?string $navigationLabel = 'Статусы заявок';
    
    protected static ?string $modelLabel = 'Статус заявки';
    
    protected static ?string $pluralModelLabel = 'Статусы заявок';
    
    protected static ?string $navigationGroup = 'Заявки';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(50),
                    
                Forms\Components\TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true)
                    ->rules(['regex:/^[a-z0-9_-]+$/'])
                    ->helperText('Только строчные буквы, цифры, дефисы и подчеркивания'),
                    
                Forms\Components\Select::make('color')
                    ->label('Цвет')
                    ->options([
                        'gray' => 'Серый',
                        'blue' => 'Синий',
                        'green' => 'Зеленый',
                        'red' => 'Красный',
                        'yellow' => 'Желтый',
                        'purple' => 'Фиолетовый',
                        'pink' => 'Розовый',
                        'indigo' => 'Индиго',
                    ])
                    ->default('gray')
                    ->required(),
                    
                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок сортировки')
                    ->numeric()
                    ->default(0)
                    ->required(),
                    
                Forms\Components\Toggle::make('is_active')
                    ->label('Активен')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->sortable(),
                    
                Tables\Columns\ColorColumn::make('color')
                    ->label('Цвет'),
                    
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable(),
                    
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
                    
                Tables\Columns\TextColumn::make('applications_count')
                    ->label('Заявок')
                    ->counts('applications'),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Активен')
                    ->boolean()
                    ->trueLabel('Только активные')
                    ->falseLabel('Только неактивные')
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order');
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
            'index' => Pages\ListApplicationStatuses::route('/'),
            'create' => Pages\CreateApplicationStatus::route('/create'),
            'edit' => Pages\EditApplicationStatus::route('/{record}/edit'),
        ];
    }
}
