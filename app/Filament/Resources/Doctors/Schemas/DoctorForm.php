<?php

namespace App\Filament\Resources\Doctors\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DoctorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('last_name')
                    ->required(),
                TextInput::make('first_name')
                    ->required(),
                TextInput::make('second_name'),
                TextInput::make('experience')
                    ->required()
                    ->numeric(),
                TextInput::make('age')
                    ->required()
                    ->numeric(),
                TextInput::make('photo_src'),
                TextInput::make('diploma_src'),
                TextInput::make('status')
                    ->required()
                    ->numeric(),
                TextInput::make('age_admission_from')
                    ->required()
                    ->numeric(),
                TextInput::make('age_admission_to')
                    ->required()
                    ->numeric(),
                TextInput::make('sum_ratings')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('count_ratings')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('uuid')
                    ->label('UUID')
                    ->required(),
                TextInput::make('review_link'),
            ]);
    }
}
