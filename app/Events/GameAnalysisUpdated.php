<?php

namespace App\Events;

use App\Models\Game;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameAnalysisUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Game $game) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel($this->game->channelName()),
            new PrivateChannel('clubs.'.$this->game->club_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'analysis.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'game_id' => $this->game->id,
            'status' => $this->game->analysis_status->value,
            'progress' => $this->game->analysis_progress,
            'message' => $this->game->analysis_message,
            'sets_won' => $this->game->sets_won,
            'sets_lost' => $this->game->sets_lost,
        ];
    }
}
