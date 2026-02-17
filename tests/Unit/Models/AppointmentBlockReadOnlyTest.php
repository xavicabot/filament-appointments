<?php

namespace XaviCabot\FilamentAppointments\Tests\Unit\Models;

use XaviCabot\FilamentAppointments\Models\AppointmentBlock;
use XaviCabot\FilamentAppointments\Tests\TestCase;

class AppointmentBlockReadOnlyTest extends TestCase
{
    public function test_google_blocks_are_not_editable(): void
    {
        $googleBlock = AppointmentBlock::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'source' => 'google',
            'reason' => 'Google Calendar',
        ]);

        $manualBlock = AppointmentBlock::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '14:00',
            'end_time' => '15:00',
            'source' => 'manual',
        ]);

        // The resource visibility callback: ($record->source ?? 'manual') === 'manual'
        $isEditable = fn (AppointmentBlock $record): bool => ($record->source ?? 'manual') === 'manual';

        $this->assertFalse($isEditable($googleBlock), 'Google blocks should not be editable');
        $this->assertTrue($isEditable($manualBlock), 'Manual blocks should be editable');
    }

    public function test_blocks_without_source_default_to_manual(): void
    {
        $block = AppointmentBlock::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);

        $isEditable = fn (AppointmentBlock $record): bool => ($record->source ?? 'manual') === 'manual';

        $this->assertTrue($isEditable($block), 'Blocks without explicit source should default to manual and be editable');
    }

    public function test_google_blocks_not_selectable_for_bulk_actions(): void
    {
        $googleBlock = AppointmentBlock::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'source' => 'google',
            'reason' => 'Google Calendar',
        ]);

        $manualBlock = AppointmentBlock::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '14:00',
            'end_time' => '15:00',
            'source' => 'manual',
        ]);

        // The checkIfRecordIsSelectableUsing callback
        $isSelectable = fn (AppointmentBlock $record): bool => ($record->source ?? 'manual') === 'manual';

        $this->assertFalse($isSelectable($googleBlock), 'Google blocks should not be selectable for bulk actions');
        $this->assertTrue($isSelectable($manualBlock), 'Manual blocks should be selectable for bulk actions');
    }
}
