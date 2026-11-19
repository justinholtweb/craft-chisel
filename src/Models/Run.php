<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One command run: who, what, when, outcome and (sanitised) output.
 *
 * @property int $id
 * @property string $uid
 * @property int|null $userId
 * @property string|null $username
 * @property string $command
 * @property list<string>|null $arguments
 * @property string|null $parameters
 * @property string $status
 * @property int|null $exitCode
 * @property string|null $output
 * @property bool $outputTruncated
 * @property string|null $error
 * @property string|null $ip
 * @property CarbonInterface|null $startedAt
 * @property CarbonInterface|null $finishedAt
 * @property int|null $durationMs
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Run extends Model
{
    public const string PENDING = 'pending';

    public const string RUNNING = 'running';

    public const string SUCCEEDED = 'succeeded';

    public const string FAILED = 'failed';

    public const string REFUSED = 'refused';

    public const array STATUSES = [self::PENDING, self::RUNNING, self::SUCCEEDED, self::FAILED, self::REFUSED];

    protected $table = 'chisel_runs';

    protected $guarded = ['id'];

    /** Never serialised by accident (JSON responses, GraphQL, logs). */
    protected $hidden = ['parameters'];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'outputTruncated' => 'boolean',
            'exitCode' => 'integer',
            'durationMs' => 'integer',
            'userId' => 'integer',
            'startedAt' => 'datetime',
            'finishedAt' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Run $run): void {
            $run->uid ??= (string) Str::uuid();
        });
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED, self::REFUSED], true);
    }

    /** The command line as shown in the CP: `name arg --opt=value`, secrets masked. */
    public function commandLine(): string
    {
        return trim($this->command.' '.implode(' ', $this->arguments ?? []));
    }

    /** @param  Builder<Run>  $query */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereIn('status', [self::PENDING, self::RUNNING]);
    }
}
