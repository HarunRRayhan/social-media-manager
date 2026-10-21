<?php

use App\Mcp\Servers\SmmServer;
use Laravel\Mcp\Facades\Mcp;

// Same Bearer API tokens and rate limit as /api/v1.
Mcp::web('/mcp', SmmServer::class)->middleware(['auth:sanctum', 'throttle:120,1']);
