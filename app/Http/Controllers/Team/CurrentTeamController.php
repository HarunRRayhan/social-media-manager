<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrentTeamController extends Controller
{
    /**
     * Switch to another of the user's teams. Teams they are not in 404.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['team_id' => ['required', 'integer']]);
        $team = $user->teams()->findOrFail($request->integer('team_id'));

        $user->forceFill(['current_team_id' => $team->id])->save();

        return back();
    }
}
