<?php

namespace App\Filament\Resources\Reviews\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ReviewForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('text')
                    ->columnSpanFull(),
                TextInput::make('rating')
                    ->required()
                    ->numeric(),
                TextInput::make('user_id')
                    ->required()
                    ->numeric(),
                Select::make('doctor_id')
                    ->relationship('doctor', 'id')
                    ->required(),
                TextInput::make('status')
                    ->required()
                    ->numeric(),
            ]);
    }
}
