<?php

namespace App\Filament\Resources\Applications\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ApplicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('city_id')
                    ->relationship('city', 'name')
                    ->required(),
                Select::make('clinic_id')
                    ->relationship('clinic', 'name'),
                Select::make('doctor_id')
                    ->relationship('doctor', 'id'),
                TextInput::make('full_name_parent'),
                TextInput::make('full_name')
                    ->required(),
                TextInput::make('birth_date'),
                TextInput::make('phone')
                    ->tel()
                    ->required(),
                TextInput::make('promo_code'),
                TextInput::make('tg_user_id')
                    ->numeric(),
                TextInput::make('tg_chat_id')
                    ->numeric(),
                Toggle::make('send_to_1c')
                    ->required(),
            ]);
    }
}
