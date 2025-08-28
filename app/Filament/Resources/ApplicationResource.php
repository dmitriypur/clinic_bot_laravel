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
                TextInput::make('full_name_parent')
                    ->label('ФИО родителя'),
                TextInput::make('full_name')
                    ->label('ФИО ребенка')
                    ->required(),
                DatePicker::make('birth_date')
                    ->label('Дата рождения'),
                TextInput::make('phone')
                    ->label('Телефон')
                    ->tel()
                    ->required(),
                TextInput::make('promo_code')
                    ->label('Промокод'),
                Select::make('city_id')
                    ->label('Город')
                    ->reactive()
                    ->relationship('city', 'name')
                    ->required()
                    ->afterStateUpdated(function (callable $set) {
                        $set('clinic_id', null);
                        $set('doctor_id', null);
                    }),
                Select::make('clinic_id')
                    ->label('Клиника')
                    ->reactive()
                    ->options(function (callable $get) {
                        $cityId = $get('city_id');
                        if (!$cityId) {
                            return [];
                        }
                        return \App\Models\Clinic::whereHas('cities', function ($query) use ($cityId) {
                            $query->where('cities.id', $cityId);
                        })->pluck('name', 'id');
                    })
                    ->afterStateUpdated(function (callable $set) {
                        $set('doctor_id', null);
                    }),
                Select::make('doctor_id')
                    ->label('Врач')
                    ->options(function (callable $get) {
                        $cityId = $get('city_id');
                        $clinicId = $get('clinic_id');
                        
                        if (!$cityId) {
                            return [];
                        }
                        
                        $query = \App\Models\Doctor::query();
                        
                        if ($clinicId) {
                            // Если выбрана клиника - показываем только докторов этой клиники
                            $query->whereHas('clinics', function ($q) use ($clinicId) {
                                $q->where('clinics.id', $clinicId);
                            });
                        } else {
                            // Если клиника не выбрана - берем всех докторов всех клиник выбранного города
                            $query->whereHas('clinics', function ($q) use ($cityId) {
                                $q->whereHas('cities', function ($cityQuery) use ($cityId) {
                                    $cityQuery->where('cities.id', $cityId);
                                });
                            });
                        }
                        
                        return $query->get()->mapWithKeys(function ($doctor) {
                            return [$doctor->id => $doctor->last_name . ' ' . $doctor->first_name];
                        });
                    }),

                TextInput::make('tg_user_id')
                    ->label('ID пользователя в Telegram')
                    ->numeric()
                    ->hidden(),
                TextInput::make('tg_chat_id')
                    ->label('ID чата в Telegram')
                    ->numeric()
                    ->hidden(),
                Toggle::make('send_to_1c')
                    ->label('Отправить в 1С')
                    ->hidden(),
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
