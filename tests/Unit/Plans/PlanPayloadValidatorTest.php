<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit\Plans;

use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlanPayloadValidatorTest extends TestCase
{
    private PlanPayloadValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PlanPayloadValidator();
    }

    /** @return array<string,mixed> */
    private function validPayload(): array
    {
        return [
            'plan_key' => 'pro',
            'display_name' => 'Pro',
            'description' => 'Professional plan',
            'entitlements' => [
                'projects.limit' => 10,
                'reports.export' => true,
                'support.priority' => null,
            ],
            'payvia_priced_plan_uuid' => 'price1234567',
            'status' => 'active',
            'sort_order' => 20,
        ];
    }

    public function testValidCreateAcceptsSupportedEntitlementValues(): void
    {
        $validated = $this->validator->validateCreate($this->validPayload());

        self::assertSame('pro', $validated['plan_key']);
        self::assertSame([
            'projects.limit' => 10,
            'reports.export' => true,
            'support.priority' => null,
        ], $validated['entitlements']);
    }

    public function testEmptyEntitlementsMapIsValid(): void
    {
        $validated = $this->validator->validateCreate(array_merge($this->validPayload(), [
            'entitlements' => [],
        ]));

        self::assertSame([], $validated['entitlements']);
    }

    public function testRejectsEntitlementKeysLongerThan128Characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator->validateCreate(array_merge($this->validPayload(), [
            'entitlements' => [str_repeat('a', 129) => true],
        ]));
    }

    public function testRejectsPayviaPricedPlanUuidThatIsNot12Characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator->validateCreate(array_merge($this->validPayload(), [
            'payvia_priced_plan_uuid' => 'too-long-price-id',
        ]));
    }

    /**
     * @dataProvider invalidEntitlementValues
     */
    public function testRejectsInvalidEntitlementValues(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator->validateCreate(array_merge($this->validPayload(), [
            'entitlements' => ['bad.key' => $value],
        ]));
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidEntitlementValues(): iterable
    {
        yield 'string' => ['yes'];
        yield 'float' => [1.5];
        yield 'array' => [['nested' => true]];
        yield 'object' => [(object) ['enabled' => true]];
        yield 'negative int' => [-1];
    }

    /**
     * @dataProvider invalidPlanKeys
     */
    public function testRejectsInvalidPlanKeys(string $planKey): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator->validateCreate(array_merge($this->validPayload(), [
            'plan_key' => $planKey,
        ]));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidPlanKeys(): iterable
    {
        yield 'uppercase' => ['Pro'];
        yield 'space' => ['pro plan'];
        yield 'slash' => ['pro/plan'];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'reserved import route' => ['import-config'];
    }

    public function testRejectsCreateWithoutRequiredFields(): void
    {
        foreach (['plan_key', 'display_name', 'entitlements', 'status'] as $field) {
            $payload = $this->validPayload();
            unset($payload[$field]);

            try {
                $this->validator->validateCreate($payload);
                self::fail("Expected missing {$field} to fail.");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($field, $e->getMessage());
            }
        }
    }

    /**
     * @dataProvider allowedStatusTransitions
     */
    public function testAllowsLegalStatusTransitions(string $from, string $to): void
    {
        $validated = $this->validator->validatePatch(
            ['status' => $to],
            array_merge($this->validPayload(), ['status' => $from])
        );

        self::assertSame($to, $validated['status']);
    }

    /** @return iterable<string,array{string,string}> */
    public static function allowedStatusTransitions(): iterable
    {
        yield 'draft to active' => ['draft', 'active'];
        yield 'draft to archived' => ['draft', 'archived'];
        yield 'active to archived' => ['active', 'archived'];
        yield 'archived to active' => ['archived', 'active'];
    }

    /**
     * @dataProvider forbiddenDraftTransitions
     */
    public function testRejectsPublishedPlanReturningToDraft(string $from): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator->validatePatch(
            ['status' => 'draft'],
            array_merge($this->validPayload(), ['status' => $from])
        );
    }

    /** @return iterable<string,array{string}> */
    public static function forbiddenDraftTransitions(): iterable
    {
        yield 'active to draft' => ['active'];
        yield 'archived to draft' => ['archived'];
    }

    public function testPatchCannotRenamePlanKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator->validatePatch(
            ['plan_key' => 'business'],
            array_merge($this->validPayload(), ['plan_key' => 'pro'])
        );
    }

    public function testCreateAcceptsDescriptionOf255Characters(): void
    {
        $validated = $this->validator->validateCreate(array_merge($this->validPayload(), [
            'description' => str_repeat('a', 255),
        ]));

        self::assertSame(str_repeat('a', 255), $validated['description']);
    }

    public function testCreateRejectsDescriptionLongerThan255Characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator->validateCreate(array_merge($this->validPayload(), [
            'description' => str_repeat('a', 256),
        ]));
    }

    public function testPatchRejectsDescriptionLongerThan255Characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator->validatePatch(
            ['description' => str_repeat('a', 256)],
            $this->validPayload()
        );
    }

    public function testPatchAcceptsDescriptionOf255Characters(): void
    {
        $validated = $this->validator->validatePatch(
            ['description' => str_repeat('a', 255)],
            $this->validPayload()
        );

        self::assertSame(str_repeat('a', 255), $validated['description']);
    }

    public function testCreateNormalizesDefaults(): void
    {
        $payload = $this->validPayload();
        unset($payload['description'], $payload['payvia_priced_plan_uuid'], $payload['sort_order']);
        $payload['status'] = 'DRAFT';

        $validated = $this->validator->validateCreate($payload);

        self::assertNull($validated['description']);
        self::assertNull($validated['payvia_priced_plan_uuid']);
        self::assertSame(0, $validated['sort_order']);
        self::assertSame('draft', $validated['status']);
    }

    public function testValidateImportConfigPlanBuildsCreatePayload(): void
    {
        $validated = $this->validator->validateImportConfigPlan('starter', [
            'name' => 'Starter',
            'entitlements' => ['projects.limit' => 3],
            'payvia_priced_plan_uuid' => 'price1234567',
        ], 'active');

        self::assertSame('starter', $validated['plan_key']);
        self::assertSame('Starter', $validated['display_name']);
        self::assertSame(['projects.limit' => 3], $validated['entitlements']);
        self::assertSame('price1234567', $validated['payvia_priced_plan_uuid']);
        self::assertSame('active', $validated['status']);
    }
}
