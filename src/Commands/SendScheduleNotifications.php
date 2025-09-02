<?php

namespace Zap\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Zap\Models\Schedule;
use Zap\Models\SchedulePeriod;

class SendScheduleNotifications extends Command
{
    protected $signature = 'zap:notify';

    protected $description = 'Send notifications for recurring schedules (before/after)';

    public function handle()
    {
        $now = now()->startOfMinute(); // normalize to minute precision
        $today = strtolower($now->format('l')); // monday, tuesday, etc.

        // Fetch periods that could occur today
        $periods = SchedulePeriod::with('schedule')
            ->whereHas('schedule', function ($query) use ($now, $today) {
                $query->active()
                    ->where(function ($q) use ($now, $today) {

                        // DAILY recurring schedules
                        $q->where('frequency', 'daily')
                            ->where('start_date', '<=', $now)
                            ->where(function ($r) use ($now) {
                                $r->whereNull('end_date')->orWhere('end_date', '>=', $now);
                            });

                        // WEEKLY recurring schedules
                        $q->orWhere(function ($w) use ($now, $today) {
                            $w->where('frequency', 'weekly')
                                ->whereJsonContains('frequency_config->days', [$today])
                                ->where('start_date', '<=', $now)
                                ->where(function ($r) use ($now) {
                                    $r->whereNull('end_date')->orWhere('end_date', '>=', $now);
                                });
                        });
                    });
            })
            ->get();

        foreach ($periods as $period) {
            $schedule = $period->schedule;

            // --- BEFORE notifications ---
            if ($schedule->shouldNotifyBefore()) {
                $beforeOffsets = $this->normalizeOffsets($schedule->before_notification_time);

                foreach ($beforeOffsets as $minutesBefore) {
                    $notifyAt = $this->calculateNotifyAt($period->start_time, $minutesBefore);
                    $this->fireNotificationOnce($schedule, $period, 'before', $notifyAt);
                }
            }

            // --- AFTER notifications ---
            if ($schedule->shouldNotifyAfter()) {
                $afterOffsets = $this->normalizeOffsets($schedule->after_notification_time);

                foreach ($afterOffsets as $minutesAfter) {
                    $notifyAt = $this->calculateNotifyAt($period->end_time, $minutesAfter, false);
                    $this->fireNotificationOnce($schedule, $period, 'after', $notifyAt);
                }
            }
        }
    }

    /**
     * Convert before/after notification time into an array.
     */
    protected function normalizeOffsets(int|array $offsets): array
    {
        if (is_array($offsets)) {
            return array_map('intval', $offsets);
        }

        return [(int) $offsets];
    }

    /**
     * Calculate notifyAt timestamp based on period time and offset.
     */
    protected function calculateNotifyAt(string $time, int $minutes, bool $before = true): Carbon
    {
        $now = now();
        $dt = Carbon::parse($time)->setDate($now->year, $now->month, $now->day);

        return $before ? $dt->subMinutes($minutes)->startOfMinute()
                       : $dt->addMinutes($minutes)->startOfMinute();
    }

    /**
     * Fire a notification exactly once if it hasn't already been sent
     */
    protected function fireNotificationOnce(Schedule $schedule, SchedulePeriod $period, string $type, Carbon $notifyAt)
    {
        $now = now()->startOfMinute();

        // Only send if notifyAt is current minute
        if (! $notifyAt->eq($now)) {
            return;
        }

        // Check if already sent
        $alreadySent = DB::table('schedule_notifications')
            ->where('schedule_period_id', $period->id)
            ->where('type', $type)
            ->where('notify_at', $notifyAt)
            ->exists();

        if ($alreadySent) {
            return;
        }

        try {
            // Get the correct notification instance
            $notification = $type === 'before'
                ? $schedule->getBeforeNotification()
                : $schedule->getAfterNotification();

            if ($notification) {
                $this->sendNotification($schedule->schedulable, $notification);
            }
        } catch (\Exception $e) {
            Log::error("Failed to send {$type} notification", [
                'schedule_id' => $schedule->id,
                'period_id' => $period->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Record as sent
        DB::table('schedule_notifications')->insert([
            'schedule_period_id' => $period->id,
            'type' => $type,
            'notify_at' => $notifyAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("Sent {$type} notification for period #{$period->id} at {$notifyAt}");
    }

    /**
     * Send notification to the notifiable.
     */
    protected function sendNotification($notifiable, $notification): void
    {
        if (config('zap.notifications.queue', true)) {
            Notification::send($notifiable, $notification);
        } else {
            Notification::sendNow($notifiable, $notification);
        }
    }
}
