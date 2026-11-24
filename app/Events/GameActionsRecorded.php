<?php

namespace App\Events;

use App\Models\Game;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever actions of a match change (AI batch, manual coding, deletion).
 * Open match screens listen on `private-games.{id}` and refresh their stats live.
 */
class GameActionsRecorded implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $actions  compact payload of the newest actions
     */
    public function __construct(
        public Game $game,
        public int $created = 0,
        public int $updated = 0,
        public int $deleted = 0,
        public array $actions = [],
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->game->channelName())];
    }

    public function broadcastAs(): string
    {
        return 'actions.recorded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'game_id' => $this->game->id,
            'created' => $this->created,
            'updated' => $this->updated,
            'deleted' => $this->deleted,
            'actions' => array_slice($this->actions, -20),
        ];
    }
}
