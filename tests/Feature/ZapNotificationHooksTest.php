<?php

use Illuminate\Support\Facades\Notification;
use Zap\Facades\Zap;
use Zap\Models\Schedule;
use Zap\Notifications\ZapCompletedNotification;
use Zap\Notifications\ZapStartingNotification;
use Zap\Services\ScheduleExecutionService;

describe('Notification Hooks', function () {
    beforeEach(function () {
        $this->user = createUser();

        config([
            'zap.notifications.enabled' => true,
            'zap.notifications.before_notification' => ZapStartingNotification::class,
            'zap.notifications.after_notification' => ZapCompletedNotification::class,
        ]);
    });

    test('it can create schedule with before notification', function () {
        $schedule = Zap::for($this->user)
            ->from('2025-03-15')
            ->addPeriod('22:00', '23:59')
            ->notifyBefore()
            ->save();

        expect($schedule->notify_before)->toBeTrue()
            ->and($schedule->before_notification_class)->toBe(ZapStartingNotification::class)
            ->and($schedule->notify_after)->toBeFalse();
    });

    test('it can create schedule with after notification', function () {
        $schedule = Zap::for($this->user)
            ->from('2025-03-15')
            ->addPeriod('22:00', '23:59')
            ->notifyAfter()
            ->save();

        expect($schedule->notify_before)->toBeFalse()
            ->and($schedule->notify_after)->toBeTrue()
            ->and($schedule->after_notification_class)->toBe(ZapCompletedNotification::class);
    });

    test('it can create schedule with both notifications', function () {
        $schedule = Zap::for($this->user)
            ->from('2025-03-15')
            ->addPeriod('22:00', '23:59')
            ->notifyBefore()
            ->notifyAfter()
            ->save();

        expect($schedule->notify_before)->toBeTrue()
            ->and($schedule->notify_after)->toBeTrue()
            ->and($schedule->before_notification_class)->toBe(ZapStartingNotification::class)
            ->and($schedule->after_notification_class)->toBe(ZapCompletedNotification::class);
    });

    test('it can create schedule with custom notifications', function () {
        $customBeforeNotification = new class extends \Illuminate\Notifications\Notification
        {
            public $customData = 'before data';

            public function via($notifiable)
            {
                return ['mail'];
            }
        };

        $customAfterNotification = new class extends \Illuminate\Notifications\Notification
        {
            public $customData = 'after data';

            public function via($notifiable)
            {
                return ['mail'];
            }
        };

        $schedule = Zap::for($this->user)
            ->from('2025-03-15')
            ->addPeriod('22:00', '23:59')
            ->notifyBeforeUsing($customBeforeNotification)
            ->notifyAfterUsing($customAfterNotification)
            ->save();

        expect($schedule->notify_before)->toBeTrue()
            ->and($schedule->notify_after)->toBeTrue()
            ->and($schedule->before_notification_class)->toBe(get_class($customBeforeNotification))
            ->and($schedule->after_notification_class)->toBe(get_class($customAfterNotification))
            ->and($schedule->before_notification_data['customData'])->toBe('before data')
            ->and($schedule->after_notification_data['customData'])->toBe('after data');
    });

    test('it sends notifications during execution', function () {
        Notification::fake();

        $schedule = Zap::for($this->user)
            ->from('2025-03-15')
            ->addPeriod('22:00', '23:59')
            ->notifyBefore()
            ->notifyAfter()
            ->save();

        app(ScheduleExecutionService::class)->execute($schedule);

        Notification::assertSentTo(
            $this->user,
            ZapStartingNotification::class,
            fn ($notification) => $notification->schedule->id === $schedule->id
        );

        Notification::assertSentTo(
            $this->user,
            ZapCompletedNotification::class,
            fn ($notification) => $notification->schedule->id === $schedule->id
                && $notification->executionDetails['status'] === 'completed'
        );
    });

    test('it sends after notification with error on failure', function () {
        Notification::fake();

        $schedule = Zap::for($this->user)
            ->from('2025-03-15')
            ->addPeriod('22:00', '23:59')
            ->notifyBefore()
            ->notifyAfter()
            ->save();

        try {
            app(ScheduleExecutionService::class)->execute($schedule, fn () => throw new \Exception('Test error'));
        } catch (\Exception) {
        }

        Notification::assertSentTo($this->user, ZapStartingNotification::class);

        Notification::assertSentTo(
            $this->user,
            ZapCompletedNotification::class,
            fn ($notification) => $notification->schedule->id === $schedule->id
                && $notification->executionDetails['status'] === 'failed'
                && $notification->executionDetails['error'] === 'Test error'
        );
    });

    test('it respects global notification setting', function () {
        Notification::fake();
        config(['zap.notifications.enabled' => false]);

        $schedule = Zap::for($this->user)
            ->from('2025-03-15')
            ->addPeriod('22:00', '23:59')
            ->notifyBefore()
            ->notifyAfter()
            ->save();

        app(ScheduleExecutionService::class)->execute($schedule);

        Notification::assertNothingSent();
    });

    test('it handles notification failures gracefully', function () {
        $failingNotification = new class extends \Illuminate\Notifications\Notification
        {
            public function via($notifiable)
            {
                throw new \Exception('Notification failed');
            }
        };

        $schedule = Zap::for($this->user)
            ->from('2025-03-15')
            ->addPeriod('22:00', '23:59')
            ->notifyBeforeUsing($failingNotification)
            ->save();

        $result = app(ScheduleExecutionService::class)->execute($schedule, fn () => 'success');

        expect($result)->toBe('success');
    });

    test('it can execute batch schedules with notifications', function () {
        Notification::fake();

        $schedules = [];
        for ($i = 1; $i <= 3; $i++) {
            $schedules[] = Zap::for($this->user)
                ->from('2025-03-15')
                ->addPeriod('22:00', '23:59')
                ->notifyBefore()
                ->notifyAfter()
                ->save();
        }

        $results = app(ScheduleExecutionService::class)->executeBatch($schedules);

        expect($results)->toHaveCount(3)
            ->each(fn ($result) => expect($result['status'])->toBe('success'));

        Notification::assertSentToTimes($this->user, ZapStartingNotification::class, 3);
        Notification::assertSentToTimes($this->user, ZapCompletedNotification::class, 3);
    });

    test('it stores notification data in database', function () {
        $customNotification = new class extends \Illuminate\Notifications\Notification
        {
            public $title = 'Custom Title';

            public $message = 'Custom Message';

            public function via($notifiable)
            {
                return ['database'];
            }
        };

        $schedule = Zap::for($this->user)
            ->from('2025-03-15')
            ->addPeriod('22:00', '23:59')
            ->notifyBeforeUsing($customNotification)
            ->save();

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'notify_before' => true,
            'before_notification_class' => get_class($customNotification),
        ]);

        $schedule->refresh();
        expect($schedule->before_notification_data['title'])->toBe('Custom Title')
            ->and($schedule->before_notification_data['message'])->toBe('Custom Message');
    });

    test('notification channels respect configuration', function () {
        config(['zap.notifications.default_channels' => ['database']]);

        $notification = new ZapStartingNotification(new Schedule);
        $channels = $notification->via($this->user);

        expect($channels)->toBe(['database']);
    });

});
