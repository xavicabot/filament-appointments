<?php

namespace XaviCabot\FilamentAppointments\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use XaviCabot\FilamentAppointments\Models\AppointmentRule;
use XaviCabot\FilamentAppointments\Filament\Resources\AppointmentRuleResource\Pages;

class AppointmentRuleResource extends Resource
{
    protected static ?string $model = AppointmentRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    public static function getNavigationGroup(): ?string
    {
        return __('filament-appointments::messages.nav_group');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('filament-appointments::messages.owner'))
                    ->description(__('filament-appointments::messages.select_agent'))
                    ->schema(static::ownerFormFields())
                    ->columns(2),
                Forms\Components\Section::make(__('filament-appointments::messages.rules.schedule'))
                    ->description(__('filament-appointments::messages.rules.schedule_description'))
                    ->schema([
                        Forms\Components\CheckboxList::make('weekdays')
                            ->label(__('filament-appointments::messages.rules.weekdays'))
                            ->helperText(__('filament-appointments::messages.rules.weekdays_help'))
                            ->options(static::getWeekdayOptions())
                            ->required()
                            ->columns(4)
                            ->visible(fn (string $operation): bool => $operation === 'create'),
                        Forms\Components\Select::make('weekday')
                            ->label(__('filament-appointments::messages.rules.weekday'))
                            ->options(static::getWeekdayOptions())
                            ->required()
                            ->visible(fn (string $operation): bool => $operation === 'edit'),
                        Forms\Components\TimePicker::make('start_time')
                            ->label(__('filament-appointments::messages.start_time'))
                            ->seconds(false)
                            ->required(),
                        Forms\Components\TimePicker::make('end_time')
                            ->label(__('filament-appointments::messages.end_time'))
                            ->seconds(false)
                            ->required(),
                        Forms\Components\TextInput::make('interval_minutes')
                            ->label(__('filament-appointments::messages.rules.interval'))
                            ->numeric()
                            ->default(30)
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label(__('filament-appointments::messages.active'))
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ...static::ownerTableColumns(),
                Tables\Columns\TextColumn::make('weekday')
                    ->label(__('filament-appointments::messages.rules.weekday'))
                    ->formatStateUsing(fn ($state): string => static::getWeekdayOptions()[$state] ?? (string) $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('start_time')
                    ->label(__('filament-appointments::messages.start'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('end_time')
                    ->label(__('filament-appointments::messages.end'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('interval_minutes')
                    ->label(__('filament-appointments::messages.rules.interval_short'))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('filament-appointments::messages.active'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('filament-appointments::messages.active')),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('filament-appointments::messages.rules.new'))
                    ->using(function (array $data, string $model): Model {
                        $weekdays = $data['weekdays'];
                        unset($data['weekdays']);
                        $last = null;
                        foreach ($weekdays as $weekday) {
                            $last = $model::create(array_merge($data, ['weekday' => (int) $weekday]));
                        }

                        return $last;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppointmentRules::route('/'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-appointments::messages.rules.label');
    }

    public static function getPluralLabel(): string
    {
        return __('filament-appointments::messages.rules.label');
    }

    /**
     * @return array<int, string>
     */
    protected static function getWeekdayOptions(): array
    {
        return [
            1 => __('filament-appointments::messages.monday'),
            2 => __('filament-appointments::messages.tuesday'),
            3 => __('filament-appointments::messages.wednesday'),
            4 => __('filament-appointments::messages.thursday'),
            5 => __('filament-appointments::messages.friday'),
            6 => __('filament-appointments::messages.saturday'),
            7 => __('filament-appointments::messages.sunday'),
        ];
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
