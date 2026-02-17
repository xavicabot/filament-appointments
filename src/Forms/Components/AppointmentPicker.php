<?php

namespace XaviCabot\FilamentAppointments\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;
use XaviCabot\FilamentAppointments\Support\SlotOwner;

class AppointmentPicker extends Field
{
    protected string $view = 'filament-appointments::forms.components.appointment-picker';

    /** @var callable|null */
    protected $ownerResolver = null;

    protected string|Closure|null $minDate = null;

    protected string|Closure|null $maxDate = null;

    public function owner(callable $resolver): static
    {
        $this->ownerResolver = $resolver;

        return $this;
    }

    public function minDate(string|\DateTimeInterface|Closure $date): static
    {
        if ($date instanceof \DateTimeInterface) {
            $date = $date->format('Y-m-d');
        }

        $this->minDate = $date;

        return $this;
    }

    public function maxDate(string|\DateTimeInterface|callable $date): static
    {
        if ($date instanceof \DateTimeInterface) {
            $date = $date->format('Y-m-d');
        }

        $this->maxDate = $date;

        return $this;
    }

    public function getSlotsEndpoint(): string
    {
        return route('filament-appointments.slots');
    }

    public function getOwner(): ?SlotOwner
    {
        if (! $this->ownerResolver) {
            return null;
        }

        $owner = $this->evaluate($this->ownerResolver);

        return $owner instanceof SlotOwner ? $owner : null;
    }

    public function getMinDate(): ?string
    {
        if (! $this->minDate) {
            return null;
        }

        $value = $this->evaluate($this->minDate);

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value ?: null;
    }

    public function getMaxDate(): ?string
    {
        if (! $this->maxDate) {
            return null;
        }

        $value = $this->evaluate($this->maxDate);

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value ?: null;
    }
}

