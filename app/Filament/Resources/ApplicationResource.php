<?php

namespace App\Filament\Resources;

use App\Filament\Filters\ApplicationFilters;
use App\Filament\Resources\ApplicationResource\Pages;
use App\Filament\Resources\ApplicationResource\RelationManagers;
use App\Filament\Resources\ApplicationResource\Widgets\AppointmentCalendarWidget;
use App\Models\Application;
use App\Models\Clinic;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ApplicationResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static ?string $navigationLabel = 'Записи на прием';
    protected static ?string $pluralNavigationLabel = 'Записи на прием';
    protected static ?string $pluralLabel = 'Записи на прием';
    protected static ?string $label = 'Запись на прием';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?int $navigationSort = 5;

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
                    ->options(function (callable $get) {
                        $user = auth()->user();
                        
                        // Если пользователь с ролью partner - показываем только города его клиники
                        if ($user->hasRole('partner')) {
                            $clinic = Clinic::query()->where('id', $user->clinic_id)->first();
                            if ($clinic) {
                                return $clinic->cities->pluck('name', 'id');
                            }
                            return [];
                        }
                        
                        // Для остальных пользователей - все города
                        return \App\Models\City::pluck('name', 'id');
                    })
                    ->required()
                    ->afterStateUpdated(function (callable $set) {
                        $set('clinic_id', null);
                        $set('branch_id', null);
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
                        
                        $user = auth()->user();
                        
                        // Если пользователь с ролью partner - показываем только его клинику
                        if ($user->hasRole('partner')) {
                            $clinic = Clinic::query()->where('id', $user->clinic_id)->first();
                            if ($clinic && $clinic->cities->contains('id', $cityId)) {
                                return [$clinic->id => $clinic->name];
                            }
                            return [];
                        }
                        
                        // Для остальных пользователей - клиники выбранного города
                        return \App\Models\Clinic::whereHas('cities', function ($query) use ($cityId) {
                            $query->where('cities.id', $cityId);
                        })->pluck('name', 'id');
                    })
                    ->afterStateUpdated(function (callable $set) {
                        $set('branch_id', null);
                        $set('doctor_id', null);
                    })
                    ->required(),
                
                Select::make('branch_id')
                    ->label('Филиал')
                    ->reactive()
                    ->options(function (callable $get) {
                        $cityId = $get('city_id');
                        $clinicId = $get('clinic_id');
                        
                        if (!$cityId) {
                            return [];
                        }
                        
                        $user = auth()->user();
                        
                        // Если пользователь с ролью partner - показываем только филиалы его клиники
                        if ($user->hasRole('partner')) {
                            $clinic = Clinic::query()->where('id', $user->clinic_id)->first();
                            if ($clinic) {
                                $query = \App\Models\Branch::with('clinic')
                                    ->where('city_id', $cityId)
                                    ->where('clinic_id', $clinic->id);
                                
                                return $query->get()->mapWithKeys(function ($branch) use ($clinic) {
                                    return [$branch->id => $clinic->name . ' - ' . $branch->name];
                                });
                            }
                            return [];
                        }
                        
                        // Для остальных пользователей - все филиалы
                        $query = \App\Models\Branch::with('clinic')->where('city_id', $cityId);
                        
                        if ($clinicId) {
                            // Если выбрана клиника - показываем только её филиалы в выбранном городе
                            $query->where('clinic_id', $clinicId);
                        }
                        // Если клиника не выбрана - показываем все филиалы выбранного города
                        
                        return $query->get()->mapWithKeys(function ($branch) {
                            if ($branch->clinic) {
                                return [$branch->id => $branch->clinic->name . ' - ' . $branch->name];
                            }
                            return [$branch->id => $branch->name];
                        });
                    })
                    ->afterStateUpdated(function (callable $set) {
                        $set('doctor_id', null);
                    }),
                Select::make('doctor_id')
                    ->label('Врач')
                    ->reactive()
                    ->options(function (callable $get) {
                        $cityId = $get('city_id');
                        $clinicId = $get('clinic_id');
                        $branchId = $get('branch_id');
                        
                        $query = \App\Models\Doctor::query();
                        
                        if ($branchId) {
                            // Если выбран филиал - показываем только врачей этого филиала
                            $query->whereHas('branches', function ($q) use ($branchId) {
                                $q->where('branches.id', $branchId);
                            });
                        } elseif ($clinicId) {
                            // Если выбрана клиника - показываем врачей этой клиники
                            $query->whereHas('clinics', function ($q) use ($clinicId) {
                                $q->where('clinics.id', $clinicId);
                            });
                        } elseif ($cityId) {
                            // Если выбран только город - показываем врачей всех клиник города
                            $query->whereHas('clinics', function ($q) use ($cityId) {
                                $q->whereHas('cities', function ($cityQuery) use ($cityId) {
                                    $cityQuery->where('cities.id', $cityId);
                                });
                            });
                        } else {
                            // Если ничего не выбрано - пустой список
                            return [];
                        }
                        
                        return $query->get()->mapWithKeys(function ($doctor) {
                            return [$doctor->id => $doctor->full_name];
                        });
                    })
                    ->required(),

                Select::make('cabinet_id')
                    ->label('Кабинет')
                    ->reactive()
                    ->options(function (callable $get) {
                        $branchId = $get('branch_id');
                        if (!$branchId) {
                            return [];
                        }
                        
                        return \App\Models\Cabinet::where('branch_id', $branchId)
                            ->pluck('name', 'id');
                    })
                    ->required(),

                DateTimePicker::make('appointment_datetime')
                    ->label('Дата и время приема')
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y H:i')
                    ->seconds(false)
                    ->rules([
                        function () {
                            return function (string $attribute, $value, \Closure $fail) {
                                if (!$value) return;
                                
                                $cabinetId = request()->input('cabinet_id');
                                if (!$cabinetId) return;
                                
                                // Проверяем, не занят ли слот (исключаем текущую запись при редактировании)
                                $query = Application::query()
                                    ->where('cabinet_id', $cabinetId)
                                    ->where('appointment_datetime', $value);
                                
                                // Если это редактирование, исключаем текущую запись
                                if (request()->route('record')) {
                                    $query->where('id', '!=', request()->route('record'));
                                }
                                
                                $isOccupied = $query->exists();
                                
                                if ($isOccupied) {
                                    $fail('Этот временной слот уже занят.');
                                }
                            };
                        }
                    ]),

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
                TextColumn::make('clinic.name')
                    ->label('Клиника')
                    ->searchable(),
                TextColumn::make('branch.name')
                    ->label('Филиал')
                    ->searchable()
                    ->formatStateUsing(function ($record) {
                        return $record->branch ? $record->branch->name : '-';
                    }),
                TextColumn::make('full_name')
                    ->label('Имя ребенка')
                    ->searchable(),
                TextColumn::make('appointment_datetime')
                    ->label('Дата и время приема')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('appointment_status')
                    ->label('Статус')
                    ->formatStateUsing(function ($record) {
                        return $record->appointment_datetime ? 'Запись на прием' : 'Заявка';
                    })
                    ->badge()
                    ->color(function ($record) {
                        return $record->appointment_datetime ? 'success' : 'warning';
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('appointment_datetime', $direction);
                    }),
            ])
            ->filters([
                ApplicationFilters::make(),
                TernaryFilter::make('appointment_status')
                    ->label('Тип записи')
                    ->placeholder('Все записи')
                    ->trueLabel('Записи на прием')
                    ->falseLabel('Заявки')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('appointment_datetime')
                            ->whereNotNull('cabinet_id')
                            ->whereNotNull('branch_id'),
                        false: fn (Builder $query) => $query->where(function ($q) {
                            $q->whereNull('appointment_datetime')
                              ->orWhereNull('cabinet_id')
                              ->orWhereNull('branch_id');
                        }),
                        blank: fn (Builder $query) => $query,
                    ),
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

    public static function getWidgets(): array
    {
        return [
            AppointmentCalendarWidget::class,
        ];
    }
}
