<?php

namespace App\Filament\Resources\Webhooks;

use App\Filament\Resources\Webhooks\Pages\CreateWebhook;
use App\Filament\Resources\Webhooks\Pages\EditWebhook;
use App\Filament\Resources\Webhooks\Pages\ListWebhooks;
use App\Filament\Resources\Webhooks\Schemas\WebhookForm;
use App\Filament\Resources\Webhooks\Tables\WebhooksTable;
use App\Models\Webhook;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WebhookResource extends Resource
{
    protected static ?string $model = Webhook::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return WebhookForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WebhooksTable::configure($table);
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
            'index' => ListWebhooks::route('/'),
            'create' => CreateWebhook::route('/create'),
            'edit' => EditWebhook::route('/{record}/edit'),
        ];
    }
}
