<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrentTeamController extends Controller
{
    /**
     * Switch to another of the user's teams and reload the same page for it. Teams they are not in 404.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['team_id' => ['required', 'integer']]);
        $team = $user->teams()->findOrFail($request->integer('team_id'));

        $user->forceFill(['current_team_id' => $team->id])->save();

        // Filters such as ?set= name the old team's records, so they are dropped.
        $page = strtok(url()->previous(), '?');

        if ($page === route('api-tokens.edit') && $user->cannot('manage', $team)) {
            return to_route('dashboard');
        }

        return redirect($page);
    }
}
