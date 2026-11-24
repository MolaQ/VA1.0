<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened in a club that members should see as a toast
 * (new match, finished analysis, new member, someone signed in…).
 * The text is translated in the browser, in each viewer's own language.
 */
class ClubActivity implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public const GAME_CREATED = 'game_created';

    public const ANALYSIS_COMPLETED = 'analysis_completed';

    public const MEMBER_ADDED = 'member_added';

    public const USER_SIGNED_IN = 'user_signed_in';

    /**
     * @param  array<string, string|int|null>  $params
     * @param  'all'|'staff'  $audience
     */
    public function __construct(
        public int $clubId,
        public string $type,
        public array $params = [],
        public ?int $actorId = null,
        public ?string $url = null,
        public string $audience = 'all',
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clubs.'.$this->clubId)];
    }

    public function broadcastAs(): string
    {
        return 'activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'params' => $this->params,
            'actor_id' => $this->actorId,
            'url' => $this->url,
            'audience' => $this->audience,
        ];
    }
}
