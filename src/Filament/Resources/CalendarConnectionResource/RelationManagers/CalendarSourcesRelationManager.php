<?php

namespace XaviCabot\FilamentAppointments\Filament\Resources\CalendarConnectionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CalendarSourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'calendarSources';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('filament-appointments::messages.calendar.sources');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('filament-appointments::messages.calendar.calendar_name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('external_calendar_id')
                    ->label(__('filament-appointments::messages.calendar.calendar_id'))
                    ->copyable()
                    ->limit(40),
                Tables\Columns\IconColumn::make('primary')
                    ->label(__('filament-appointments::messages.calendar.primary'))
                    ->boolean()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('included')
                    ->label(__('filament-appointments::messages.calendar.included'))
                    ->sortable(),
            ])
            ->filters([])
            ->actions([])
            ->bulkActions([]);
    }
}
