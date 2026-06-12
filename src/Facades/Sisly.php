<?php

declare(strict_types=1);

namespace Sisly\Facades;

use Illuminate\Support\Facades\Facade;
use Sisly\DTOs\CoachInfo;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\DTOs\SislyResponse;
use Sisly\Enums\CoachId;
use Sisly\SislyManager;

/**
 * @method static SislyResponse startSession(string $message, array $context = [])
 * @method static SislyResponse initSession(array $context = [])
 * @method static SislyResponse message(string $sessionId, string $message)
 * @method static Session|null getSession(string $sessionId)
 * @method static array getState(string $sessionId)
 * @method static void endSession(string $sessionId)
 * @method static array<CoachInfo> getCoaches()
 * @method static CoachInfo getCoach(CoachId $coachId)
 * @method static bool sessionExists(string $sessionId)
 *
 * @see \Sisly\SislyManager
 */
class Sisly extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return SislyManager::class;
    }
}
