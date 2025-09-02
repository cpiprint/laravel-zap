<?php

namespace Zap\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Zap\Models\Schedule;

class ScheduleExecutionService
{
    /**
     * Execute a schedule with notification hooks.
     */
    public function execute(Schedule $schedule): mixed
    {
        $result = null;
        $executionStartTime = now();

        try {
            // Send before notification
            $this->sendBeforeNotification($schedule);

            // Calculate execution details
            $executionDetails = [
                'status' => 'completed',
                'duration' => $executionStartTime->diffForHumans(now(), short: true),
                'completed_at' => now()->toISOString(),
            ];

            // Send after notification
            $this->sendAfterNotification($schedule, $executionDetails);

            return $result;
        } catch (\Exception $e) {
            // Log the error
            Log::error('Schedule execution failed', [
                'schedule_id' => $schedule->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send after notification with error status
            $executionDetails = [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'duration' => $executionStartTime->diffForHumans(now(), short: true),
                'failed_at' => now()->toISOString(),
            ];

            $this->sendAfterNotification($schedule, $executionDetails);

            throw $e;
        }
    }

    /**
     * Send the before notification if configured.
     */
    protected function sendBeforeNotification(Schedule $schedule): void
    {
        if (! $schedule->shouldNotifyBefore()) {
            return;
        }

        try {
            $notification = $schedule->getBeforeNotification();
            $this->sendNotification($schedule->schedulable, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to send before notification', [
                'schedule_id' => $schedule->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send the after notification if configured.
     */
    protected function sendAfterNotification(Schedule $schedule, array $executionDetails = []): void
    {
        if (! $schedule->shouldNotifyAfter()) {
            return;
        }

        try {
            $notification = $schedule->getAfterNotification();

            // If it's the default notification, we can pass execution details
            if ($notification instanceof \Zap\Notifications\ZapCompletedNotification) {
                $notification->executionDetails = $executionDetails;
            }

            $this->sendNotification($schedule->schedulable, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to send after notification', [
                'schedule_id' => $schedule->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a notification to a notifiable.
     */
    protected function sendNotification($notifiable, $notification): void
    {
        if (config('zap.notifications.queue', true)) {
            Notification::send($notifiable, $notification);
        } else {
            Notification::sendNow($notifiable, $notification);
        }
    }

    /**
     * Execute multiple schedules in batch.
     */
    public function executeBatch(array $schedules, ?callable $callback = null): array
    {
        $results = [];

        foreach ($schedules as $schedule) {
            try {
                $results[$schedule->id] = [
                    'schedule' => $schedule,
                    'result' => $this->execute($schedule, $callback),
                    'status' => 'success',
                ];
            } catch (\Exception $e) {
                $results[$schedule->id] = [
                    'schedule' => $schedule,
                    'error' => $e->getMessage(),
                    'status' => 'failed',
                ];
            }
        }

        return $results;
    }
}
