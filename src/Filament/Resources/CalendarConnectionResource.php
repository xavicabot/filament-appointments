<?php

namespace XaviCabot\FilamentAppointments\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use XaviCabot\FilamentAppointments\Filament\Resources\CalendarConnectionResource\Pages;
use XaviCabot\FilamentAppointments\Filament\Resources\CalendarConnectionResource\RelationManagers;
use XaviCabot\FilamentAppointments\Models\AppointmentRule;
use XaviCabot\FilamentAppointments\Models\CalendarConnection;
use XaviCabot\FilamentAppointments\Models\CalendarSource;
use XaviCabot\FilamentAppointments\Services\GoogleCalendarServiceInterface;

class CalendarConnectionResource extends Resource
{
    protected static ?string $model = CalendarConnection::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    public static function getNavigationGroup(): ?string
    {
        return __('filament-appointments::messages.nav_group');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('filament-appointments::messages.calendar.connection'))
                    ->schema([
                        Forms\Components\TextInput::make('user_id')
                            ->label(__('filament-appointments::messages.calendar.user_id'))
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('provider')
                            ->label(__('filament-appointments::messages.calendar.provider'))
                            ->default('google')
                            ->required()
                            ->maxLength(100),
                        Forms\Components\Select::make('status')
                            ->label(__('filament-appointments::messages.calendar.status'))
                            ->options([
                                'connected' => __('filament-appointments::messages.calendar.connected'),
                                'disconnected' => __('filament-appointments::messages.calendar.disconnected'),
                            ])
                            ->required(),
                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label(__('filament-appointments::messages.calendar.expires_at')),
                        Forms\Components\TagsInput::make('scopes')
                            ->label(__('filament-appointments::messages.calendar.scopes'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user_id')
                    ->label(__('filament-appointments::messages.calendar.user_id'))
                    ->formatStateUsing(function ($state) {
                        $ownerModel = config('filament-appointments.owner_model');
                        if ($ownerModel) {
                            $label = config('filament-appointments.owner_label', 'name');
                            return $ownerModel::find($state)?->$label ?? $state;
                        }
                        return $state;
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider')
                    ->label(__('filament-appointments::messages.calendar.provider'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('filament-appointments::messages.calendar.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'connected' => 'success',
                        'disconnected' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label(__('filament-appointments::messages.calendar.expires_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('calendar_sources_count')
                    ->label(__('filament-appointments::messages.calendar.calendars'))
                    ->counts('calendarSources')
                    ->sortable(),
            ])
            ->filters([])
            ->headerActions([
                Tables\Actions\Action::make('connect_google')
                    ->label(__('filament-appointments::messages.calendar.connect_google'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->form(static::connectGoogleFormFields())
                    ->action(function (array $data) {
                        $url = route('filament-appointments.google.redirect', ['user_id' => $data['user_id']]);

                        return redirect()->away($url);
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('sync_calendars')
                    ->label(__('filament-appointments::messages.calendar.sync'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (CalendarConnection $record) {
                        try {
                            $service = app(GoogleCalendarServiceInterface::class);
                            $calendars = $service->syncCalendars($record);

                            Notification::make()
                                ->title(__('filament-appointments::messages.calendar.calendars_synced'))
                                ->body(__('filament-appointments::messages.calendar.calendars_found', ['count' => count($calendars)]))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('filament-appointments::messages.calendar.sync_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('disconnect')
                    ->label(__('filament-appointments::messages.calendar.disconnect'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (CalendarConnection $record) {
                        CalendarSource::where('connection_id', $record->id)->delete();
                        $record->delete();

                        Notification::make()
                            ->title(__('filament-appointments::messages.calendar.disconnected_title'))
                            ->body(__('filament-appointments::messages.calendar.connection_removed'))
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function connectGoogleFormFields(): array
    {
        $ownerModel = config('filament-appointments.owner_model');

        if ($ownerModel) {
            $label = config('filament-appointments.owner_label', 'name');
            $instance = new $ownerModel;

            $ownerIds = AppointmentRule::distinct()->pluck('owner_id');

            return [
                Forms\Components\Select::make('user_id')
                    ->label(__('filament-appointments::messages.calendar.connect_for'))
                    ->options(function () use ($ownerModel, $instance, $ownerIds) {
                        return $ownerModel::whereIn($instance->getKeyName(), $ownerIds)
                            ->get()
                            ->mapWithKeys(function ($model) use ($instance) {
                                $key = $model->{$instance->getKeyName()};
                                $display = $model->email ?? $model->name ?? $key;

                                return [$key => $display];
                            });
                    })
                    ->required()
                    ->searchable(),
            ];
        }

        return [
            Forms\Components\TextInput::make('user_id')
                ->label(__('filament-appointments::messages.calendar.user_id'))
                ->numeric()
                ->required(),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CalendarSourcesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCalendarConnections::route('/'),
            'view' => Pages\ViewCalendarConnection::route('/{record}'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-appointments::messages.calendar.label');
    }

    public static function getPluralLabel(): string
    {
        return __('filament-appointments::messages.calendar.label');
    }
}
