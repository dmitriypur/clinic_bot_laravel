<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DoctorResource\Pages;
use App\Filament\Resources\DoctorResource\RelationManagers;
use App\Models\Doctor;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
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

class DoctorResource extends Resource
{
    protected static ?string $model = Doctor::class;

    protected static ?string $navigationLabel = 'Врачи';
    protected static ?string $pluralNavigationLabel = 'Врач';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_name')
                    ->label('Фамилия')
                    ->searchable(),
                TextColumn::make('first_name')
                    ->label('Имя')
                    ->searchable(),
                TextColumn::make('clinics.name')
                    ->label('Клиники')
                    ->searchable(),
                IconColumn::make('status')
                    ->label('Статус')
                    ->boolean(),
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
            'index' => Pages\ListDoctors::route('/'),
            'create' => Pages\CreateDoctor::route('/create'),
            'edit' => Pages\EditDoctor::route('/{record}/edit'),
        ];
    }
}
