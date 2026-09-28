<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Write;

use App\Booking\Domain\BookingPolicy;
use App\Booking\Domain\Violations;
use App\Booking\StateStore\State\CompanyState;
use App\Booking\StateStore\State\EmailClaimState;
use App\Booking\StateStore\State\MemberState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\BindTo;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Violation;

final readonly class RegisterMember
{
    public function __construct(
        public string $memberId,
        public string $companyId,
        public string $email,
    ) {}

    public function normalizedEmail(): string
    {
        return BookingPolicy::normalizeEmail($this->email);
    }

    /**
     * @return list<object>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        ?MemberState $member,
        ?CompanyState $company,
        #[BindTo(method: 'normalizedEmail')]
        ?EmailClaimState $emailClaim,
    ): array {
        Violations::throwIfAny([
            null !== $member ? new Violation('member.already_registered', 'Member already exists.') : null,
            null === $company ? new Violation('company.not_found', 'Company does not exist.') : null,
            null !== $emailClaim ? new Violation('member.email_taken', 'Email is already used by another member.') : null,
        ]);

        return [
            new MemberState($this->memberId, $this->companyId, $this->normalizedEmail()),
            new EmailClaimState($this->normalizedEmail(), $this->memberId),
        ];
    }
}
