<?php

namespace XaviCabot\FilamentAppointments\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use XaviCabot\FilamentAppointments\Models\AppointmentBlock;
use XaviCabot\FilamentAppointments\Models\CalendarConnection;
use XaviCabot\FilamentAppointments\Filament\Resources\AppointmentBlockResource\Pages;
use XaviCabot\FilamentAppointments\Services\GoogleCalendarServiceInterface;

class AppointmentBlockResource extends Resource
{
    protected static ?string $model = AppointmentBlock::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    public static function getNavigationGroup(): ?string
    {
        return __('filament-appointments::messages.nav_group');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make(__('filament-appointments::messages.owner'))
                    ->description(__('filament-appointments::messages.select_agent'))
                    ->schema(static::ownerFormFields())
                    ->columns(2),
                Section::make(__('filament-appointments::messages.blocks.block'))
                    ->description(__('filament-appointments::messages.blocks.block_description'))
                    ->schema([
                        Forms\Components\DatePicker::make('date')
                            ->label(__('filament-appointments::messages.date'))
                            ->required(),
                        Forms\Components\TimePicker::make('start_time')
                            ->label(__('filament-appointments::messages.start_time'))
                            ->seconds(false)
                            ->required(),
                        Forms\Components\TimePicker::make('end_time')
                            ->label(__('filament-appointments::messages.end_time'))
                            ->seconds(false)
                            ->required(),
                        Forms\Components\TextInput::make('reason')
                            ->label(__('filament-appointments::messages.blocks.reason'))
                            ->maxLength(255),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ...static::ownerTableColumns(),
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
                Tables\Columns\TextColumn::make('reason')
                    ->label(__('filament-appointments::messages.blocks.reason'))
                    ->limit(40),
                Tables\Columns\TextColumn::make('source')
                    ->label(__('filament-appointments::messages.blocks.source'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'google' => __('filament-appointments::messages.blocks.source_google'),
                        default => __('filament-appointments::messages.blocks.source_manual'),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'google' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('date_from')
                            ->label(__('filament-appointments::messages.blocks.from')),
                        Forms\Components\DatePicker::make('date_until')
                            ->label(__('filament-appointments::messages.blocks.until')),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['date_from'] ?? null, fn ($query, $date): mixed => $query->whereDate('date', '>=', $date))
                            ->when($data['date_until'] ?? null, fn ($query, $date): mixed => $query->whereDate('date', '<=', $date));
                    }),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label(__('filament-appointments::messages.blocks.new')),
                Actions\Action::make('sync_google')
                    ->label(__('filament-appointments::messages.blocks.sync_google'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function () {
                        $connections = CalendarConnection::query()
                            ->where('provider', 'google')
                            ->where('status', 'connected')
                            ->get();

                        $total = 0;

                        foreach ($connections as $conn) {
                            try {
                                $ownerType = config('filament-appointments.owner_model')
                                    ? (method_exists(new (config('filament-appointments.owner_model')), 'getSlotOwnerType')
                                        ? (new (config('filament-appointments.owner_model')))->getSlotOwnerType()
                                        : 'user')
                                    : 'user';

                                $service = app(GoogleCalendarServiceInterface::class);
                                $total += $service->syncBusyBlocks($conn, $ownerType, $conn->user_id);
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title(__('filament-appointments::messages.blocks.sync_failed'))
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }
                        }

                        Notification::make()
                            ->title(__('filament-appointments::messages.blocks.synced_title'))
                            ->body(__('filament-appointments::messages.blocks.synced_body', ['count' => $total]))
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->visible(fn (AppointmentBlock $record): bool => ($record->source ?? 'manual') === 'manual'),
                Actions\DeleteAction::make()
                    ->visible(fn (AppointmentBlock $record): bool => ($record->source ?? 'manual') === 'manual'),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->checkIfRecordIsSelectableUsing(
                fn (AppointmentBlock $record): bool => ($record->source ?? 'manual') === 'manual',
            );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppointmentBlocks::route('/'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-appointments::messages.blocks.label');
    }

    public static function getPluralLabel(): string
    {
        return __('filament-appointments::messages.blocks.label');
    }

    protected static function ownerFormFields(): array
    {
        $ownerModel = config('filament-appointments.owner_model');

        if ($ownerModel) {
            $label = config('filament-appointments.owner_label', 'name');
            $instance = new $ownerModel;
            $ownerType = method_exists($instance, 'getSlotOwnerType')
                ? $instance->getSlotOwnerType()
                : 'user';

            return [
                Forms\Components\Select::make('owner_id')
                    ->label(__('filament-appointments::messages.owner'))
                    ->options(function () use ($ownerModel, $instance) {
                        return $ownerModel::all()->mapWithKeys(function ($model) use ($instance) {
                            $key = $model->{$instance->getKeyName()};
                            $display = $model->email ?? $model->name ?? $key;

                            return [$key => $display];
                        });
                    })
                    ->required()
                    ->searchable(),
                Forms\Components\Hidden::make('owner_type')
                    ->default($ownerType),
            ];
        }

        return [
            Forms\Components\TextInput::make('owner_type')
                ->label(__('filament-appointments::messages.owner_type'))
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('owner_id')
                ->label(__('filament-appointments::messages.owner_id'))
                ->numeric()
                ->required(),
        ];
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
}
