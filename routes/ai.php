<?php

use App\Mcp\Servers\DevServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('mission-control', DevServer::class);
