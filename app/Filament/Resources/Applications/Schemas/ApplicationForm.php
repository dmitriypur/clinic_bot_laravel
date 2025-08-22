<?php

namespace App\Filament\Resources\Applications\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Forms\Components\DatePicker;

class ApplicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('city_id')
                    ->label('Город')
                    ->relationship('city', 'name')
                    ->required(),
                Select::make('clinic_id')
                    ->label('Клиника')
                    ->relationship('clinic', 'name'),
                Select::make('doctor_id')
                    ->label('Врач')
                    ->relationship('doctor', 'last_name'),
                TextInput::make('full_name_parent')
                    ->label('Имя родителя'),
                TextInput::make('full_name')
                    ->label('Имя ребенка')
                    ->required(),
                DatePicker::make('birth_date')
                    ->label('Дата рождения'),
                TextInput::make('phone')
                    ->label('Телефон')
                    ->tel()
                    ->required(),
                TextInput::make('promo_code')
                    ->label('Промокод'),
                TextInput::make('tg_user_id')
                    ->label('ID пользователя в Telegram')
                    ->numeric(),
                TextInput::make('tg_chat_id')
                    ->label('ID чата в Telegram')
                    ->numeric(),
                Toggle::make('send_to_1c')
                    ->label('Отправить в 1С')
                    ->required(),
            ]);
    }
}
