<?php

namespace App\Filament\Resources\Doctors\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;

class DoctorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('last_name')
                    ->label('Фамилия')
                    ->required(),
                TextInput::make('first_name')
                    ->label('Имя')
                    ->required(),
                TextInput::make('second_name')
                    ->label('Отчество'),
                TextInput::make('experience')
                    ->label('Опыт')
                    ->required()
                    ->numeric(),
                TextInput::make('age')
                    ->label('Возраст')
                    ->required()
                    ->numeric(),
                
                Select::make('clinic_id')
                    ->label('Клиника')
                    ->multiple()
                    ->relationship('clinics', 'name')
                    ->required(),
                TextInput::make('age_admission_from')
                    ->label('Возраст приёма с')
                    ->required()
                    ->numeric(),
                TextInput::make('age_admission_to')
                    ->label('Возраст приёма до')
                    ->required()
                    ->numeric(),
                TextInput::make('sum_ratings')
                    ->label('Оценка')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('count_ratings')
                    ->label('Количество оценок')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('uuid')
                    ->label('UUID врача')
                    ->required(),
                TextInput::make('review_link')
                    ->label('Ссылка на отзывы'),

                FileUpload::make('photo_src')
                    ->label('Фото'),
                FileUpload::make('diploma_src')
                    ->label('Диплом'),
                Toggle::make('status')
                    ->label('Статус'),
            ]);
    }
}
