<?php

namespace Zap\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Notifications\Notification;
use Zap\Enums\ScheduleTypes;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $schedulable_type
 * @property int $schedulable_id
 * @property ScheduleTypes $schedule_type
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property bool $is_recurring
 * @property string|null $frequency
 * @property array|null $frequency_config
 * @property array|null $metadata
 * @property bool $is_active
 * @property bool $notify_before
 * @property bool $notify_after
 * @property string|int $before_notification_time
 * @property string|int $after_notification_time
 * @property string|null $before_notification_class
 * @property string|null $after_notification_class
 * @property array|null $before_notification_data
 * @property array|null $after_notification_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SchedulePeriod> $periods
 * @property-read Model $schedulable
 * @property-read int $total_duration
 */
class Schedule extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'schedulable_type',
        'schedulable_id',
        'name',
        'description',
        'schedule_type',
        'start_date',
        'end_date',
        'is_recurring',
        'frequency',
        'frequency_config',
        'metadata',
        'is_active',
        'notify_before',
        'notify_after',
        'before_notification_time',
        'after_notification_time',
        'before_notification_class',
        'after_notification_class',
        'before_notification_data',
        'after_notification_data',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'schedule_type' => ScheduleTypes::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'is_recurring' => 'boolean',
        'frequency_config' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'notify_before' => 'boolean',
        'notify_after' => 'boolean',
        'before_notification_time' => 'int',
        'after_notification_time' => 'int',
        'before_notification_data' => 'array',
        'after_notification_data' => 'array',
    ];

    /**
     * The attributes that should be guarded.
     */
    protected $guarded = [];

    /**
     * Get the parent schedulable model.
     */
    public function schedulable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the schedule periods.
     *
     * @return HasMany<SchedulePeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(SchedulePeriod::class);
    }

    /**
     * Create a new Eloquent query builder for the model.
     */
    public function newEloquentBuilder($query): Builder
    {
        return new Builder($query);
    }

    /**
     * Create a new Eloquent Collection instance.
     *
     * @param  array<int, static>  $models
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /**
     * Scope a query to only include active schedules.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope a query to only include recurring schedules.
     */
    public function scopeRecurring(Builder $query): void
    {
        $query->where('is_recurring', true);
    }

    /**
     * Scope a query to only include schedules of a specific type.
     */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('schedule_type', $type);
    }

    /**
     * Scope a query to only include availability schedules.
     */
    public function scopeAvailability(Builder $query): void
    {
        $query->where('schedule_type', ScheduleTypes::AVAILABILITY);
    }

    /**
     * Scope a query to only include appointment schedules.
     */
    public function scopeAppointments(Builder $query): void
    {
        $query->where('schedule_type', ScheduleTypes::APPOINTMENT);
    }

    /**
     * Scope a query to only include blocked schedules.
     */
    public function scopeBlocked(Builder $query): void
    {
        $query->where('schedule_type', ScheduleTypes::BLOCKED);
    }

    /**
     * Scope a query to only include schedules for a specific date.
     */
    public function scopeForDate(Builder $query, string $date): void
    {
        $checkDate = \Carbon\Carbon::parse($date);

        $query->where('start_date', '<=', $checkDate)
            ->where(function ($q) use ($checkDate) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $checkDate);
            });
    }

    /**
     * Scope a query to only include schedules within a date range.
     */
    public function scopeForDateRange(Builder $query, string $startDate, string $endDate): void
    {
        $query->where(function ($q) use ($startDate, $endDate) {
            $q->whereBetween('start_date', [$startDate, $endDate])
                ->orWhereBetween('end_date', [$startDate, $endDate])
                ->orWhere(function ($q2) use ($startDate, $endDate) {
                    $q2->where('start_date', '<=', $startDate)
                        ->where('end_date', '>=', $endDate);
                });
        });
    }

    /**
     * Check if this schedule overlaps with another schedule.
     */
    public function overlapsWith(Schedule $other): bool
    {
        // Basic date range overlap check
        if ($this->end_date && $other->end_date) {
            return $this->start_date <= $other->end_date && $this->end_date >= $other->start_date;
        }

        // Handle open-ended schedules
        if (! $this->end_date && ! $other->end_date) {
            return $this->start_date <= $other->start_date;
        }

        if (! $this->end_date) {
            return $this->start_date <= ($other->end_date ?? $other->start_date);
        }

        if (! $other->end_date) {
            return $this->end_date >= $other->start_date;
        }

        return false;
    }

    /**
     * Get the total duration of all periods in minutes.
     */
    public function getTotalDurationAttribute(): int
    {
        return $this->periods->sum('duration_minutes');
    }

    /**
     * Check if the schedule is currently active.
     */
    public function isActiveOn(string $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $checkDate = \Carbon\Carbon::parse($date);
        $startDate = $this->start_date;
        $endDate = $this->end_date;

        return $checkDate->greaterThanOrEqualTo($startDate) &&
               ($endDate === null || $checkDate->lessThanOrEqualTo($endDate));
    }

    /**
     * Check if this schedule is of availability type.
     */
    public function isAvailability(): bool
    {
        return $this->schedule_type->is(ScheduleTypes::AVAILABILITY);
    }

    /**
     * Check if this schedule is of appointment type.
     */
    public function isAppointment(): bool
    {
        return $this->schedule_type->is(ScheduleTypes::APPOINTMENT);
    }

    /**
     * Check if this schedule is of blocked type.
     */
    public function isBlocked(): bool
    {
        return $this->schedule_type->is(ScheduleTypes::BLOCKED);
    }

    /**
     * Check if this schedule is of custom type.
     */
    public function isCustom(): bool
    {
        return $this->schedule_type->is(ScheduleTypes::CUSTOM);
    }

    /**
     * Check if this schedule should prevent overlaps (appointments and blocked schedules).
     */
    public function preventsOverlaps(): bool
    {
        return $this->schedule_type->preventsOverlaps();
    }

    /**
     * Check if this schedule allows overlaps (availability schedules).
     */
    public function allowsOverlaps(): bool
    {
        return $this->schedule_type->allowsOverlaps();
    }

    /**
     * Get the before notification instance.
     */
    public function getBeforeNotification(): ?Notification
    {
        if (! $this->notify_before || ! $this->before_notification_class) {
            return null;
        }

        return $this->instantiateNotification(
            $this->before_notification_class,
            $this->before_notification_data ?? []
        );
    }

    /**
     * Get the after notification instance.
     */
    public function getAfterNotification(): ?Notification
    {
        if (! $this->notify_after || ! $this->after_notification_class) {
            return null;
        }

        return $this->instantiateNotification(
            $this->after_notification_class,
            $this->after_notification_data ?? []
        );
    }

    /**
     * Instantiate a notification from class name and data.
     */
    private function instantiateNotification(string $class, array $data): Notification
    {
        if (! class_exists($class)) {
            throw new \RuntimeException("Notification class {$class} does not exist");
        }

        // If we have constructor params stored, use them
        if (isset($data['constructor_params'])) {
            $reflection = new \ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if ($constructor) {
                $params = [];
                foreach ($constructor->getParameters() as $param) {
                    $params[] = $data['constructor_params'][$param->getName()] ?? null;
                }

                return $reflection->newInstanceArgs($params);
            }
        }

        // Try to instantiate with the schedule as parameter
        return new $class($this);
    }

    /**
     * Check if before notifications are enabled.
     */
    public function shouldNotifyBefore(): bool
    {
        return $this->notify_before && config('zap.notifications.enabled', true);
    }

    /**
     * Check if after notifications are enabled.
     */
    public function shouldNotifyAfter(): bool
    {
        return $this->notify_after && config('zap.notifications.enabled', true);
    }

    /**
     * Execute this schedule with notification hooks.
     */
    public function execute(?callable $callback = null): mixed
    {
        return app(\Zap\Services\ScheduleExecutionService::class)->execute($this, $callback);
    }
}
