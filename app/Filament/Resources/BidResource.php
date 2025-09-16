<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BidResource\Pages;
use App\Filament\Resources\BidResource\RelationManagers;
use App\Models\Application;
use App\Models\Clinic;
use App\Filament\Widgets\AppointmentCalendarWidget;
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

class BidResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static ?string $navigationLabel = 'Заявки';
    protected static ?string $pluralNavigationLabel = 'Заявки';
    protected static ?string $pluralLabel = 'Заявки';
    protected static ?string $label = 'Заявка';
    protected static ?string $navigationGroup = 'Журнал приемов';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';
    protected static ?int $navigationSort = 5;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Получаем текущего пользователя
        $user = auth()->user();

        // Если пользователь с ролью 'partner' — показываем только их город
        if ($user->hasRole('partner')) {
            $clinic = Clinic::query()->where('id', $user->clinic_id)->first();
            $applications = $clinic->applications->pluck('id')->toArray();
            $query->whereIn('id', $applications);
        }

        $query->where(function ($query) {
            $query->whereHas('status', function ($query) {
                $query->where('type', '!=', 'appointment');
            })->orWhere(function ($query) {
                $query->whereNull('status_id')
                      ->whereIn('source', ['frontend', 'telegram']);
            });
        });

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
                    ->rules([
                        fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                            if (request()->isMethod('POST') && !$value) {
                                $fail("Поле ФИО ребенка обязательно для заполнения.");
                            }
                        },
                    ]),
                DatePicker::make('birth_date')
                    ->label('Дата рождения'),
                TextInput::make('phone')
                    ->label('Телефон')
                    ->tel()
                    ->rules([
                        fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                            if (request()->isMethod('POST') && !$value) {
                                $fail("Поле телефон обязательно для заполнения.");
                            }
                        },
                    ]),
                TextInput::make('promo_code')
                    ->label('Промокод'),
                Select::make('city_id')
                    ->label('Город')
                    ->live()
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
                    ->rules([
                        fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                            if (request()->isMethod('POST') && !$value) {
                                $fail("Поле город обязательно для заполнения.");
                            }
                        },
                    ])
                    ->afterStateUpdated(function (callable $set, callable $get) {
                        // Сбрасываем зависимые поля только если город действительно изменился
                        $currentCityId = $get('city_id');
                        if ($currentCityId) {
                            $set('clinic_id', null);
                            $set('branch_id', null);
                            $set('doctor_id', null);
                        }
                    }),
                Select::make('clinic_id')
                    ->label('Клиника')
                    ->live()
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
                    ->afterStateUpdated(function (callable $set, callable $get) {
                        // Сбрасываем зависимые поля только если клиника действительно изменилась
                        $currentClinicId = $get('clinic_id');
                        if ($currentClinicId) {
                            $set('branch_id', null);
                            $set('doctor_id', null);
                        }
                    })
                    ->rules([
                        fn (\Filament\Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                            // Проверяем только если форма отправляется (не при каждом изменении)
                            if (request()->isMethod('POST') && $get('city_id') && !$value) {
                                $fail("Поле клиника обязательно для заполнения.");
                            }
                        },
                    ]),
                
                Select::make('branch_id')
                    ->label('Филиал')
                    ->live()
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
                    ->afterStateUpdated(function (callable $set, callable $get) {
                        // Сбрасываем зависимые поля только если филиал действительно изменился
                        $currentBranchId = $get('branch_id');
                        if ($currentBranchId) {
                            $set('doctor_id', null);
                        }
                    })
                    ->rules([
                        fn (\Filament\Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                            // Проверяем только если форма отправляется (не при каждом изменении)
                            if (request()->isMethod('POST') && $get('city_id') && !$value) {
                                $fail("Поле клиника обязательно для заполнения.");
                            }
                        },
                    ]),
                Select::make('doctor_id')
                    ->label('Врач')
                    ->live()
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
                    ->rules([
                        fn (\Filament\Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                            // Проверяем только если форма отправляется (не при каждом изменении)
                            if (request()->isMethod('POST') && $get('city_id') && !$value) {
                                $fail("Поле клиника обязательно для заполнения.");
                            }
                        },
                    ]),

                Select::make('cabinet_id')
                    ->label('Кабинет')
                    ->live()
                    ->options(function (callable $get) {
                        $branchId = $get('branch_id');
                        if (!$branchId) {
                            return [];
                        }
                        
                        return \App\Models\Cabinet::where('branch_id', $branchId)
                            ->pluck('name', 'id');
                    })
                    ->rules([
                        fn (\Filament\Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                            // Проверяем только если форма отправляется (не при каждом изменении)
                            if (request()->isMethod('POST') && $get('branch_id') && !$value) {
                                $fail("Поле кабинет обязательно для заполнения.");
                            }
                        },
                    ]),

                TextInput::make('tg_user_id')
                    ->label('ID пользователя в Telegram')
                    ->hidden()
                    ->numeric(),
                TextInput::make('tg_chat_id')
                    ->label('ID чата в Telegram')
                    ->hidden()
                    ->numeric(),
                Toggle::make('send_to_1c')
                    ->label('Отправить в 1С')
                    ->hidden(),
                TextInput::make('source')
                    ->label('Источник создания')
                    ->readonly()
                    ->hidden(),
                Select::make('status_id')
                    ->label('Статус заявки')
                    ->relationship('status', 'name', fn ($query) => $query->where('type', 'bid'))
                    ->searchable()
                    ->preload()
                    ->live()
                    ->default(function () {
                        $newStatus = \App\Models\ApplicationStatus::where('slug', 'new')->first();
                        return $newStatus ? $newStatus->id : null;
                    }),
                DateTimePicker::make('appointment_datetime')
                    ->label('Дата и время приема')
                    ->rules([
                        fn (\Filament\Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                            // Проверяем только если статус "Запись на прием" (slug: appointment)
                            $statusId = $get('status_id');
                            if (request()->isMethod('POST') && $statusId) {
                                $status = \App\Models\ApplicationStatus::find($statusId);
                                if ($status && $status->slug === 'appointment' && !$value) {
                                    $fail("Поле дата и время приема обязательно для заполнения при статусе 'Запись на прием'.");
                                }
                            }
                        },
                    ])
                    ->native(false)
                    ->displayFormat('d.m.Y H:i')
                    ->seconds(false)
                    ->readonly()
                    ->live()
                    ->visible(function (\Filament\Forms\Get $get): bool {
                        $statusId = $get('status_id');
                        if (!$statusId) return false;
                        
                        $status = \App\Models\ApplicationStatus::find($statusId);
                        return $status && $status->slug === 'appointment';
                    })
                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                        // Обновляем связанные поля при изменении времени
                        $appointmentDatetime = $state;
                        if ($appointmentDatetime) {
                            $carbon = \Carbon\Carbon::parse($appointmentDatetime);
                            
                            // Находим кабинет по времени приема
                            $application = \App\Models\Application::where('appointment_datetime', $carbon->format('Y-m-d H:i:s'))->first();
                            if ($application) {
                                $set('city_id', $application->city_id);
                                $set('clinic_id', $application->clinic_id);
                                $set('branch_id', $application->branch_id);
                                $set('cabinet_id', $application->cabinet_id);
                                $set('doctor_id', $application->doctor_id);
                            }
                        }
                    }),
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
                TextColumn::make('source')
                    ->label('Источник')
                    ->searchable(),
                TextColumn::make('status.name')
                    ->label('Статус')
                    ->badge()
                    ->color(fn ($record) => match($record->status?->color) {
                        'blue' => 'primary',
                        'green' => 'success', 
                        'red' => 'danger',
                        'yellow' => 'warning',
                        'purple' => 'info',
                        'pink' => 'secondary',
                        'indigo' => 'info',
                        default => 'gray'
                    })
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
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
            'index' => Pages\ListBids::route('/'),
            'create' => Pages\CreateBid::route('/create'),
            'edit' => Pages\EditBid::route('/{record}/edit'),
        ];
    }
}
