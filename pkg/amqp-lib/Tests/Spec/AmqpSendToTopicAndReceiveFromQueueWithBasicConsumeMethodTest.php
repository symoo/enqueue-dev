<?php

namespace Enqueue\AmqpLib\Tests\Spec;

use Enqueue\AmqpLib\AmqpConnectionFactory;
use Enqueue\AmqpLib\AmqpContext;
use Interop\Queue\PsrContext;
use Interop\Queue\Spec\SendToTopicAndReceiveFromQueueSpec;

/**
 * @group functional
 */
class AmqpSendToTopicAndReceiveFromQueueWithBasicConsumeMethodTest extends SendToTopicAndReceiveFromQueueSpec
{
    /**
     * {@inheritdoc}
     */
    protected function createContext()
    {
        $factory = new AmqpConnectionFactory(getenv('AMQP_DSN').'?receive_method=basic_consume');

        return $factory->createContext();
    }

    /**
     * {@inheritdoc}
     *
     * @param AmqpContext $context
     */
    protected function createQueue(PsrContext $context, $queueName)
    {
        $queueName .= '_basic_consume';

        $queue = $context->createQueue($queueName);
        $context->declareQueue($queue);
        $context->purge($queue);

        $context->bind($context->createTopic($queueName), $queue);

        return $queue;
    }

    /**
     * {@inheritdoc}
     *
     * @param AmqpContext $context
     */
    protected function createTopic(PsrContext $context, $topicName)
    {
        $topicName .= '_basic_consume';

        $topic = $context->createTopic($topicName);
        $topic->setType('fanout');
        $topic->setDurable(true);
        $context->declareTopic($topic);

        return $topic;
    }
}