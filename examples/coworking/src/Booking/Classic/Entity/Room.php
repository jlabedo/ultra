<?php

declare(strict_types=1);

namespace App\Booking\Classic\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cw_orm_rooms')]
class Room
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public private(set) string $id,
        #[ORM\Column]
        public private(set) string $name,
    ) {}
}
