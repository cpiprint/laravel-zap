<?php

namespace Zap\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Zap\Models\Schedule;

class ZapCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Schedule $schedule,
        public ?array $executionDetails = null
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return config('zap.notifications.default_channels', ['mail', 'database']);
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $scheduleName = $this->schedule->name ?: 'Unnamed Schedule';

        $message = (new MailMessage)
            ->subject(__('Schedule Completed: :name', ['name' => $scheduleName]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name ?? 'there']))
            ->line(__('Your scheduled task ":name" has been completed successfully.', ['name' => $scheduleName]))
            ->line(__('Started at: :time', ['time' => $this->schedule->start_date->format('Y-m-d H:i:s')]));

        if ($this->executionDetails) {
            if (isset($this->executionDetails['duration'])) {
                $message->line(__('Duration: :duration', ['duration' => $this->executionDetails['duration']]));
            }
            if (isset($this->executionDetails['status'])) {
                $message->line(__('Status: :status', ['status' => $this->executionDetails['status']]));
            }
        }

        return $message
            ->when($this->schedule->description, function ($message) {
                return $message->line(__('Description: :description', ['description' => $this->schedule->description]));
            })
            ->action(__('View Schedule'), $this->getScheduleUrl())
            ->line(__('Thank you for using our application!'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'schedule_id' => $this->schedule->id,
            'schedule_name' => $this->schedule->name,
            'type' => 'schedule_completed',
            'start_date' => $this->schedule->start_date->toISOString(),
            'end_date' => $this->schedule->end_date?->toISOString(),
            'description' => $this->schedule->description,
            'execution_details' => $this->executionDetails,
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'schedule_id' => $this->schedule->id,
            'schedule_name' => $this->schedule->name,
            'type' => 'schedule_completed',
            'start_date' => $this->schedule->start_date->toISOString(),
            'end_date' => $this->schedule->end_date?->toISOString(),
            'description' => $this->schedule->description,
            'execution_details' => $this->executionDetails,
            'message' => __('Your scheduled task ":name" has been completed.', ['name' => $this->schedule->name]),
        ]);
    }

    /**
     * Get the URL to view the schedule.
     */
    protected function getScheduleUrl(): string
    {
        // This should be customized based on your application's URL structure
        return url('/schedules/'.$this->schedule->id);
    }
}
