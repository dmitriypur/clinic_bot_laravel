<?php

namespace App\Filament\Resources\Reviews\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ReviewForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
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
}
