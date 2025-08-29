<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BranchResource\Pages;
use App\Filament\Resources\BranchResource\RelationManagers;
use App\Models\Branch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    
    protected static ?string $modelLabel = 'Филиал';
    protected static ?string $pluralModelLabel = 'Филиалы';
    protected static ?string $navigationLabel = 'Филиалы';
    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Получаем текущего пользователя
        $user = auth()->user();

        // Если пользователь с ролью 'doctor' — не показываем ничего
        if ($user && $user->hasRole('doctor')) {
            $query->whereRaw('1 = 0'); // Всегда false
        }
        // Если пользователь с ролью 'partner' — показываем только филиалы их клиники
        elseif ($user && $user->hasRole('partner')) {
            $query->where('clinic_id', $user->clinic_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();
        $isPartner = $user && $user->hasRole('partner');
        
        return $form
            ->schema([
                Forms\Components\Select::make('clinic_id')
                    ->relationship(
                        'clinic', 
                        'name',
                        fn ($query) => $isPartner ? $query->where('id', $user->clinic_id) : $query
                    )
                    ->required()
                    ->label('Клиника')
                    ->searchable()
                    ->preload()
                    ->reactive()
                    ->default($isPartner ? $user->clinic_id : null)
                    ->disabled($isPartner)
                    ->afterStateUpdated(fn (callable $set) => $set('city_id', null)),
                Forms\Components\Select::make('city_id')
                    ->label('Город')
                    ->required()
                    ->searchable()
                    ->options(function (callable $get) {
                        $clinicId = $get('clinic_id');
                        if (!$clinicId) {
                            return [];
                        }
                        
                        return \App\Models\Clinic::find($clinicId)
                            ?->cities()
                            ->pluck('name', 'id')
                            ->toArray() ?? [];
                    })
                    ->disabled(fn (callable $get): bool => !$get('clinic_id')),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->label('Название филиала')
                    ->maxLength(500),
                Forms\Components\Textarea::make('address')
                    ->label('Адрес')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('phone')
                    ->label('Телефон')
                    ->tel()
                    ->maxLength(50),
                Forms\Components\Select::make('status')
                    ->label('Статус')
                    ->options([
                        1 => 'Активный',
                        0 => 'Неактивный',
                    ])
                    ->required()
                    ->default(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('clinic.name')
                    ->label('Клиника')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('city.name')
                    ->label('Город')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Название филиала')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('address')
                    ->label('Адрес')
                    ->limit(50)
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        '1' => 'Активный',
                        '0' => 'Неактивный',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        '1' => 'success',
                        '0' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Обновлен')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('clinic_id')
                    ->relationship('clinic', 'name')
                    ->label('Клиника')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('city_id')
                    ->relationship('city', 'name')
                    ->label('Город')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        1 => 'Активный',
                        0 => 'Неактивный',
                    ]),
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
            RelationManagers\DoctorsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        $user = auth()->user();
        
        $pages = [
            'index' => Pages\ListBranches::route('/'),
        ];
        
        // Только не-doctor пользователи могут создавать и редактировать филиалы
        if (!$user || !$user->hasRole('doctor')) {
            $pages['create'] = Pages\CreateBranch::route('/create');
            $pages['edit'] = Pages\EditBranch::route('/{record}/edit');
        }
        
        return $pages;
    }
}
