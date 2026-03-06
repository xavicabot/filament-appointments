<?php

namespace XaviCabot\FilamentAppointments\Filament\Resources;

use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use XaviCabot\FilamentAppointments\Filament\Resources\AppointmentBookingResource\Pages;
use XaviCabot\FilamentAppointments\Models\AppointmentBooking;

class AppointmentBookingResource extends Resource
{
    protected static ?string $model = AppointmentBooking::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    public static function getNavigationGroup(): ?string
    {
        return __('filament-appointments::messages.nav_group');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ...static::ownerTableColumns(),
                ...static::clientTableColumns(),
                Tables\Columns\TextColumn::make('date')
                    ->label(__('filament-appointments::messages.date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('start_time')
                    ->label(__('filament-appointments::messages.start'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('end_time')
                    ->label(__('filament-appointments::messages.end'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('filament-appointments::messages.bookings.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'confirmed' => 'success',
                        'pending' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'confirmed' => __('filament-appointments::messages.bookings.status_confirmed'),
                        'pending' => __('filament-appointments::messages.bookings.status_pending'),
                        'cancelled' => __('filament-appointments::messages.bookings.status_cancelled'),
                        default => $state,
                    }),
                Tables\Columns\IconColumn::make('metadata.meet_link')
                    ->label(__('filament-appointments::messages.bookings.synced'))
                    ->icon(fn ($state): string => $state ? 'heroicon-o-video-camera' : 'heroicon-o-video-camera-slash')
                    ->color(fn ($state): string => $state ? 'success' : 'gray')
                    ->tooltip(fn ($state): string => $state
                        ? __('filament-appointments::messages.bookings.meet_linked')
                        : __('filament-appointments::messages.bookings.no_meet_link')),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('filament-appointments::messages.bookings.status'))
                    ->options([
                        'confirmed' => __('filament-appointments::messages.bookings.status_confirmed'),
                        'pending' => __('filament-appointments::messages.bookings.status_pending'),
                        'cancelled' => __('filament-appointments::messages.bookings.status_cancelled'),
                    ]),
                Tables\Filters\Filter::make('date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label(__('filament-appointments::messages.blocks.from')),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label(__('filament-appointments::messages.blocks.until')),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('date', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('date', '<=', $date));
                    }),
                ...static::ownerTableFilter(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('cancel')
                    ->label(__('filament-appointments::messages.bookings.cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (AppointmentBooking $record): bool => $record->status !== 'cancelled')
                    ->action(function (AppointmentBooking $record) {
                        $record->update(['status' => 'cancelled']);

                        Notification::make()
                            ->title(__('filament-appointments::messages.bookings.cancelled_title'))
                            ->body(__('filament-appointments::messages.bookings.cancelled_body'))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->schema([
                Section::make(__('filament-appointments::messages.bookings.details'))
                    ->schema([
                        Infolists\Components\TextEntry::make('date')
                            ->label(__('filament-appointments::messages.date'))
                            ->date(),
                        Infolists\Components\TextEntry::make('start_time')
                            ->label(__('filament-appointments::messages.start_time')),
                        Infolists\Components\TextEntry::make('end_time')
                            ->label(__('filament-appointments::messages.end_time')),
                        Infolists\Components\TextEntry::make('status')
                            ->label(__('filament-appointments::messages.bookings.status'))
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'confirmed' => 'success',
                                'pending' => 'warning',
                                'cancelled' => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'confirmed' => __('filament-appointments::messages.bookings.status_confirmed'),
                                'pending' => __('filament-appointments::messages.bookings.status_pending'),
                                'cancelled' => __('filament-appointments::messages.bookings.status_cancelled'),
                                default => $state,
                            }),
                    ])
                    ->columns(2),

                Section::make(__('filament-appointments::messages.owner'))
                    ->description(__('filament-appointments::messages.select_agent'))
                    ->schema(static::ownerInfolistEntries())
                    ->columns(2),

                Section::make(__('filament-appointments::messages.bookings.client'))
                    ->schema(static::clientInfolistEntries())
                    ->columns(2),

                Section::make(__('filament-appointments::messages.bookings.google_calendar'))
                    ->schema([
                        Infolists\Components\TextEntry::make('metadata.meet_link')
                            ->label(__('filament-appointments::messages.bookings.meet_link'))
                            ->url(fn ($state) => $state)
                            ->openUrlInNewTab()
                            ->copyable()
                            ->placeholder(__('filament-appointments::messages.bookings.no_meet_link')),
                    ]),

                Section::make(__('filament-appointments::messages.bookings.metadata'))
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('metadata')
                            ->label(__('filament-appointments::messages.bookings.metadata')),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppointmentBookings::route('/'),
            'view' => Pages\ViewAppointmentBooking::route('/{record}'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-appointments::messages.bookings.label');
    }

    public static function getPluralLabel(): string
    {
        return __('filament-appointments::messages.bookings.label');
    }

    public static function getModelLabel(): string
    {
        return __('filament-appointments::messages.bookings.singular_label');
    }

    protected static function ownerTableColumns(): array
    {
        $ownerModel = config('filament-appointments.owner_model');

        if ($ownerModel) {
            $label = config('filament-appointments.owner_label', 'name');

            return [
                Tables\Columns\TextColumn::make('owner_id')
                    ->label(__('filament-appointments::messages.owner'))
                    ->formatStateUsing(function ($state) use ($ownerModel, $label) {
                        return $ownerModel::find($state)?->$label ?? $state;
                    })
                    ->sortable()
                    ->searchable(),
            ];
        }

        return [
            Tables\Columns\TextColumn::make('owner_type')
                ->label(__('filament-appointments::messages.owner_type'))
                ->sortable()
                ->searchable(),
            Tables\Columns\TextColumn::make('owner_id')
                ->label(__('filament-appointments::messages.owner_id'))
                ->sortable(),
        ];
    }

    protected static function clientTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('client_type')
                ->label(__('filament-appointments::messages.bookings.client_type'))
                ->sortable()
                ->searchable(),
            Tables\Columns\TextColumn::make('client_id')
                ->label(__('filament-appointments::messages.bookings.client_id'))
                ->sortable(),
        ];
    }

    protected static function ownerTableFilter(): array
    {
        $ownerModel = config('filament-appointments.owner_model');

        if (! $ownerModel) {
            return [];
        }

        $label = config('filament-appointments.owner_label', 'name');
        $instance = new $ownerModel;

        return [
            Tables\Filters\SelectFilter::make('owner_id')
                ->label(__('filament-appointments::messages.owner'))
                ->options(fn () => $ownerModel::pluck($label, $instance->getKeyName())),
        ];
    }

    protected static function ownerInfolistEntries(): array
    {
        $ownerModel = config('filament-appointments.owner_model');

        if ($ownerModel) {
            $label = config('filament-appointments.owner_label', 'name');

            return [
                Infolists\Components\TextEntry::make('owner_id')
                    ->label(__('filament-appointments::messages.owner'))
                    ->formatStateUsing(function ($state) use ($ownerModel, $label) {
                        return $ownerModel::find($state)?->$label ?? $state;
                    }),
            ];
        }

        return [
            Infolists\Components\TextEntry::make('owner_type')
                ->label(__('filament-appointments::messages.owner_type')),
            Infolists\Components\TextEntry::make('owner_id')
                ->label(__('filament-appointments::messages.owner_id')),
        ];
    }

    protected static function clientInfolistEntries(): array
    {
        return [
            Infolists\Components\TextEntry::make('client_type')
                ->label(__('filament-appointments::messages.bookings.client_type')),
            Infolists\Components\TextEntry::make('client_id')
                ->label(__('filament-appointments::messages.bookings.client_id')),
        ];
    }
}
