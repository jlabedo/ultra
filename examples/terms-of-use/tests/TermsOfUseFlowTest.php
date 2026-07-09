<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use App\TermsOfUse\Query\GetTermsOfUseAcceptanceStatus;
use App\TermsOfUse\Write\AcceptCurrentTermsOfUse;
use App\TermsOfUse\Write\DefineTermsOfUse;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ultra\MessageHandling\MediatorInterface;

/**
 * @internal
 *
 * @coversNothing
 */
final class TermsOfUseFlowTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function test_a_user_must_accept_new_terms_and_not_terms_already_accepted(): void
    {
        self::bootKernel();

        /** @var MediatorInterface $mediator */
        $mediator = self::getContainer()->get(MediatorInterface::class);

        $mediator->send(new DefineTermsOfUse('2026-01', 'https://example.test/terms/2026-01'));

        $status = $mediator->send(new GetTermsOfUseAcceptanceStatus('user-1'));
        self::assertTrue($status->mustAcceptCurrentTermsOfUse());
        self::assertSame('2026-01', $status->currentTermsOfUse?->id);
        self::assertNull($status->acceptedTermsOfUse);

        $mediator->send(new AcceptCurrentTermsOfUse('user-1'));

        $status = $mediator->send(new GetTermsOfUseAcceptanceStatus('user-1'));
        self::assertFalse($status->mustAcceptCurrentTermsOfUse());
        self::assertSame('2026-01', $status->acceptedTermsOfUse?->id);
    }

    public function test_a_user_must_accept_again_when_the_current_terms_change(): void
    {
        self::bootKernel();

        /** @var MediatorInterface $mediator */
        $mediator = self::getContainer()->get(MediatorInterface::class);

        $mediator->send(new DefineTermsOfUse('2026-01', 'https://example.test/terms/2026-01'));
        $mediator->send(new AcceptCurrentTermsOfUse('user-1'));
        $mediator->send(new DefineTermsOfUse('2026-06', 'https://example.test/terms/2026-06'));

        $status = $mediator->send(new GetTermsOfUseAcceptanceStatus('user-1'));
        self::assertTrue($status->mustAcceptCurrentTermsOfUse());
        self::assertSame('2026-06', $status->currentTermsOfUse?->id);
        self::assertSame('2026-01', $status->acceptedTermsOfUse?->id);
    }

    public function test_a_changed_url_also_requires_a_new_acceptance(): void
    {
        self::bootKernel();

        /** @var MediatorInterface $mediator */
        $mediator = self::getContainer()->get(MediatorInterface::class);

        $mediator->send(new DefineTermsOfUse('2026-01', 'https://example.test/terms/2026-01'));
        $mediator->send(new AcceptCurrentTermsOfUse('user-1'));
        $mediator->send(new DefineTermsOfUse('2026-01', 'https://example.test/terms/revised'));

        $status = $mediator->send(new GetTermsOfUseAcceptanceStatus('user-1'));
        self::assertTrue($status->mustAcceptCurrentTermsOfUse());
        self::assertSame('https://example.test/terms/revised', $status->currentTermsOfUse?->url);
    }
}
