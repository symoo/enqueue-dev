<?php

namespace Enqueue\AmqpBunny\Tests\Spec;

use Enqueue\AmqpLib\AmqpConnectionFactory;
use Enqueue\AmqpTools\RabbitMqDelayPluginDelayStrategy;
use Interop\Queue\PsrContext;
use Interop\Queue\Spec\SendAndReceiveDelayedMessageFromQueueSpec;

/**
 * @group functional
 */
class AmqpSendAndReceiveDelayedMessageWithDelayPluginStrategyTest extends SendAndReceiveDelayedMessageFromQueueSpec
{
    /**
     * {@inheritdoc}
     */
    protected function createContext()
    {
        $factory = new AmqpConnectionFactory(getenv('AMQP_DSN'));
        $factory->setDelayStrategy(new RabbitMqDelayPluginDelayStrategy());

        return $factory->createContext();
    }

    /**
     * {@inheritdoc}
     */
    protected function createQueue(PsrContext $context, $queueName)
    {
        $queue = parent::createQueue($context, $queueName);

        $context->declareQueue($queue);

        return $queue;
    }
}
