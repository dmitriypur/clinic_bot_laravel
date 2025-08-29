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
    protected static ?string $pluralLabel = 'Врачи';
    protected static ?string $label = 'Врач';
    protected static ?int $navigationSort = 4;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Получаем текущего пользователя
        $user = auth()->user();

        if ($user) {
            // Если пользователь с ролью 'doctor' — показываем только его самого
            if ($user->hasRole('doctor')) {
                $query->where('id', $user->doctor_id);
            }
            // Если пользователь с ролью 'partner' — показываем только врачей их клиники
            elseif ($user->hasRole('partner')) {
                $query->whereHas('clinics', function ($query) use ($user) {
                    $query->where('clinic_id', $user->clinic_id);
                });
            }
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();
        $isDoctor = $user && $user->hasRole('doctor');
        
        return $form
            ->schema([
                TextInput::make('last_name')
                    ->label('Фамилия')
                    ->required()
                    ->disabled($isDoctor),
                TextInput::make('first_name')
                    ->label('Имя')
                    ->required()
                    ->disabled($isDoctor),
                TextInput::make('second_name')
                    ->label('Отчество')
                    ->disabled($isDoctor),
                TextInput::make('experience')
                    ->label('Опыт')
                    ->required()
                    ->numeric(),
                TextInput::make('age')
                    ->label('Возраст')
                    ->required()
                    ->numeric()
                    ->disabled($isDoctor),

                Select::make('clinic_id')
                    ->label('Клиники')
                    ->multiple()
                    ->relationship('clinics', 'name')
                    ->disabled($isDoctor)
                    ->live()
                    ->searchable()
                    ->preload()
                    ->afterStateUpdated(function (callable $set) {
                        // Сбрасываем филиалы при изменении клиник
                        $set('branch_ids', []);
                    })
                    ->columnSpanFull(),
                
                Select::make('branch_ids')
                    ->label('Филиалы')
                    ->multiple()
                    ->live()
                    ->options(function (callable $get) {
                        $clinicIds = $get('clinic_id');
                        
                        if (empty($clinicIds)) {
                            return [];
                        }
                        
                        return \App\Models\Branch::with(['clinic', 'city'])
                            ->whereIn('clinic_id', $clinicIds)
                            ->get()
                            ->mapWithKeys(function ($branch) {
                                return [$branch->id => $branch->clinic->name . ' - ' . $branch->name . ' (' . $branch->city->name . ')'];
                            });
                    })
                    ->searchable()
                    ->disabled($isDoctor)
                    ->columnSpanFull(),
                TextInput::make('age_admission_from')
                    ->label('Возраст приёма с')
                    ->required()
                    ->numeric(),
                TextInput::make('age_admission_to')
                    ->label('Возраст приёма до')
                    ->required()
                    ->numeric(),
                FileUpload::make('photo_src')
                    ->label('Фото'),
                FileUpload::make('diploma_src')
                    ->label('Диплом'),
                Toggle::make('status')
                    ->label('Активен')
                    ->default(true)
                    ->disabled($isDoctor),
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
                    ->searchable()
                    ->limit(30),
                TextColumn::make('branches.name')
                    ->label('Филиалы')
                    ->formatStateUsing(function ($record) {
                        return $record->branches->map(function ($branch) {
                            return $branch->clinic->name . ' - ' . $branch->name;
                        })->join(', ');
                    })
                    ->limit(50)
                    ->tooltip(function ($record) {
                        return $record->branches->map(function ($branch) {
                            return $branch->clinic->name . ' - ' . $branch->name . ' (' . $branch->city->name . ')';
                        })->join("\n");
                    }),
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
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(function () {
                            $user = auth()->user();
                            return !$user || !$user->hasRole('doctor');
                        }),
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
        $user = auth()->user();
        
        $pages = [
            'index' => Pages\ListDoctors::route('/'),
        ];
        
        // Только не-doctor пользователи могут создавать врачей
        if (!$user || !$user->hasRole('doctor')) {
            $pages['create'] = Pages\CreateDoctor::route('/create');
        }
        
        $pages['edit'] = Pages\EditDoctor::route('/{record}/edit');
        
        return $pages;
    }
}
