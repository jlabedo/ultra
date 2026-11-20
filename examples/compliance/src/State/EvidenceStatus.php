<?php

declare(strict_types=1);

namespace ComplianceExample\State;

enum EvidenceStatus
{
    case Unused;
    case Active;
    case Withdrawn;
}
