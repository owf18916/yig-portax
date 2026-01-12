<?php

namespace Database\Seeders;

use App\Models\Announcement;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AnnouncementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Announcement::create([
            'title' => 'System Update',
            'content' => 'Dashboard analytics have been updated with real-time charts for better case monitoring.',
            'type' => 'info',
            'is_active' => true,
            'published_at' => now(),
            'created_by' => 1,
            'updated_by' => 1
        ]);

        Announcement::create([
            'title' => 'Feature Enabled',
            'content' => 'Workflow analysis and automatic case status tracking are now available for all users.',
            'type' => 'success',
            'is_active' => true,
            'published_at' => now()->subDay(),
            'created_by' => 1,
            'updated_by' => 1
        ]);

        Announcement::create([
            'title' => 'Maintenance Notice',
            'content' => 'Database optimization is scheduled for next week. System will remain accessible but may be slower.',
            'type' => 'warning',
            'is_active' => true,
            'published_at' => now()->subDays(2),
            'created_by' => 1,
            'updated_by' => 1
        ]);
    }
}
