<?php

namespace Enqueue\AmqpLib;

use Enqueue\AmqpTools\DelayStrategyAware;
use Enqueue\AmqpTools\DelayStrategyAwareTrait;
use Interop\Amqp\AmqpBind as InteropAmqpBind;
use Interop\Amqp\AmqpContext as InteropAmqpContext;
use Interop\Amqp\AmqpMessage as InteropAmqpMessage;
use Interop\Amqp\AmqpQueue as InteropAmqpQueue;
use Interop\Amqp\AmqpTopic as InteropAmqpTopic;
use Interop\Amqp\Impl\AmqpBind;
use Interop\Amqp\Impl\AmqpMessage;
use Interop\Amqp\Impl\AmqpQueue;
use Interop\Amqp\Impl\AmqpTopic;
use Interop\Queue\Exception;
use Interop\Queue\InvalidDestinationException;
use Interop\Queue\PsrDestination;
use Interop\Queue\PsrTopic;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Wire\AMQPTable;

class AmqpContext implements InteropAmqpContext, DelayStrategyAware
{
    use DelayStrategyAwareTrait;

    /**
     * @var AbstractConnection
     */
    private $connection;

    /**
     * @var AMQPChannel
     */
    private $channel;

    /**
     * @var string
     */
    private $config;

    /**
     * @var Buffer
     */
    private $buffer;

    /**
     * @param AbstractConnection $connection
     * @param array              $config
     */
    public function __construct(AbstractConnection $connection, $config = [])
    {
        $this->config = array_replace([
            'receive_method' => 'basic_get',
            'qos_prefetch_size' => 0,
            'qos_prefetch_count' => 1,
            'qos_global' => false,
        ], $config);

        $this->connection = $connection;
        $this->buffer = new Buffer();
    }

    /**
     * @param string|null $body
     * @param array       $properties
     * @param array       $headers
     *
     * @return InteropAmqpMessage
     */
    public function createMessage($body = '', array $properties = [], array $headers = [])
    {
        return new AmqpMessage($body, $properties, $headers);
    }

    /**
     * @param string $name
     *
     * @return InteropAmqpQueue
     */
    public function createQueue($name)
    {
        return new AmqpQueue($name);
    }

    /**
     * @param string $name
     *
     * @return InteropAmqpTopic
     */
    public function createTopic($name)
    {
        return new AmqpTopic($name);
    }

    /**
     * @param PsrDestination $destination
     *
     * @return AmqpConsumer
     */
    public function createConsumer(PsrDestination $destination)
    {
        $destination instanceof PsrTopic
            ? InvalidDestinationException::assertDestinationInstanceOf($destination, InteropAmqpTopic::class)
            : InvalidDestinationException::assertDestinationInstanceOf($destination, InteropAmqpQueue::class)
        ;

        if ($destination instanceof AmqpTopic) {
            $queue = $this->createTemporaryQueue();
            $this->bind(new AmqpBind($destination, $queue, $queue->getQueueName()));

            return new AmqpConsumer($this->getChannel(), $queue, $this->buffer, $this->config['receive_method']);
        }

        return new AmqpConsumer($this->getChannel(), $destination, $this->buffer, $this->config['receive_method']);
    }

    /**
     * @return AmqpProducer
     */
    public function createProducer()
    {
        $producer = new AmqpProducer($this->getChannel(), $this);
        $producer->setDelayStrategy($this->delayStrategy);

        return $producer;
    }

    /**
     * @return InteropAmqpQueue
     */
    public function createTemporaryQueue()
    {
        list($name) = $this->getChannel()->queue_declare('', false, false, true, false);

        $queue = $this->createQueue($name);
        $queue->addFlag(InteropAmqpQueue::FLAG_EXCLUSIVE);

        return $queue;
    }

