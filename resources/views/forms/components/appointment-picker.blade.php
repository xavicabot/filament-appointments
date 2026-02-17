<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php
        $owner = $getOwner();
    @endphp

    <div
            wire:key="appointment-picker-{{ $owner?->type }}-{{ $owner?->id }}"
            x-data="{
            open: false,
            loading: false,
            slots: [],
            selected: $wire.$entangle('{{ $getStatePath() }}', false),
            date: '',
            ownerType: @js($owner?->type),
            ownerId: @js($owner?->id),
            minDate: @js($getMinDate()),
            maxDate: @js($getMaxDate()),
            reasonLabels: @js([
                'past' => __('filament-appointments::messages.picker.reason_past'),
                'blocked' => __('filament-appointments::messages.picker.reason_blocked'),
                'google_busy' => __('filament-appointments::messages.picker.reason_google_busy'),
                'booked' => __('filament-appointments::messages.picker.reason_booked'),
                'pending' => __('filament-appointments::messages.picker.reason_pending'),
            ]),
            init() {
                if (this.selected) {
                    const parts = String(this.selected).split(' ');
                    if (parts.length > 0 && parts[0].match(/^\d{4}-\d{2}-\d{2}$/)) {
                        this.date = parts[0];
                    }
                }

                if (!this.date) {
                    const today = new Date();
                    const month = String(today.getMonth() + 1).padStart(2, '0');
                    const day = String(today.getDate()).padStart(2, '0');
                    this.date = `${today.getFullYear()}-${month}-${day}`;
                }

                this.$watch('date', () => this.load());

                if (this.date && this.ownerType && this.ownerId) {
                    this.load();
                }
            },
            async load() {
                if (!this.date || !this.ownerType || !this.ownerId) {
                    this.slots = [];
                    return;
                }

                this.loading = true;

                try {
                    const url = new URL('{{ $getSlotsEndpoint() }}', window.location.origin);
                    url.searchParams.set('date', this.date);
                    url.searchParams.set('owner_type', this.ownerType);
                    url.searchParams.set('owner_id', this.ownerId);

                    const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                    const json = await res.json();
                    this.slots = Array.isArray(json?.slots) ? json.slots : [];
                } catch (e) {
                    console.error(e);
                    this.slots = [];
                } finally {
                    this.loading = false;
                }
            },
            pick(slot) {
                if (slot.disabled) return;
                this.selected = this.date + ' ' + slot.value;
                this.open = false;
            },
        }"
            class="fi-appointment-picker"
    >
        <div class="flex gap-3">
            <div class="w-40">
                <label class="fi-input-label block text-sm font-medium text-gray-700 mb-1">
                    {{ __('filament-appointments::messages.picker.date') }}
                </label>

                <input
                        type="date"
                        x-model="date"
                        :min="minDate"
                        :max="maxDate"
                        class="fi-input block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm"
                />
            </div>

            <div class="flex-1">
                <label class="fi-input-label block text-sm font-medium text-gray-700 mb-1">
                    {{ $getLabel() }}
                </label>

                <div class="relative">
                    <button
                            type="button"
                            class="fi-input w-full flex items-center justify-between rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
                            @click="open = !open; if (open && slots.length === 0) load()"
                    >
                        <span class="truncate" x-text="selected ? String(selected).split(' ')[1] : '{{ __('filament-appointments::messages.picker.select_time') }}'"></span>

                        <div class="ml-3 flex items-center gap-2">
                            <template x-if="loading">
                                <svg class="h-4 w-4 animate-spin text-gray-400" viewBox="0 0 24 24" fill="none">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                                </svg>
                            </template>

                            <svg class="h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </button>

                    <div
                            x-show="open"
                            x-transition
                            @click.outside="open = false"
                            class="absolute z-10 mt-1 w-full rounded-lg border border-gray-200 bg-white p-3 shadow-lg"
                    >
                        <template x-if="!loading && slots.length === 0">
                            <p class="text-sm text-gray-500">
                                {{ __('filament-appointments::messages.picker.no_slots') }}
                            </p>
                        </template>

                        <div class="grid grid-cols-3 gap-2 max-h-64 overflow-y-auto" x-show="slots.length > 0">
                            <template x-for="slot in slots" :key="slot.value">
                                <button
                                        type="button"
                                        class="text-sm px-2 py-1 rounded-md border transition"
                                        :class="{
                                        'opacity-50 cursor-not-allowed pointer-events-none bg-gray-100 border-gray-200 text-gray-400 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-500': slot.disabled,
                                        'bg-primary-600 border-primary-600 text-white': selected && String(selected).endsWith(' ' + slot.value) && !slot.disabled,
                                        'bg-white border-gray-200 text-gray-900 hover:bg-gray-50 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-800': !slot.disabled && !(selected && String(selected).endsWith(' ' + slot.value)),
                                    }"
                                        :disabled="slot.disabled"
                                        :title="slot.disabled && slot.reason ? reasonLabels[slot.reason] || slot.reason : null"
                                        @click="pick(slot)"
                                >
                                    <span x-text="slot.label"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-dynamic-component>
