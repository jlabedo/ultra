<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Write;

use App\Booking\Domain\BookingPolicy;
use App\Booking\Domain\Violations;
use App\Booking\EventSourced\Event\MemberRegistered;
use App\Booking\EventSourced\State\CompanyState;
use App\Booking\EventSourced\State\EmailRegistrationState;
use App\Booking\EventSourced\State\MemberState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\BindTo;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Violation;

final class RegisterMember
{
    /** Derived identity: the uniqueness boundary is the normalized email, not the raw input. */
    public string $normalizedEmail {
        get => BookingPolicy::normalizeEmail($this->email);
    }

    public function __construct(
        public readonly string $memberId,
        public readonly string $companyId,
        public readonly string $email,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        MemberState $member,
        CompanyState $company,
        #[BindTo('normalizedEmail')]
        EmailRegistrationState $email,
    ): MemberRegistered {
        Violations::throwIfAny([
            $member->exists ? new Violation('member.already_registered', 'Member already exists.') : null,
            !$company->exists ? new Violation('company.not_found', 'Company does not exist.') : null,
            $email->taken ? new Violation('member.email_taken', 'Email is already used by another member.') : null,
        ]);

        return new MemberRegistered($this->memberId, $this->companyId, $this->normalizedEmail);
    }
}
