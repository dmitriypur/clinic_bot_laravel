<?php

namespace App\Filament\Resources;

use App\Filament\Filters\ApplicationFilters;
use App\Filament\Resources\ApplicationResource\Pages;
use App\Filament\Resources\ApplicationResource\RelationManagers;
use App\Filament\Widgets\AppointmentCalendarWidget;
use App\Models\Application;
use App\Models\Clinic;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\Actions as FormActions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Models\ApplicationStatus;
use Filament\Tables\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use App\Filament\Exports\ApplicationExporter;
use Filament\Support\Colors\Color;

class ApplicationResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static ?string $navigationLabel = 'Журнал приемов';
    protected static ?string $pluralNavigationLabel = 'Журнал приемов';
    protected static ?string $pluralLabel = 'Журнал приемов';
    protected static ?string $label = 'Журнал приемов';
    protected static ?string $navigationGroup = 'Журнал приемов';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?int $navigationSort = 6;

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

        // Показываем только заявки из админки (source = null)
        $query->whereHas('status', function ($query) {
            $query->where('type', 'appointment')
                ->orWhere('slug', 'appointment');
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
                    }),

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
                    }),

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

                                $cabinetId = data_get(request()->all(), 'data.cabinet_id') ?? request()->input('cabinet_id');
                                if (!$cabinetId) return;

                                try {
                                    $timezone = config('app.timezone', 'UTC');
                                    $carbon = $value instanceof Carbon
                                        ? $value->copy()->setTimezone($timezone)
                                        : Carbon::parse($value, $timezone);

                                    $slotUtc = $carbon->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
                                } catch (\Throwable $exception) {
                                    return;
                                }

                                // Проверяем, не занят ли слот (исключаем текущую запись при редактировании)
                                $query = Application::query()
                                    ->where('cabinet_id', $cabinetId)
                                    ->where('appointment_datetime', $slotUtc);

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
                    ->hidden()
                    ->numeric(),
                TextInput::make('tg_chat_id')
                    ->label('ID чата в Telegram')
                    ->hidden()
                    ->numeric(),
                Toggle::make('send_to_1c')
                    ->label('Отправить в 1С')
                    ->hidden(),

                Select::make('status_id')
                    ->label('Статус заявки')
                    ->relationship('status', 'name', fn ($query) => $query->where('type', 'appointment'))
                    ->default(fn () => ApplicationStatus::where('slug', 'appointment_scheduled')->first()?->id)
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Section::make('Информация о приеме')
                    ->columns(3)
                    ->hidden(fn (?string $operation): bool => $operation === 'create')
                    ->schema([
                        Placeholder::make('appointment_status_display')
                            ->label('Статус приема')
                            ->extraAttributes(function (?Application $record): array {
                                $isCompleted = false;

                                if ($record) {
                                    $isCompleted = $record->isCompleted() || $record->appointment?->isCompleted();
                                }

                                return [
                                    'class' => 'rounded-md px-4 py-3 text-sm font-medium text-white',
                                    'style' => $isCompleted ? 'background: #5eb75e;' : 'background: #db4c4c;',
                                ];
                            })
                            ->content(fn (?Application $record): string => $record?->getStatusLabel() ?? '—'),
                        Placeholder::make('appointment_started_at_display')
                            ->label('Начало приема')
                            ->content(function (?Application $record): string {
                                $startedAt = $record?->appointment?->started_at;

                                return $startedAt
                                    ? $startedAt->copy()->setTimezone(config('app.timezone', 'UTC'))->format('d.m.Y H:i')
                                    : '—';
                            }),
                        Placeholder::make('appointment_completed_at_display')
                            ->label('Окончание приема')
                            ->content(function (?Application $record): string {
                                $completedAt = $record?->appointment?->completed_at;

                                return $completedAt
                                    ? $completedAt->copy()->setTimezone(config('app.timezone', 'UTC'))->format('d.m.Y H:i')
                                    : '—';
                            }),
                        Placeholder::make('appointment_notes_display')
                            ->label('Заметки врача')
                            ->content(fn (?Application $record): string => $record?->appointment?->notes ?? '—')
                            ->columnSpanFull(),
                        FormActions::make([
                            FormAction::make('startAppointment')
                                ->label('Начать прием')
                                ->icon('heroicon-o-play')
                                ->color('success')
                                ->visible(function (?Application $record): bool {
                                    if (! $record) {
                                        return false;
                                    }

                                    $user = auth()->user();

                                    return $record->isScheduled()
                                        && $user
                                        && ($user->isDoctor() || $user->isPartner() || $user->isSuperAdmin());
                                })
                                ->requiresConfirmation()
                                ->action(function (?Application $record, \Filament\Resources\Pages\EditRecord $livewire) {
                                    if (! $record) {
                                        return;
                                    }

                                    if ($record->startAppointment()) {
                                        $livewireRecord = $livewire->getRecord();
                                        $livewireRecord->refresh()->load(['appointment', 'status']);
                                        $livewire->refreshFormData(['status_id']);

                                        Notification::make()
                                            ->title('Прием начат')
                                            ->body('Прием пациента успешно начат')
                                            ->success()
                                            ->send();

                                        $livewire->dispatch('refetchEvents');
                                    } else {
                                        Notification::make()
                                            ->title('Ошибка')
                                            ->body('Не удалось начать прием')
                                            ->danger()
                                            ->send();
                                    }
                                })
                                ->modal(false),
                            FormAction::make('completeAppointment')
                                ->label('Завершить прием')
                                ->icon('heroicon-o-check-circle')
                                ->color('warning')
                                ->visible(function (?Application $record): bool {
                                    if (! $record) {
                                        return false;
                                    }

                                    $user = auth()->user();

                                    return $record->isInProgress()
                                        && $user
                                        && ($user->isDoctor() || $user->isPartner() || $user->isSuperAdmin());
                                })
                                ->requiresConfirmation()
                                ->action(function (?Application $record, \Filament\Resources\Pages\EditRecord $livewire) {
                                    if (! $record) {
                                        return;
                                    }

                                    if ($record->completeAppointment()) {
                                        $livewireRecord = $livewire->getRecord();
                                        $livewireRecord->refresh()->load(['appointment', 'status']);
                                        $livewire->refreshFormData(['status_id']);

                                        Notification::make()
                                            ->title('Прием завершен')
                                            ->body('Прием пациента успешно завершен')
                                            ->success()
                                            ->send();

                                        $livewire->dispatch('refetchEvents');
                                    } else {
                                        Notification::make()
                                            ->title('Ошибка')
                                            ->body('Не удалось завершить прием')
                                            ->danger()
                                            ->send();
                                    }
                                })
                                ->modal(false),
                        ])
                            ->key('appointment-actions')
                            ->fullWidth()
                            ->visible(fn (?Application $record): bool => ! empty($record)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([
                ExportAction::make()
                    ->exporter(ApplicationExporter::class)
                    ->formats([ExportFormat::Csv, ExportFormat::Xlsx])
                    ->columnMapping(true) // показывает выбор колонок в модальном окне
                    ->successNotificationTitle('Экспорт завершен')
                    ->successNotificationMessage('Файл готов к скачиванию!'),
            ])
            ->columns([
                TextColumn::make('clinic.name')
                    ->label('Клиника')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('branch.name')
                    ->label('Филиал')
                    ->searchable()
                    ->sortable()
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
                TextColumn::make("promo_code")
                    ->label("Промокод")
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status.name')
                    ->label('Статус')
                    ->badge()
                    ->color(fn ($record) => $record->status?->getBadgeColor() ?? Color::Gray)
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('clinic')
                    ->relationship('clinic', 'name')
                    ->label('Клиника')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('branch')
                    ->relationship('branch', 'name')
                    ->label('Филиал')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('appointment_datetime')
                    ->form([
                        DatePicker::make('from')->label('Дата приема с'),
                        DatePicker::make('until')->label('Дата приема по'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date) => $query->whereDate('appointment_datetime', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date) => $query->whereDate('appointment_datetime', '<=', $date),
                            );
                    }),
                Tables\Filters\Filter::make('promo_code')
                    ->form([
                        TextInput::make('value')->label('Промокод'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['value'],
                                fn (Builder $query, $code) => $query->where('promo_code', 'like', "%{$code}%"),
                            );
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->relationship('status', 'name', fn (Builder $query) => $query->where('type', 'appointment'))
                    ->label('Статус')
                    ->searchable()
                    ->preload(),
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
