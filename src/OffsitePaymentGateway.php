<?php

/*
 * This file is part of the Active Collab Dummy Payment Gateway project.
 *
 * (c) A51 doo <info@activecollab.com>. All rights reserved.
 */

namespace ActiveCollab\DummyPaymentGateway;

use ActiveCollab\DateValue\DateTimeValue;
use ActiveCollab\DateValue\DateTimeValueInterface;
use ActiveCollab\Payments\Customer\CustomerInterface;
use ActiveCollab\Payments\Dispatcher\DispatcherInterface;
use ActiveCollab\Payments\Gateway\GatewayInterface;
use ActiveCollab\Payments\Gateway\GatewayInterface\Implementation as GatewayInterfaceImplementation;
use ActiveCollab\Payments\Order\OrderInterface;
use ActiveCollab\Payments\Order\Refund\RefundInterface;
use ActiveCollab\Payments\OrderItem\OrderItemInterface;
use ActiveCollab\Payments\PaymentMethod\PaymentMethodInterface;
use ActiveCollab\Payments\Subscription\Cancelation\Cancelation;
use ActiveCollab\Payments\Subscription\Change\Change;
use ActiveCollab\Payments\Subscription\FailedPayment\FailedPayment;
use ActiveCollab\Payments\Subscription\Rebill\Rebill;
use ActiveCollab\Payments\Subscription\SubscriptionInterface;
use ActiveCollab\Payments\Test\Fixtures\Refund;
use ActiveCollab\Payments\Test\Fixtures\Subscription;
use BadMethodCallException;
use InvalidArgumentException;

/**
 * @package ActiveCollab\DummyPaymentGateway
 */
class OffsitePaymentGateway implements GatewayInterface
{
    use GatewayInterfaceImplementation;

    /**
     * @var OrderInterface[]
     */
    private $orders = [];

    /**
     * @var RefundInterface[]
     */
    private $refunds = [];

    /**
     * @var SubscriptionInterface[]
     */
    private $subscriptions = [];

    /**
     * @param \ActiveCollab\Payments\Dispatcher\DispatcherInterface $dispatcher
     */
    public function __construct(DispatcherInterface &$dispatcher)
    {
        $this->setDispatcher($dispatcher);
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'dopg'; // Dummy Offsite Payment Gateway
    }

