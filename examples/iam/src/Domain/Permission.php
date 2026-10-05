<?php

declare(strict_types=1);

namespace IamExample\Domain;

enum Permission: string
{
    case ManageIam = 'iam:manage';
    case ReadDocument = 'documents:read';
    case EditDocument = 'documents:edit';
    case DeleteDocument = 'documents:delete';
}
