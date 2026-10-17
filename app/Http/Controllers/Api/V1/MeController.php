<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Show the account that owns the access token.
     */
    public function __invoke(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }
}
