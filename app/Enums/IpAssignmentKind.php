<?php

namespace App\Enums;

enum IpAssignmentKind: string
{
    case Single = 'single';
    case LanSubnet = 'lan_subnet';
    case P2p = 'p2p';
    case RoutedSubnet = 'routed_subnet';
}