    /**
     * {@inheritdoc}
     */
    public function declareTopic(InteropAmqpTopic $topic)
    {
        $this->getChannel()->exchange_declare(
            $topic->getTopicName(),
            $topic->getType(),
            (bool) ($topic->getFlags() & InteropAmqpTopic::FLAG_PASSIVE),
            (bool) ($topic->getFlags() & InteropAmqpTopic::FLAG_DURABLE),
            (bool) ($topic->getFlags() & InteropAmqpTopic::FLAG_AUTODELETE),
            (bool) ($topic->getFlags() & InteropAmqpTopic::FLAG_INTERNAL),
            (bool) ($topic->getFlags() & InteropAmqpTopic::FLAG_NOWAIT),
            $topic->getArguments() ? new AMQPTable($topic->getArguments()) : null
        );
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTopic(InteropAmqpTopic $topic)
    {
        $this->getChannel()->exchange_delete(
            $topic->getTopicName(),
            (bool) ($topic->getFlags() & InteropAmqpTopic::FLAG_IFUNUSED),
            (bool) ($topic->getFlags() & InteropAmqpTopic::FLAG_NOWAIT)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function declareQueue(InteropAmqpQueue $queue)
    {
        list(, $messageCount) = $this->getChannel()->queue_declare(
            $queue->getQueueName(),
            (bool) ($queue->getFlags() & InteropAmqpQueue::FLAG_PASSIVE),
            (bool) ($queue->getFlags() & InteropAmqpQueue::FLAG_DURABLE),
            (bool) ($queue->getFlags() & InteropAmqpQueue::FLAG_EXCLUSIVE),
            (bool) ($queue->getFlags() & InteropAmqpQueue::FLAG_AUTODELETE),
            (bool) ($queue->getFlags() & InteropAmqpQueue::FLAG_NOWAIT),
            $queue->getArguments() ? new AMQPTable($queue->getArguments()) : null
        );

        return $messageCount;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteQueue(InteropAmqpQueue $queue)
    {
        $this->getChannel()->queue_delete(
            $queue->getQueueName(),
            (bool) ($queue->getFlags() & InteropAmqpQueue::FLAG_IFUNUSED),
            (bool) ($queue->getFlags() & InteropAmqpQueue::FLAG_IFEMPTY),
            (bool) ($queue->getFlags() & InteropAmqpQueue::FLAG_NOWAIT)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function purgeQueue(InteropAmqpQueue $queue)
    {
        $this->getChannel()->queue_purge(
            $queue->getQueueName(),
            (bool) ($queue->getFlags() & InteropAmqpQueue::FLAG_NOWAIT)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function bind(InteropAmqpBind $bind)
    {
        if ($bind->getSource() instanceof InteropAmqpQueue && $bind->getTarget() instanceof InteropAmqpQueue) {
            throw new Exception('Is not possible to bind queue to queue. It is possible to bind topic to queue or topic to topic');
        }

        // bind exchange to exchange
        if ($bind->getSource() instanceof InteropAmqpTopic && $bind->getTarget() instanceof InteropAmqpTopic) {
            $this->getChannel()->exchange_bind(
                $bind->getTarget()->getTopicName(),
                $bind->getSource()->getTopicName(),
                $bind->getRoutingKey(),
                (bool) ($bind->getFlags() & InteropAmqpBind::FLAG_NOWAIT),
                $bind->getArguments()
            );
            // bind queue to exchange
        } elseif ($bind->getSource() instanceof InteropAmqpQueue) {
            $this->getChannel()->queue_bind(
                $bind->getSource()->getQueueName(),
                $bind->getTarget()->getTopicName(),
                $bind->getRoutingKey(),
                (bool) ($bind->getFlags() & InteropAmqpBind::FLAG_NOWAIT),
                $bind->getArguments()
            );
            // bind exchange to queue
        } else {
            $this->getChannel()->queue_bind(
                $bind->getTarget()->getQueueName(),
                $bind->getSource()->getTopicName(),
                $bind->getRoutingKey(),
                (bool) ($bind->getFlags() & InteropAmqpBind::FLAG_NOWAIT),
                $bind->getArguments()
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unbind(InteropAmqpBind $bind)
    {
        if ($bind->getSource() instanceof InteropAmqpQueue && $bind->getTarget() instanceof InteropAmqpQueue) {
            throw new Exception('Is not possible to bind queue to queue. It is possible to bind topic to queue or topic to topic');
        }

        // bind exchange to exchange
        if ($bind->getSource() instanceof InteropAmqpTopic && $bind->getTarget() instanceof InteropAmqpTopic) {
            $this->getChannel()->exchange_unbind(
                $bind->getTarget()->getTopicName(),
                $bind->getSource()->getTopicName(),
                $bind->getRoutingKey(),
                (bool) ($bind->getFlags() & InteropAmqpBind::FLAG_NOWAIT),
                $bind->getArguments()
            );
            // bind queue to exchange
        } elseif ($bind->getSource() instanceof InteropAmqpQueue) {
            $this->getChannel()->queue_unbind(
                $bind->getSource()->getQueueName(),
                $bind->getTarget()->getTopicName(),
                $bind->getRoutingKey(),
                $bind->getArguments()
            );
            // bind exchange to queue
        } else {
            $this->getChannel()->queue_unbind(
                $bind->getTarget()->getQueueName(),
                $bind->getSource()->getTopicName(),
                $bind->getRoutingKey(),
                $bind->getArguments()
            );
        }
    }

    public function close()
    {
        if ($this->channel) {
            $this->channel->close();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setQos($prefetchSize, $prefetchCount, $global)
    {
        $this->getChannel()->basic_qos($prefetchSize, $prefetchCount, $global);
    }

    /**
     * @return AMQPChannel
     */
    private function getChannel()
    {
        if (null === $this->channel) {
            $this->channel = $this->connection->channel();
            $this->channel->basic_qos(
                $this->config['qos_prefetch_size'],
                $this->config['qos_prefetch_count'],
                $this->config['qos_global']
            );
        }

        return $this->channel;
    }
}
