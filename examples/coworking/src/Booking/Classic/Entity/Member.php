<?php

declare(strict_types=1);

namespace App\Booking\Classic\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cw_orm_members')]
class Member
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public private(set) string $id,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        public private(set) Company $company,
        #[ORM\Column(unique: true)]
        public private(set) string $email,
    ) {}
}