    /**
     * {@inheritdoc}
     */
    public function getOurReference(): string
    {
        return $this->getIdentifier();
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultPaymentMethod(string $customer_id)
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function listPaymentMethods(string $customer_id): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function addPaymentMethod(CustomerInterface $customer, bool $set_as_default, ...$arguments): PaymentMethodInterface
    {
        throw new BadMethodCallException(__METHOD__ . ' is not implemented yet');
    }

    /**
     * {@inheritdoc}
     */
    public function getOrderByReference(string $order_reference): OrderInterface
    {
        if (isset($this->orders[$order_reference])) {
            return $this->orders[$order_reference];
        }

        throw new InvalidArgumentException("Order #{$order_reference} not found");
    }

    /**
     * {@inheritdoc}
     */
    public function getRefundByReference(string $refund_reference): RefundInterface
    {
        if (isset($this->refunds[$refund_reference])) {
            return $this->refunds[$refund_reference];
        }

        throw new InvalidArgumentException("Refund #{$refund_reference} not found");
    }

    /**
     * {@inheritdoc}
     */
    public function createSubscription(CustomerInterface $customer, PaymentMethodInterface $payment_method, $product_name, string $period, ...$arguments): SubscriptionInterface
    {
        return new Subscription($customer, '2016-02-03', new DateTimeValue(), $period, 'USD', 200, []);
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriptionByReference(string $subscription_reference): SubscriptionInterface
    {
        if (isset($this->subscriptions[$subscription_reference])) {
            return $this->subscriptions[$subscription_reference];
        }

        throw new InvalidArgumentException("Subscription #{$subscription_reference} not found");
    }

    /**
     * {@inheritdoc}
     */
    public function getProductIdByNameAndBillingPeriod(string $product_name, string $period = SubscriptionInterface::MONTHLY): string
    {
        return '';
    }

    /**
     * Trigger product order completed.
     *
     * @param OrderInterface $order
     */
    public function triggerOrderCompleted(OrderInterface $order)
    {
        $this->orders[$order->getReference()] = $order;

        $this->getDispatcher()->triggerOrderCompleted($this, $order);
    }

    /**
     * Trigger order has been fully refunded.
     *
     * @param OrderInterface              $order
     * @param DateTimeValueInterface|null $timestamp
     */
    public function triggerOrderRefunded(OrderInterface $order, DateTimeValueInterface $timestamp = null)
    {
        $this->orders[$order->getReference()] = $order;

        if (empty($timestamp)) {
            $timestamp = new DateTimeValue();
        }

        $refund = new Refund($order->getReference().'-X', $order->getReference(), $timestamp, $order->getTotal());
        $refund->setGateway($this);

        $this->refunds[$refund->getReference()] = $refund;

        $this->getDispatcher()->triggerOrderRefunded($this, $order, $refund);
    }

    /**
     * Trigger order has been partially refunded.
     *
     * @param OrderInterface              $order
     * @param OrderItemInterface[]        $items
     * @param DateTimeValueInterface|null $timestamp
     */
    public function triggerOrderPartiallyRefunded(OrderInterface $order, array $items = null, DateTimeValueInterface $timestamp = null)
    {
        $this->orders[$order->getReference()] = $order;

        if (empty($timestamp)) {
            $timestamp = new DateTimeValue();
        }

        $refund = new Refund($order->getReference().'-X', $order->getReference(), $timestamp, 200);
        $refund->setGateway($this);

        if (!empty($items)) {
            $refund->setItems($items);
        }

        $this->refunds[$refund->getReference()] = $refund;

        $this->getDispatcher()->triggerOrderPartiallyRefunded($this, $order, $refund);
    }

    /**
     * Trigger subscription activated (created) event.
     *
     * @param SubscriptionInterface $subscription
     */
    public function triggerSubscriptionActivated(SubscriptionInterface $subscription)
    {
        $subscription->setGateway($this);
        $this->registerSubscription($subscription);

        $this->getDispatcher()->triggerSubscriptionActivated($this, $subscription);
    }

    /**
     * Trigger subscription failed payment event.
     *
     * @param SubscriptionInterface       $subscription
     * @param DateTimeValueInterface|null $timestamp
     * @param DateTimeValueInterface|null $next_billing_timestamp
     */
    public function triggerSubscriptionRebilled(SubscriptionInterface $subscription, DateTimeValueInterface $timestamp = null, DateTimeValueInterface $next_billing_timestamp = null)
    {
        $subscription->setGateway($this);
        $this->registerSubscription($subscription);

        if (empty($timestamp)) {
            $timestamp = new DateTimeValue();
        }

        if (empty($next_billing_timestamp)) {
            $next_billing_timestamp = $subscription->calculateNextBillingTimestamp($timestamp);
        }

        $rebill = new Rebill($subscription->getReference(), $timestamp, $next_billing_timestamp);
        $rebill->setGateway($this);

        $this->getDispatcher()->triggerSubscriptionRebill($this, $subscription, $rebill);
    }

    /**
     * Trigger subscription failed payment event.
     *
     * @param SubscriptionInterface       $subscription
     * @param DateTimeValueInterface|null $timestamp
     */
    public function triggerSubscriptionChanged(SubscriptionInterface $subscription, DateTimeValueInterface $timestamp = null)
    {
        $subscription->setGateway($this);
        $this->registerSubscription($subscription);

        if (empty($timestamp)) {
            $timestamp = new DateTimeValue();
        }

        $change = new Change($subscription->getReference(), $timestamp);
        $change->setGateway($this);

        $this->getDispatcher()->triggerSubscriptionChanged($this, $subscription, $change);
    }

    /**
     * Trigger subscription deactivated (canceled) event.
     *
     * @param SubscriptionInterface       $subscription
     * @param DateTimeValueInterface|null $timestamp
     */
    public function triggerSubscriptionDeactivated(SubscriptionInterface $subscription, DateTimeValueInterface $timestamp = null)
    {
        $subscription->setGateway($this);
        $this->registerSubscription($subscription);

        if (empty($timestamp)) {
            $timestamp = new DateTimeValue();
        }

        $cancelation = new Cancelation($subscription->getReference(), $timestamp);
        $cancelation->setGateway($this);

        $this->getDispatcher()->triggerSubscriptionDeactivated($this, $subscription, $cancelation);
    }

    /**
     * Trigger subscription failed payment event.
     *
     * @param SubscriptionInterface       $subscription
     * @param DateTimeValueInterface|null $timestamp
     */
    public function triggerSubscriptionFailedPayment(SubscriptionInterface $subscription, DateTimeValueInterface $timestamp = null)
    {
        $subscription->setGateway($this);
        $this->registerSubscription($subscription);

        if (empty($timestamp)) {
            $timestamp = new DateTimeValue();
        }

        $failed_payment = new FailedPayment($subscription->getReference(), $timestamp);
        $failed_payment->setGateway($this);

        $this->getDispatcher()->triggerSubscriptionPaymentFailed($this, $subscription, $failed_payment);
    }

    /**
     * Add subscription to the list of available subscriptions.
     *
     * @param SubscriptionInterface $subscription
     */
    public function registerSubscription(SubscriptionInterface $subscription)
    {
        $this->subscriptions[$subscription->getReference()] = $subscription;
    }

    /**
     * Update an existing interface for the given customer, that ordered a given product using a given payment method.
     *
     * @param SubscriptionInterface  $subscription
     * @param CustomerInterface      $customer
     * @param PaymentMethodInterface $payment_method
     * @param string                 $product_name
     * @param string                 $period
     * @param  array                  ...$arguments
     * @return SubscriptionInterface
     */
    public function updateSubscription(SubscriptionInterface $subscription, CustomerInterface $customer, PaymentMethodInterface $payment_method, $product_name, string $period, ...$arguments): SubscriptionInterface
    {
        return $subscription;
    }
}
