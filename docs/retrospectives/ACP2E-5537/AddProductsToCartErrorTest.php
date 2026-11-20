<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Quote\Test\Unit\Model\Cart;

use Magento\Quote\Model\Cart\AddProductsToCartError;
use PHPUnit\Framework\TestCase;

class AddProductsToCartErrorTest extends TestCase
{
    /**
     * @var AddProductsToCartError
     */
    private $model;

    protected function setUp(): void
    {
        $this->model = new AddProductsToCartError();
    }

    /**
     * Cart validation messages must resolve to a specific, actionable error code.
     *
     * Data sets are asserted in a loop rather than through a data provider so the test runs
     * unchanged on the PHPUnit version pinned by this branch and on newer major versions,
     * where annotation-based providers are no longer supported.
     */
    public function testCreateResolvesCodeForValidationMessages(): void
    {
        $messageCodes = [
            'max sale qty exceeded' => [
                'The requested qty exceeds the maximum qty allowed in shopping cart',
                'INSUFFICIENT_STOCK',
            ],
            'requested qty not available (without dot)' => [
                'The requested qty is not available',
                'INSUFFICIENT_STOCK',
            ],
            'requested qty not available (with dot)' => [
                'The requested qty. is not available',
                'INSUFFICIENT_STOCK',
            ],
            'min sale qty' => [
                'The fewest you may purchase is 2.',
                'INSUFFICIENT_STOCK',
            ],
            'max sale qty' => [
                'The most you may purchase is 5.',
                'INSUFFICIENT_STOCK',
            ],
            'not enough items for sale' => [
                'Not enough items for sale',
                'INSUFFICIENT_STOCK',
            ],
            'non positive quantity' => [
                'The product quantity should be greater than 0',
                'INVALID_PARAMETER_VALUE',
            ],
            'decimal quantity' => [
                'You cannot use decimal quantity for this product.',
                'INVALID_PARAMETER_VALUE',
            ],
            'qty increments' => [
                'You can buy Simple Product 1 only in quantities of 3 at a time.',
                'INVALID_PARAMETER_VALUE',
            ],
            'wrong option data format' => [
                'Wrong format of the entered option data',
                'INVALID_PARAMETER_VALUE',
            ],
            'required options missing' => [
                'You need to choose options for your item.',
                'REQUIRED_PARAMETER_MISSING',
            ],
            'product not found' => [
                'Could not find a product with SKU "simple"',
                'PRODUCT_NOT_FOUND',
            ],
            'product not available' => [
                'Product that you are trying to add is not available.',
                'NOT_SALABLE',
            ],
            'out of stock' => [
                'This product is out of stock',
                'INSUFFICIENT_STOCK',
            ],
        ];

        foreach ($messageCodes as $name => $data) {
            [$message, $expectedCode] = $data;
            $this->assertSame(
                $expectedCode,
                $this->model->create($message)->getCode(),
                sprintf('Unexpected error code resolved for the "%s" message.', $name)
            );
        }
    }

    /**
     * An unmapped message must still fall back to the UNDEFINED code.
     */
    public function testCreateFallsBackToUndefinedCode(): void
    {
        $error = $this->model->create('Some message with no mapping');

        $this->assertSame('UNDEFINED', $error->getCode());
    }

    /**
     * The original message and the cart item position are passed through untouched.
     */
    public function testCreateKeepsMessageAndCartItemPosition(): void
    {
        $message = 'The requested qty exceeds the maximum qty allowed in shopping cart';
        $error = $this->model->create($message, 3);

        $this->assertSame($message, $error->getMessage());
        $this->assertSame(3, $error->getCartItemPosition());
    }
}
