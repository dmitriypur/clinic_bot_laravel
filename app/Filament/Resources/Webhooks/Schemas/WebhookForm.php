<?php

namespace App\Filament\Resources\Webhooks\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class WebhookForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('user_id')
                    ->required()
                    ->numeric(),
                TextInput::make('link')
                    ->required(),
                TextInput::make('secret')
                    ->required(),
            ]);
    }
}
