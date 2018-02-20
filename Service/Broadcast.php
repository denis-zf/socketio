<?php

namespace SfCod\SocketIoBundle\Service;

use Doctrine\DBAL\Connection;
use Exception;
use HTMLPurifier;
use Psr\Log\LoggerInterface;
use SfCod\SocketIoBundle\Events\EventInterface;
use SfCod\SocketIoBundle\events\EventPolicyInterface;
use SfCod\SocketIoBundle\Events\EventPublisherInterface;
use SfCod\SocketIoBundle\events\EventRoomInterface;
use SfCod\SocketIoBundle\Events\EventSubscriberInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class Broadcast
 *
 * @package SfCod\SocketIoBundle
 */
class Broadcast
{
    use ContainerAwareTrait;

    /**
     * @var RedisDriver
     */
    protected $redis;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var EventManager
     */
    protected $manager;

    /**
     * @var Process
     */
    protected $process;

    /**
     * @var array
     */
    protected static $channels = [];

    /**
     * Broadcast constructor.
     *
     * @param ContainerInterface $container
     * @param RedisDriver $redis
     * @param EventManager $manager
     * @param LoggerInterface $logger
     */
    public function __construct(ContainerInterface $container, RedisDriver $redis, EventManager $manager, LoggerInterface $logger, Process $process)
    {
        $this->redis = $redis;
        $this->logger = $logger;
        $this->manager = $manager;
        $this->process = $process;

        $this->setContainer($container);
    }

    /**
     * Subscribe to event from client
     *
     * @param string $event
     * @param array $data
     *
     * @return \Symfony\Component\Process\Process
     *
     * @throws Exception
     */
    public function on(string $event, array $data)
    {
        // Clear data
        array_walk_recursive($data, function (&$item, $key) {
            $item = (new HtmlPurifier())->purify($item);
        });

        $this->logger->info(json_encode(['type' => 'on', 'name' => $event, 'data' => $data]));

//        $eventHandlerClass = $this->getEventHandlerClass($event);

        return $this->process->run($event, $data);
    }

    /**
     * Run process
     *
     * @param string $handler
     * @param array $data
     */
    public function process(string $handler, array $data)
    {
        try {
            /** @var EventInterface|EventSubscriberInterface|EventPolicyInterface $eventHandler */
            $eventHandler = $this->container->get(sprintf('socketio.%s', $handler));
            $eventHandler->setPayload($data);
            if (false === $eventHandler instanceof EventInterface) {
                throw new Exception('Event should implement EventInterface');
            }

            $eventHandler->setContainer($this->container);

            if (false === $eventHandler instanceof EventSubscriberInterface) {
                throw new Exception('Event should implement EventSubscriberInterface');
            }

            if (true === $eventHandler instanceof EventPolicyInterface && false === $eventHandler->can($data)) {
                return;
            }

            /** @var Connection $connection */
            $connection = $this->container->get('doctrine')->getConnection();
            $connection->close();
            $connection->connect();

            $eventHandler->handle();
        } catch (Exception $e) {
            $this->logger->error($e);
        }
    }

    /**
     * Emit event to client
     *
     * @param string $event
     * @param array $data
     *
     * @throws Exception
     */
    public function emit(string $event, array $data)
    {
        $this->logger->info(json_encode(['type' => 'emit', 'name' => $event, 'data' => $data]));

        try {
//            $eventHandlerClass = $this->getEventHandlerClass($event);

            /** @var EventInterface|EventPublisherInterface|EventRoomInterface $eventHandler */
//            $eventHandler = new $eventHandlerClass($data);
            $eventHandler = $this->container->get(sprintf('socketio.%s', $event));
            $eventHandler->setPayload($data);

            $eventHandlerClass = get_class($eventHandler);

            if (false === $eventHandler instanceof EventInterface) {
                throw new Exception('Event should implement EventInterface');
            }

            $eventHandler->setContainer($this->container);

            if (false === $eventHandler instanceof EventPublisherInterface) {
                throw new Exception('Event should implement EventPublisherInterface');
            }

            $data = $eventHandler->fire();

            if (true === $eventHandler instanceof EventRoomInterface) {
                $data['room'] = $eventHandler->room();
            }

            foreach ($eventHandlerClass::broadcastOn() as $channel) {
                $this->publish($this->channelName($channel), [
                    'name' => $eventHandlerClass::name(),
                    'data' => $data,
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error($e);
        }
    }

    /**
     * Redis channels names
     *
     * @return array
     */
    public function channels(): array
    {
        if (empty(self::$channels)) {
            foreach ($this->manager->getList() as $eventHandlerClass) {
                self::$channels = array_merge(self::$channels, $eventHandlerClass::broadcastOn());
            }
            self::$channels = array_unique(self::$channels);
            self::$channels = array_map(function ($channel) {
                return $this->channelName($channel);
            }, self::$channels);
        }

        return self::$channels;
    }

    /**
     * Prepare channel name
     *
     * @param string $name
     *
     * @return string
     */
    protected function channelName(string $name): string
    {
        return $name . getenv('SOCKET_IO_NSP');
    }

    /**
     * Publish data to redis channel
     *
     * @param string $channel
     * @param array $data
     */
    protected function publish(string $channel, array $data)
    {
        $this->redis->getClient(true)->publish($channel, json_encode($data));
    }

//    /**
//     * Get event handler service
//     *
//     * @param string $event
//     *
//     * @return string
//     *
//     * @throws Exception
//     */
//    protected function getEventHandlerClass(string $event): string
//    {
//        $eventHandlerClass = $this->manager->getList()[$event] ?? null;
//
//        if (null === $eventHandlerClass) {
//            throw new Exception("Can not find $event");
//        }
//
//        return $eventHandlerClass;
//    }
}