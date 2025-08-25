<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApplicationResource\Pages;
use App\Filament\Resources\ApplicationResource\RelationManagers;
use App\Models\Application;
use App\Models\Clinic;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
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

class ApplicationResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static ?string $navigationLabel = 'Заявки';
    protected static ?string $pluralNavigationLabel = 'Заявки';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Получаем текущего пользователя
        $user = auth()->user();

        // Если пользователь с ролью 'user' — показываем только их город
        if ($user->hasRole('partner')) {
            $clinic = Clinic::query()->where('id', $user->clinic_id)->first();
            $applications = $clinic->applications->pluck('id')->toArray();
            $query->whereIn('id', $applications);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('city.name')
                    ->label('Город')
                    ->searchable(),
                TextColumn::make('clinic.name')
                    ->label('Клиника')
                    ->searchable(),
                TextColumn::make('doctor.last_name')
                    ->label('Врач')
                    ->searchable(),
                TextColumn::make('full_name')
                    ->label('Имя ребенка')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable(),
                IconColumn::make('send_to_1c')
                    ->label('Отправить в 1С')
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
            'index' => Pages\ListApplications::route('/'),
            'create' => Pages\CreateApplication::route('/create'),
            'edit' => Pages\EditApplication::route('/{record}/edit'),
        ];
    }
}
