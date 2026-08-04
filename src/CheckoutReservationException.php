<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions;

/**
 * Typed refusal raised by `SubscriptionService::reserveCheckoutFor()` (design
 * spec §4.1), mirroring {@see SubscriptionConflictException}'s role as the
 * domain-conflict signal for the `…For()` core: a host branches on
 * `$reasonCode` instead of parsing a message string, the same way
 * {@see \Glueful\Extensions\Subscriptions\Projection\RejectedProviderEventException}
 * carries its own deterministic `$rejectionCode` -- named `reasonCode`, not
 * `code`, because `\Exception` already declares a non-readonly `$code`
 * property that a readonly promoted property of the same name cannot
 * redeclare.
 *
 * - `already_subscribed`: the subject already has an entitling subscription --
 *   active/trialing/past_due, or a `non_renewing` row whose period end is
 *   still in the future. An EXPIRED `non_renewing` row is non-entitling and
 *   never raises this.
 * - `checkout_reservation_replace_refused`: the subject already has an
 *   `incomplete` reservation on a DIFFERENT origination and/or plan, and the
 *   caller did not pass `$opts['replace'] = true`. Only Payvia's
 *   `SubscriptionCheckoutService::prepare()` continuation -- AFTER its new
 *   origination has won the database live guard -- may set that flag; every
 *   other/ad-hoc caller is refused, so two concurrent checkout attempts can
 *   never leave the reservation bound to the losing plan.
 */
final class CheckoutReservationException extends \RuntimeException
{
    private function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }

    public static function alreadySubscribed(Subject $subject): self
    {
        return new self('already_subscribed', sprintf(
            "Subject '%s:%s' in tenant '%s' already has an entitling subscription; "
            . 'refusing to reserve a checkout.',
            $subject->type,
            $subject->uuid,
            $subject->tenantUuid
        ));
    }

    public static function replaceRequiresFlag(Subject $subject): self
    {
        return new self('checkout_reservation_replace_refused', sprintf(
            "Subject '%s:%s' in tenant '%s' already has an incomplete checkout reservation on a "
            . "different origination/plan; ad-hoc replacement is refused. Pass opts['replace'] = true "
            . 'from inside the successful prepare() continuation -- after it has won the live guard -- '
            . 'to replace it.',
            $subject->type,
            $subject->uuid,
            $subject->tenantUuid
        ));
    }
}
