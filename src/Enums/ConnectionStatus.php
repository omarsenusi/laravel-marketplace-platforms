<?php

namespace Omarsenusi\MarketplacePlatforms\Enums;

enum ConnectionStatus: string
{
    case PendingOAuth = 'pending_oauth';
    case Active = 'active';
    case RequiresReauth = 'requires_reauth';
    case Suspended = 'suspended';
    case Uninstalled = 'uninstalled';
}
