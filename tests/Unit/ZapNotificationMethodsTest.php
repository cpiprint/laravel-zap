<?php

use Illuminate\Notifications\Notification;
use Zap\Builders\ScheduleBuilder;
use Zap\Models\Schedule;
use Zap\Notifications\ZapCompletedNotification;
use Zap\Notifications\ZapStartingNotification;

beforeEach(function () {
    // Set up test configuration
    config([
        'zap.notifications.before_notification' => ZapStartingNotification::class,
        'zap.notifications.after_notification' => ZapCompletedNotification::class,
    ]);
});

test('it can enable before notifications with default notification', function () {
    $builder = new ScheduleBuilder;
    $builder->notifyBefore();

    $attributes = $builder->getAttributes();

    expect($attributes['notify_before'])->toBeTrue();
    expect($attributes['before_notification_class'])->toBe(ZapStartingNotification::class);
});

test('it can enable after notifications with default notification', function () {
    $builder = new ScheduleBuilder;
    $builder->notifyAfter();

    $attributes = $builder->getAttributes();

    expect($attributes['notify_after'])->toBeTrue();
    expect($attributes['after_notification_class'])->toBe(ZapCompletedNotification::class);
});

test('it can enable before notifications with custom notification', function () {
    $customNotification = new class extends Notification
    {
        public function via($notifiable)
        {
            return ['mail'];
        }
    };

    $builder = new ScheduleBuilder;
    $builder->notifyBeforeUsing($customNotification);

    $attributes = $builder->getAttributes();

    expect($attributes['notify_before'])->toBeTrue();
    expect($attributes['before_notification_class'])->toBe(get_class($customNotification));
    expect($attributes['before_notification_data'])->toBeArray();
});

test('it can enable after notifications with custom notification', function () {
    $customNotification = new class extends Notification
    {
        public function via($notifiable)
        {
            return ['mail'];
        }
    };

    $builder = new ScheduleBuilder;
    $builder->notifyAfterUsing($customNotification);

    $attributes = $builder->getAttributes();

    expect($attributes['notify_after'])->toBeTrue();
    expect($attributes['after_notification_class'])->toBe(get_class($customNotification));
    expect($attributes['after_notification_data'])->toBeArray();
});

test('it can chain notification methods', function () {
    $builder = new ScheduleBuilder;

    $result = $builder
        ->notifyBefore()
        ->notifyAfter();

    expect($result)->toBeInstanceOf(ScheduleBuilder::class);

    $attributes = $builder->getAttributes();
    expect($attributes['notify_before'])->toBeTrue();
    expect($attributes['notify_after'])->toBeTrue();
});

test('it serializes notification with public properties', function () {
    $notification = new class extends Notification
    {
        public $testProperty = 'test value';

        public $anotherProperty = 123;

        public function via($notifiable)
        {
            return ['mail'];
        }
    };

    $builder = new ScheduleBuilder;
    $builder->notifyBeforeUsing($notification);

    $attributes = $builder->getAttributes();
    $data = $attributes['before_notification_data'];

    expect($data['testProperty'])->toBe('test value');
    expect($data['anotherProperty'])->toBe(123);
});

test('schedule model can check if notifications should be sent', function () {
    $schedule = new Schedule([
        'notify_before' => true,
        'notify_after' => false,
    ]);

    config(['zap.notifications.enabled' => true]);

    expect($schedule->shouldNotifyBefore())->toBeTrue();
    expect($schedule->shouldNotifyAfter())->toBeFalse();
});

test('schedule model respects global notification setting', function () {
    $schedule = new Schedule([
        'notify_before' => true,
        'notify_after' => true,
    ]);

    config(['zap.notifications.enabled' => false]);

    expect($schedule->shouldNotifyBefore())->toBeFalse();
    expect($schedule->shouldNotifyAfter())->toBeFalse();
});

test('schedule model can instantiate before notification', function () {
    $schedule = new Schedule([
        'notify_before' => true,
        'before_notification_class' => ZapStartingNotification::class,
    ]);

    $notification = $schedule->getBeforeNotification();

    expect($notification)->toBeInstanceOf(ZapStartingNotification::class);
    expect($notification->schedule)->toBe($schedule);
});

test('schedule model can instantiate after notification', function () {
    $schedule = new Schedule([
        'notify_after' => true,
        'after_notification_class' => ZapCompletedNotification::class,
    ]);

    $notification = $schedule->getAfterNotification();

    expect($notification)->toBeInstanceOf(ZapCompletedNotification::class);
    expect($notification->schedule)->toBe($schedule);
});

test('schedule model returns null when notifications disabled', function () {
    $schedule = new Schedule([
        'notify_before' => false,
        'notify_after' => false,
    ]);

    expect($schedule->getBeforeNotification())->toBeNull();
    expect($schedule->getAfterNotification())->toBeNull();
});

test('it throws exception for non existent notification class', function () {
    $schedule = new Schedule([
        'notify_before' => true,
        'before_notification_class' => 'NonExistent\\NotificationClass',
    ]);

    expect(fn () => $schedule->getBeforeNotification())
        ->toThrow(RuntimeException::class, 'Notification class NonExistent\\NotificationClass does not exist');
});
