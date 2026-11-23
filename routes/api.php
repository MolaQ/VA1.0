<?php

use App\Http\Controllers\Api\V1\ActionController;
use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AnalysisController;
use App\Http\Controllers\Api\V1\GameController;
use App\Http\Controllers\Api\V1\RallyController;
use App\Http\Controllers\Api\V1\SetController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Machine API used by the AI video-analysis pipeline (and future integrations).
| Authenticate with a club API token: `Authorization: Bearer <token>`.
*/
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->name('api.')->group(function () {
    Route::get('/me', fn (Request $request) => [
        'id' => $request->user()->id,
        'name' => $request->user()->name,
        'clubs' => $request->user()->clubs()->get(['clubs.id', 'clubs.name']),
    ])->name('me');

    Route::post('/agent/claim', [AgentController::class, 'claim'])->name('agent.claim');
    Route::get('/games/{game}', [GameController::class, 'show'])->name('games.show');
    Route::post('/games/{game}/actions', [ActionController::class, 'store'])->name('games.actions.store');
    Route::delete('/games/{game}/actions', [ActionController::class, 'destroy'])->name('games.actions.destroy');
    Route::post('/games/{game}/rallies', [RallyController::class, 'store'])->name('games.rallies.store');
    Route::put('/games/{game}/sets/{number}', [SetController::class, 'update'])->whereNumber('number')->name('games.sets.update');
    Route::patch('/games/{game}/analysis', [AnalysisController::class, 'update'])->name('games.analysis.update');
});
