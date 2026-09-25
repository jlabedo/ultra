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

final class RegisterMember
{
    public string $normalizedEmail {
        get => BookingPolicy::normalizeEmail($this->email);
    }

    public function __construct(
        public readonly string $memberId,
        public readonly string $companyId,
        public readonly string $email,
    ) {}

    /**
     * @return list<object>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        ?MemberState $member,
        ?CompanyState $company,
        #[BindTo('normalizedEmail')]
        ?EmailClaimState $emailClaim,
    ): array {
        Violations::throwIfAny([
            null !== $member ? new Violation('member.already_registered', 'Member already exists.') : null,
            null === $company ? new Violation('company.not_found', 'Company does not exist.') : null,
            null !== $emailClaim ? new Violation('member.email_taken', 'Email is already used by another member.') : null,
        ]);

        return [
            new MemberState($this->memberId, $this->companyId, $this->normalizedEmail),
            new EmailClaimState($this->normalizedEmail, $this->memberId),
        ];
    }
}
