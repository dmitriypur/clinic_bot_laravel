<?php

namespace App\Filament\Resources\Clinics\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;

class ClinicForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required(),
                TextInput::make('status')
                    ->label('Статус')
                    ->required()
                    ->numeric(),
                Select::make('city_id')
                    ->label('Города')
                    ->relationship('cities', 'name')
                    ->required(),
            ]);
    }
}
