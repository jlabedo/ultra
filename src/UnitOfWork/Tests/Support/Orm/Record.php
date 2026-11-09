<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests\Support\Orm;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'orm_records')]
final class Record
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id,
        #[ORM\Column]
        public string $value,
    ) {}
}
