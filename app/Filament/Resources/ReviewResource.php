<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReviewResource\Pages;
use App\Filament\Resources\ReviewResource\RelationManagers;
use App\Models\Review;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static ?string $navigationLabel = 'Отзывы';
    protected static ?string $pluralNavigationLabel = 'Отзыв';
    protected static ?string $pluralLabel = 'Отзывы';
    protected static ?string $label = 'Отзыв';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Textarea::make('text')
                    ->label('Текст')
                    ->columnSpanFull(),
                TextInput::make('rating')
                    ->label('Оценка')
                    ->required()
                    ->numeric(),
                TextInput::make('user_id')
                    ->label('Пользователь')
                    ->required()
                    ->numeric(),
                Select::make('doctor_id')
                    ->label('Врач')
                    ->relationship('doctor', 'last_name')
                    ->required(),
                Toggle::make('status')
                    ->label('Опубликовано')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('doctor.full_name')
                    ->label('Врач')
                    ->searchable(),
                TextColumn::make('rating')
                    ->label('Оценка')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('status')
                    ->label('Опубликовано')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListReviews::route('/'),
            'create' => Pages\CreateReview::route('/create'),
            'edit' => Pages\EditReview::route('/{record}/edit'),
        ];
    }
}
