<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppointmentResource\Pages;
use App\Filament\Resources\AppointmentResource\RelationManagers;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Enums\AppointmentStatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AppointmentResource extends Resource
{
    protected static ?string $model = Appointment::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    
    protected static ?string $navigationLabel = 'Приемы';
    
    protected static ?string $modelLabel = 'Прием';
    
    protected static ?string $pluralModelLabel = 'Приемы';
    
    protected static ?string $navigationGroup = 'Журнал приемов';
    protected static ?int $navigationSort = 7;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Получаем текущего пользователя
        $user = auth()->user();

        // Если пользователь с ролью 'partner' — показываем только приемы его клиники
        if ($user->hasRole('partner')) {
            $clinic = Clinic::query()->where('id', $user->clinic_id)->first();
            if ($clinic) {
                $query->whereHas('application', function ($query) use ($clinic) {
                    $query->where('clinic_id', $clinic->id);
                });
            }
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([    
                Forms\Components\Section::make('Детали приема')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options(AppointmentStatus::options())
                            ->required()
                            ->default(AppointmentStatus::IN_PROGRESS),
                            
                        Forms\Components\DateTimePicker::make('started_at')
                            ->label('Начало приема')
                            ->required(),
                            
                        Forms\Components\DateTimePicker::make('completed_at')
                            ->label('Окончание приема'),
                            
                        Forms\Components\Textarea::make('notes')
                            ->label('Заметки врача')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Информация о заявке')
                    ->schema([
                        Forms\Components\TextInput::make('patient_full_name')
                            ->label('ФИО пациента')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(function (Forms\Get $get) {
                                $info = $get('patient_info');
                                return $info['full_name'] ?? '';
                            }),
                            
                        Forms\Components\TextInput::make('patient_parent')
                            ->label('ФИО родителя')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(function (Forms\Get $get) {
                                $info = $get('patient_info');
                                return $info['full_name_parent'] ?? '';
                            }),
                            
                        Forms\Components\TextInput::make('patient_birth_date')
                            ->label('Дата рождения')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(function (Forms\Get $get) {
                                $info = $get('patient_info');
                                return $info['birth_date'] ?? '';
                            }),
                            
                        Forms\Components\TextInput::make('patient_phone')
                            ->label('Телефон')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(function (Forms\Get $get) {
                                $info = $get('patient_info');
                                return $info['phone'] ?? '';
                            }),
                            
                        Forms\Components\TextInput::make('appointment_datetime')
                            ->label('Дата и время приема')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(function (Forms\Get $get) {
                                $info = $get('appointment_info');
                                return $info['datetime'] ?? '';
                            }),
                            
                        Forms\Components\TextInput::make('appointment_city')
                            ->label('Город')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(function (Forms\Get $get) {
                                $info = $get('appointment_info');
                                return $info['city'] ?? '';
                            }),
                            
                        Forms\Components\TextInput::make('appointment_clinic')
                            ->label('Клиника')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(function (Forms\Get $get) {
                                $info = $get('appointment_info');
                                return $info['clinic'] ?? '';
                            }),
                            
                        Forms\Components\TextInput::make('appointment_branch')
                            ->label('Филиал')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(function (Forms\Get $get) {
                                $info = $get('appointment_info');
                                return $info['branch'] ?? '';
                            }),
                            
                        Forms\Components\TextInput::make('appointment_doctor')
                            ->label('Врач')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(function (Forms\Get $get) {
                                $info = $get('appointment_info');
                                return $info['doctor'] ?? '';
                            }),
                            
                        Forms\Components\TextInput::make('appointment_cabinet')
                            ->label('Кабинет')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(function (Forms\Get $get) {
                                $info = $get('appointment_info');
                                return $info['cabinet'] ?? '';
                            }),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('application.full_name')
                    ->label('Пациент')
                    ->sortable()
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('application.appointment_datetime')
                    ->label('Дата и время')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('application.doctor.name')
                    ->label('Врач')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('application.clinic.name')
                    ->label('Клиника')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (AppointmentStatus $state): string => $state->getColor())
                    ->formatStateUsing(fn (AppointmentStatus $state): string => $state->getLabel()),
                    
                Tables\Columns\TextColumn::make('started_at')
                    ->label('Начало')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Окончание')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('duration')
                    ->label('Длительность')
                    ->getStateUsing(function ($record) {
                        $duration = $record->getDurationInMinutes();
                        return $duration ? "{$duration} мин" : '-';
                    }),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(AppointmentStatus::options()),
                    
                Tables\Filters\Filter::make('started_at')
                    ->form([
                        Forms\Components\DatePicker::make('started_from')
                            ->label('Начало с'),
                        Forms\Components\DatePicker::make('started_until')
                            ->label('Начало до'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['started_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('started_at', '>=', $date),
                            )
                            ->when(
                                $data['started_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('started_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('started_at', 'desc');
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
            'index' => Pages\ListAppointments::route('/'),
            'create' => Pages\CreateAppointment::route('/create'),
            'edit' => Pages\EditAppointment::route('/{record}/edit'),
        ];
    }
}
